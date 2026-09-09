<?php

namespace App\Support;

use App\Models\TenantMembership;
use App\Support\Modules\Contracts\KeputusanWorkflowDiambil;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WorkflowRuntime
{
    /** @param array<string, mixed> $data */
    public function submit(string $tenantId, object $type, object $version, string $idempotencyKey, array $data): object
    {
        return DB::transaction(function () use ($tenantId, $type, $version, $idempotencyKey, $data): object {
            $id = (string) Str::ulid();
            DB::table('workflow_instances')->insert([
                'id' => $id,
                'tenant_id' => $tenantId,
                'workflow_type_id' => $type->id,
                'initiator_membership_id' => $data['initiator_membership_id'] ?? null,
                'correlation_id' => $data['correlation_id'] ?? $id,
                'configuration_version_id' => $version->id,
                'source_document_type' => $data['source_document_type'],
                'source_document_id' => $data['source_document_id'],
                'idempotency_key' => $idempotencyKey,
                'decision_context' => json_encode($data['decision_context'], JSON_THROW_ON_ERROR),
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $elements = DB::table('workflow_elements')->where('version_id', $version->id)->get()->keyBy('id');
            $transitions = DB::table('workflow_transitions')->where('version_id', $version->id)->get()->groupBy('from_element_id');
            $start = $elements->firstWhere('kind', 'start');
            abort_unless($start, 422, 'Workflow aktif belum memiliki langkah Mulai.');
            $this->history($tenantId, $id, 'submitted', ['element' => $start->key]);
            $this->advance($tenantId, $id, $elements, $transitions, $start, $data['decision_context']);
            $this->completeWhenDone($tenantId, DB::table('workflow_instances')->where('id', $id)->first(), null);

            return DB::table('workflow_instances')->where('id', $id)->first();
        });
    }

    public function decide(TenantMembership $actor, string $workItemId, string $decision, ?string $comment): object
    {
        return DB::transaction(function () use ($actor, $workItemId, $decision, $comment): object {
            $workItem = DB::table('workflow_work_items')->where('id', $workItemId)->where('tenant_id', $actor->tenant_id)
                ->where('assigned_membership_id', $actor->id)->lockForUpdate()->first();
            abort_unless($workItem, 404);
            if ($workItem->status !== 'pending') {
                throw ValidationException::withMessages(['decision' => 'Tugas ini sudah ditangani.']);
            }

            $instance = DB::table('workflow_instances')->where('id', $workItem->instance_id)->where('tenant_id', $actor->tenant_id)->lockForUpdate()->first();
            abort_unless($instance && $instance->status === 'pending', 409, 'Permintaan ini sudah selesai.');
            abort_if($instance->initiator_membership_id !== null && $instance->initiator_membership_id === $actor->id, 403, 'Pengaju tidak dapat menyetujui dokumennya sendiri.');

            $element = DB::table('workflow_elements')->where('id', $workItem->element_id)->where('version_id', $instance->configuration_version_id)->first();
            abort_unless($element, 409, 'Langkah workflow tidak ditemukan.');
            $config = json_decode($element->configuration, true, 512, JSON_THROW_ON_ERROR);
            $isApproval = $element->kind === 'approval';
            $validDecisions = $isApproval ? ['approve', 'reject'] : ['complete', 'approve', 'reject'];
            abort_unless(in_array($decision, $validDecisions, true), 422, 'Keputusan ini tidak tersedia untuk langkah tersebut.');

            $status = $decision === 'reject' ? 'rejected' : ($isApproval ? 'approved' : 'completed');
            DB::table('workflow_work_items')->where('id', $workItem->id)->update(['status' => $status, 'completed_at' => now(), 'updated_at' => now()]);
            $this->history($actor->tenant_id, $instance->id, $status, array_filter(['comment' => $comment, 'element' => $element->key]), $actor->id);

            $policy = (string) ($config['completion_policy'] ?? 'single');
            $items = DB::table('workflow_work_items')->where('instance_id', $instance->id)->where('element_id', $element->id)->get();
            $decision = $this->completionDecision($config, $items);
            if ($decision === null) {
                return DB::table('workflow_instances')->where('id', $instance->id)->first();
            }
            if ($decision === 'rejected') {
                $this->finish($actor->tenant_id, $instance, 'rejected', $comment, $actor->id);

                return DB::table('workflow_instances')->where('id', $instance->id)->first();
            }
            if ($policy !== 'all') {
                DB::table('workflow_work_items')->where('instance_id', $instance->id)->where('element_id', $element->id)->where('status', 'pending')->update(['status' => 'cancelled', 'completed_at' => now(), 'updated_at' => now()]);
            }
            $this->markNodeCompleted($actor->tenant_id, $instance->id, $element->key);

            $elements = DB::table('workflow_elements')->where('version_id', $instance->configuration_version_id)->get()->keyBy('id');
            $transitions = DB::table('workflow_transitions')->where('version_id', $instance->configuration_version_id)->get()->groupBy('from_element_id');
            $context = json_decode($instance->decision_context, true, 512, JSON_THROW_ON_ERROR);
            $this->advanceEdges($actor->tenant_id, $instance->id, $elements, $transitions, $element, $context, $isApproval ? 'approve' : 'complete');
            $this->completeWhenDone($actor->tenant_id, $instance, $actor->id);

            return DB::table('workflow_instances')->where('id', $instance->id)->first();
        });
    }

    /** @param Collection<string, object> $elements @param Collection<string, Collection<int, object>> $transitions @param array<string, mixed> $context */
    private function advance(string $tenantId, string $instanceId, Collection $elements, Collection $transitions, object $element, array $context): void
    {
        if ($element->kind === 'end') {
            $this->history($tenantId, $instanceId, 'end_reached', ['element' => $element->key]);

            return;
        }
        if (in_array($element->kind, ['start', 'parallel'], true)) {
            $this->markNodeCompleted($tenantId, $instanceId, $element->key);
            $this->advanceEdges($tenantId, $instanceId, $elements, $transitions, $element, $context);

            return;
        }
        if ($element->kind === 'condition') {
            $this->markNodeCompleted($tenantId, $instanceId, $element->key);
            $result = $this->evaluate($element, $context);
            $edge = $transitions->get($element->id, collect())->first(fn (object $candidate): bool => (string) $candidate->outcome === ($result ? 'true' : 'false'));
            abort_unless($edge, 422, "Keputusan kondisi {$element->label} belum memiliki cabang yang sesuai.");
            $this->advance($tenantId, $instanceId, $elements, $transitions, $elements->get($edge->to_element_id), $context);

            return;
        }
        if (! in_array($element->kind, ['approval', 'manual_task'], true)) {
            abort(422, "Elemen {$element->label} belum dapat dijalankan.");
        }

        $config = json_decode($element->configuration, true, 512, JSON_THROW_ON_ERROR);
        $membershipIds = $this->assignees($tenantId, $config);
        if ($membershipIds->isEmpty()) {
            $instance = DB::table('workflow_instances')->where('id', $instanceId)->first();
            $this->finish($tenantId, $instance, 'rejected', 'Tidak ada penerima tugas aktif.', null);

            return;
        }
        foreach ($membershipIds as $membershipId) {
            DB::table('workflow_work_items')->insert([
                'id' => (string) Str::ulid(), 'tenant_id' => $tenantId, 'instance_id' => $instanceId,
                'element_id' => $element->id, 'assigned_membership_id' => $membershipId, 'status' => 'pending',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** @param Collection<string, object> $elements @param Collection<string, Collection<int, object>> $transitions @param array<string, mixed> $context */
    private function advanceEdges(string $tenantId, string $instanceId, Collection $elements, Collection $transitions, object $element, array $context, ?string $outcome = null): void
    {
        foreach ($transitions->get($element->id, collect()) as $edge) {
            if ($outcome !== null && $edge->outcome !== null && (string) $edge->outcome !== $outcome) {
                continue;
            }
            $next = $elements->get($edge->to_element_id);
            if (! $next) {
                continue;
            }
            if ($next->kind !== 'end' && $this->hasMultiplePredecessors($next->id, $transitions) && ! $this->predecessorsCompleted($instanceId, $next, $elements, $transitions)) {
                continue;
            }
            $this->advance($tenantId, $instanceId, $elements, $transitions, $next, $context);
        }
    }

    /** @param Collection<string, Collection<int, object>> $transitions */
    private function hasMultiplePredecessors(string $elementId, Collection $transitions): bool
    {
        return $transitions->flatten(1)->where('to_element_id', $elementId)->count() > 1;
    }

    /** @param Collection<string, object> $elements @param Collection<string, Collection<int, object>> $transitions */
    private function predecessorsCompleted(string $instanceId, object $target, Collection $elements, Collection $transitions): bool
    {
        $predecessorIds = $transitions->flatten(1)->where('to_element_id', $target->id)->pluck('from_element_id')->unique();
        $completed = DB::table('workflow_history')->where('instance_id', $instanceId)->where('event_type', 'node_completed')->get()->pluck('details')->map(fn (string $details): ?string => json_decode($details, true)['element'] ?? null)->filter();

        return $predecessorIds->every(fn (string $id): bool => $completed->contains($elements->get($id)?->key));
    }

    /** @param array<string, mixed> $context */
    private function evaluate(object $element, array $context): bool
    {
        $config = json_decode($element->configuration, true, 512, JSON_THROW_ON_ERROR);
        $field = $config['field'] ?? null;
        abort_unless(is_string($field) && $field !== '', 422, "Field kondisi pada {$element->label} belum dipilih.");
        $actual = data_get($context, $field);
        $operator = (string) ($config['operator'] ?? 'equals');
        $expected = $config['value'] ?? null;

        return match ($operator) {
            'equals' => $actual == $expected,
            'not_equals' => $actual != $expected,
            'greater_than' => $actual > $expected,
            'greater_or_equal' => $actual >= $expected,
            'less_than' => $actual < $expected,
            'less_or_equal' => $actual <= $expected,
            'contains' => is_string($actual) && str_contains($actual, (string) $expected),
            'is_true' => (bool) $actual === true,
            'is_false' => (bool) $actual === false,
            default => throw ValidationException::withMessages(["node.{$element->key}" => 'Operator kondisi tidak dikenali.']),
        };
    }

    private function completeWhenDone(string $tenantId, ?object $instance, ?string $actorMembershipId): void
    {
        if (! $instance || $instance->status !== 'pending') {
            return;
        }
        $pending = DB::table('workflow_work_items')->where('instance_id', $instance->id)->where('status', 'pending')->exists();
        $endReached = DB::table('workflow_history')->where('instance_id', $instance->id)->where('event_type', 'end_reached')->exists();
        if (! $pending && $endReached) {
            $this->finish($tenantId, $instance, 'approved', null, $actorMembershipId);
        }
    }

    private function finish(string $tenantId, object $instance, string $status, ?string $comment, ?string $actorMembershipId): void
    {
        if ($instance->status !== 'pending') {
            return;
        }
        DB::table('workflow_work_items')->where('instance_id', $instance->id)->where('status', 'pending')->update(['status' => 'cancelled', 'completed_at' => now(), 'updated_at' => now()]);
        DB::table('workflow_instances')->where('id', $instance->id)->update(['status' => $status, 'updated_at' => now()]);
        $this->history($tenantId, $instance->id, 'decision_'.$status, array_filter(['comment' => $comment]), $actorMembershipId);
        if (DB::table('workflow_history')->where('instance_id', $instance->id)->where('event_type', 'decision_event_emitted')->exists()) {
            return;
        }
        $workflowType = DB::table('workflow_types')->where('id', $instance->workflow_type_id)->first(['code']);
        // The legal entity lives on the configuration, not the instance, so the envelope
        // reads it back through the version the instance was started against. A decision
        // is an accounting-relevant fact, so 04-api-and-integration.md requires it.
        $legalEntityId = DB::table('workflow_configuration_versions as versions')
            ->join('workflow_configurations as configurations', 'configurations.id', '=', 'versions.configuration_id')
            ->where('versions.id', $instance->configuration_version_id)
            ->value('configurations.legal_entity_id');
        // Satu isi, dua jalur. Amplop HTTP dan event in-process membawa `data` yang sama
        // persis karena keduanya dibangun dari variabel ini; menyusunnya dua kali adalah cara
        // paling pasti membuat penerima di dalam proses dan penerima di luar proses melihat
        // dua kenyataan yang berbeda.
        $isi = [
            'workflow_instance_id' => $instance->id,
            'workflow_type' => $workflowType?->code,
            'decision' => $status,
            'source_document_type' => $instance->source_document_type,
            'source_document_id' => $instance->source_document_id,
            'decision_context' => json_decode($instance->decision_context, true, 512, JSON_THROW_ON_ERROR),
        ];
        $idEvent = (string) Str::ulid();
        $idKorelasi = (string) ($instance->correlation_id ?? $instance->id);
        DB::table('outbox_events')->insert([
            'id' => $idEvent, 'tenant_id' => $tenantId, 'type' => 'core.workflow.decision.v2',
            'correlation_id' => $idKorelasi,
            'legal_entity_id' => $legalEntityId,
            'payload' => json_encode($isi, JSON_THROW_ON_ERROR),
            'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->history($tenantId, $instance->id, 'decision_event_emitted', ['status' => $status], $actorMembershipId);

        // Dipancarkan **di dalam** transaksi keputusan, dan itu disengaja: listener module
        // memperbarui dokumennya pada transaksi yang sama, sehingga instance yang `approved`
        // tidak pernah berpasangan dengan dokumen yang masih `submitted`. Konsekuensinya
        // seimbang dan diterima: listener yang melempar membatalkan keputusannya juga.
        event(new KeputusanWorkflowDiambil($idEvent, $tenantId, $idKorelasi, $legalEntityId === null ? null : (string) $legalEntityId, $isi));
    }

    /** @return Collection<int, string> */
    /** @param array<string, mixed> $config @return Collection<int, string> */
    private function assignees(string $tenantId, array $config): Collection
    {
        if (isset($config['assignees']) && is_array($config['assignees'])) {
            $ids = collect($config['assignees'])
                ->filter(fn (mixed $assignee): bool => is_array($assignee) && ($assignee['type'] ?? null) === 'member' && isset($assignee['id']))
                ->map(fn (array $assignee): string => (string) $assignee['id'])
                ->filter()
                ->unique()
                ->values();

            return TenantMembership::query()->where('tenant_id', $tenantId)->where('status', 'active')->whereIn('id', $ids)->pluck('id');
        }

        $assignee = $config['assignee'] ?? null;
        abort_unless(is_array($assignee) && isset($assignee['type'], $assignee['id']), 422, 'Penerima tugas belum lengkap.');
        $type = (string) $assignee['type'];
        $id = (string) $assignee['id'];
        if ($type === 'member') {
            return TenantMembership::query()->where('tenant_id', $tenantId)->where('status', 'active')->whereKey($id)->pluck('id');
        }
        abort_unless($type === 'role', 422, 'Cara menentukan penerima tugas tidak dikenali.');

        return DB::table('role_assignments as assignments')->join('tenant_memberships as memberships', 'memberships.id', '=', 'assignments.membership_id')->join('roles', 'roles.id', '=', 'assignments.role_id')->where('memberships.tenant_id', $tenantId)->where('memberships.status', 'active')->where('roles.is_active', true)->where('assignments.role_id', $id)->where('assignments.status', 'active')->where('assignments.valid_from', '<=', now())->where(fn ($query) => $query->whereNull('assignments.valid_until')->orWhere('assignments.valid_until', '>', now()))->pluck('memberships.id')->unique()->values();
    }

    /** @param array<string, mixed> $config @param Collection<int, object> $items */
    private function completionDecision(array $config, Collection $items): ?string
    {
        $policy = (string) ($config['completion_policy'] ?? 'single');
        $pending = $items->where('status', 'pending')->count();
        $responded = $items->count() - $pending;
        $rejected = $items->where('status', 'rejected')->count();

        if ($policy === 'single') {
            return $rejected > 0 ? 'rejected' : 'approved';
        }
        if ($policy === 'all') {
            if ($rejected > 0) {
                return 'rejected';
            }

            return $pending === 0 ? 'approved' : null;
        }

        $threshold = $policy === 'percentage'
            ? max(1, (int) ceil($items->count() * ((float) ($config['completion_percentage'] ?? 100) / 100)))
            : intdiv($items->count(), 2) + 1;
        if ($responded < $threshold) {
            return null;
        }

        return $rejected > 0 ? 'rejected' : 'approved';
    }

    private function markNodeCompleted(string $tenantId, string $instanceId, string $elementKey): void
    {
        if (DB::table('workflow_history')->where('instance_id', $instanceId)->where('event_type', 'node_completed')->whereJsonContains('details->element', $elementKey)->exists()) {
            return;
        }
        $this->history($tenantId, $instanceId, 'node_completed', ['element' => $elementKey]);
    }

    /** @param array<string, mixed> $details */
    private function history(string $tenantId, string $instanceId, string $eventType, array $details, ?string $actorMembershipId = null): void
    {
        DB::table('workflow_history')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $tenantId, 'instance_id' => $instanceId,
            'event_type' => $eventType, 'actor_membership_id' => $actorMembershipId,
            'details' => json_encode($details, JSON_THROW_ON_ERROR), 'occurred_at' => now(),
        ]);
    }
}
