<?php

namespace App\Foundation\Workflow\Support;

use App\Platform\Access\Support\DataPolicyAccessResolver;
use App\Platform\Modules\Support\LaunchableAppCatalog;
use App\Platform\Tenant\Models\TenantMembership;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Izin persetujuan dan batas data dinyatakan pemilik workflow pada skema tipe, bukan oleh pengaju. */
final class WorkflowApprovalAuthority
{
    /** @return array<string,mixed> */
    public function rules(\stdClass $instance): array
    {
        $type = DB::table('workflow_types')->where('id', $instance->workflow_type_id)->first();

        return $this->typeRules($type);
    }

    /** @return array<string,mixed> */
    private function typeRules(\stdClass $type): array
    {
        $schema = json_decode($type->decision_context_schema, true, 512, JSON_THROW_ON_ERROR);

        return ['app_id' => $type->app_id] + ($schema['x-approval-authority'] ?? []);
    }

    public function allows(\stdClass $instance, TenantMembership $actor): bool
    {
        $rules = $this->rules($instance);

        return $this->allowsUsingRules($instance, $actor, $rules,
            fn (string $appId): array => app(LaunchableAppCatalog::class)->permissionsFor($actor, $appId));
    }

    /** @return Closure(Collection<int, \stdClass>): Collection<int, \stdClass> */
    public function filterFor(TenantMembership $actor): Closure
    {
        $rules = [];
        $permissions = [];

        return function (Collection $instances) use ($actor, &$rules, &$permissions): Collection {
            $missing = $instances->pluck('workflow_type_id')->unique()->reject(fn (string $id): bool => isset($rules[$id]))->values()->all();
            if ($missing !== []) {
                foreach (DB::table('workflow_types')->whereIn('id', $missing)->get() as $type) {
                    $rules[$type->id] = $this->typeRules($type);
                }
            }
            $permissionsFor = function (string $appId) use ($actor, &$permissions): array {
                return $permissions[$appId] ??= app(LaunchableAppCatalog::class)->permissionsFor($actor, $appId);
            };

            return $instances->filter(fn (\stdClass $instance): bool => isset($rules[$instance->workflow_type_id])
                && $this->allowsUsingRules($instance, $actor, $rules[$instance->workflow_type_id], $permissionsFor))->values();
        };
    }

    /** @param array<string, mixed> $rules */
    private function allowsUsingRules(\stdClass $instance, TenantMembership $actor, array $rules, Closure $permissionsFor): bool
    {
        if (! isset($rules['permission'])) {
            return true;
        }
        if ($actor->status !== 'active' || (string) $actor->tenant_id !== (string) $instance->tenant_id
            || ($rules['disallow_submitter'] ?? false) && $actor->id === $instance->initiator_membership_id
            || ! in_array($rules['permission'], $permissionsFor($rules['app_id']), true)) {
            return false;
        }
        if (! isset($rules['data_policy'])) {
            return true;
        }
        $scope = app(DataPolicyAccessResolver::class)->resolve($actor)[$rules['data_policy']] ?? [];
        $context = json_decode($instance->decision_context, true, 512, JSON_THROW_ON_ERROR);

        return ($scope['all'] ?? false) || collect($scope['scope_grants'] ?? [])->contains(
            fn (array $grant): bool => $grant['legal_entity_id'] === ($context['legal_entity_id'] ?? null)
                && in_array($context['responsible_org_unit_id'] ?? null, $grant['operating_unit_ids'], true),
        );
    }
}
