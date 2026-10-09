<?php

namespace App\Foundation\Workflow\Support;

use App\Platform\Access\Support\DataPolicyAccessResolver;
use App\Platform\Modules\Support\LaunchableAppCatalog;
use App\Platform\Tenant\Models\TenantMembership;
use Illuminate\Support\Facades\DB;

/** Izin persetujuan dan batas data dinyatakan pemilik workflow pada skema tipe, bukan oleh pengaju. */
final class WorkflowApprovalAuthority
{
    /** @return array<string,mixed> */
    public function rules(\stdClass $instance): array
    {
        $type = DB::table('workflow_types')->where('id', $instance->workflow_type_id)->first();
        $schema = json_decode($type->decision_context_schema, true, 512, JSON_THROW_ON_ERROR);

        return ['app_id' => $type->app_id] + ($schema['x-approval-authority'] ?? []);
    }

    public function allows(\stdClass $instance, TenantMembership $actor): bool
    {
        $rules = $this->rules($instance);
        if (! isset($rules['permission'])) {
            return true;
        }
        if ($actor->status !== 'active' || (string) $actor->tenant_id !== (string) $instance->tenant_id
            || ($rules['disallow_submitter'] ?? false) && $actor->id === $instance->initiator_membership_id
            || ! in_array($rules['permission'], app(LaunchableAppCatalog::class)->permissionsFor($actor, $rules['app_id']), true)) {
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
