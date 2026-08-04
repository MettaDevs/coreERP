<?php

namespace App\Http\Controllers\Workflow;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\WorkflowRuntime;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

class InternalWorkflowInstanceController extends Controller
{
    public function store(Request $request, WorkflowRuntime $runtime): JsonResponse
    {
        $tenantId = (string) $request->attributes->get('coreerp.tenant_id');
        $appId = (string) $request->attributes->get('coreerp.app_id');
        $data = $request->validate([
            'workflow_type' => ['required', 'string', 'max:120'],
            'source_document_type' => ['required', 'string', 'max:120'],
            'source_document_id' => ['required', 'ulid'],
            'legal_entity_id' => ['nullable', 'ulid'],
            'initiator_membership_id' => ['required', 'ulid'],
            'decision_context' => ['required', 'array'],
        ]);
        $key = (string) $request->header('Idempotency-Key');
        validator(['key' => $key], ['key' => ['required', 'string', 'max:160', 'regex:/^[A-Za-z0-9._:-]+$/']])->validate();

        $type = DB::table('workflow_types')->where('code', $data['workflow_type'])->where('app_id', $appId)->first();
        abort_unless($type, 404, 'Jenis workflow tidak terdaftar untuk aplikasi ini.');
        if (($type->scope ?? 'legal_entity') === 'legal_entity') {
            validator($data, ['legal_entity_id' => ['required', 'ulid']])->validate();
        }
        abort_unless(DB::table('tenant_memberships')->where('id', $data['initiator_membership_id'])->where('tenant_id', $tenantId)->where('status', 'active')->exists(), 422, 'Pengaju workflow tidak valid.');
        $requiredContext = json_decode($type->decision_context_schema, true, 512, JSON_THROW_ON_ERROR)['required'] ?? [];
        foreach ($requiredContext as $field) {
            if (! is_string($field) || ! array_key_exists($field, $data['decision_context']) || $data['decision_context'][$field] === null) {
                throw ValidationException::withMessages(['decision_context' => "Data {$field} wajib dikirim untuk workflow ini."]);
            }
        }
        $existing = DB::table('workflow_instances')->where(['tenant_id' => $tenantId, 'workflow_type_id' => $type->id, 'idempotency_key' => $key])->first();
        if ($existing) {
            return response()->json(['data' => $this->present($existing)], 200, ['Idempotent-Replayed' => 'true']);
        }

        $version = DB::table('workflow_configuration_versions as versions')
            ->join('workflow_configurations as configurations', 'configurations.id', '=', 'versions.configuration_id')
            ->where('configurations.tenant_id', $tenantId)->where('configurations.workflow_type_id', $type->id)
            ->when(($type->scope ?? 'legal_entity') === 'legal_entity', fn ($query) => $query->where('configurations.legal_entity_id', $data['legal_entity_id']))
            ->when(($type->scope ?? 'legal_entity') === 'tenant', fn ($query) => $query->whereNull('configurations.legal_entity_id'))
            ->where('configurations.enabled', true)->where('versions.status', 'published')
            ->where(fn ($query) => $query->whereNull('versions.effective_from')->orWhere('versions.effective_from', '<=', today()))
            ->orderByDesc('versions.effective_from')->first();

        abort_unless($version, 409, 'Belum ada workflow aktif untuk dokumen ini.');
        try {
            $instance = $runtime->submit($tenantId, $type, $version, $key, $data);
        } catch (QueryException $exception) {
            if ($exception->getCode() !== '23505') {
                throw $exception;
            }
            $instance = DB::table('workflow_instances')->where(['tenant_id' => $tenantId, 'workflow_type_id' => $type->id, 'idempotency_key' => $key])->first();
            abort_unless($instance, 409, 'Permintaan sedang diproses.');

            return response()->json(['data' => $this->present($instance)], 200, ['Idempotent-Replayed' => 'true']);
        }

        return response()->json(['data' => $this->present($instance)], 201);
    }

    private function present(object $instance): array
    {
        return ['id' => $instance->id, 'status' => $instance->status, 'source_document_type' => $instance->source_document_type, 'source_document_id' => $instance->source_document_id];
    }
}
