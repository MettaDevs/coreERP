<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\RoleAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class HrPositionAssignmentController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->attributes->get('coreerp.app_id') === 'human-resources', 403);
        $data = $request->validate(['membership_id' => ['required', 'ulid'], 'position_id' => ['required', 'ulid'], 'operating_unit_id' => ['required', 'ulid'], 'assignment_id' => ['required', 'ulid'], 'active' => ['required', 'boolean']]);
        $tenantId = (string) $request->attributes->get('coreerp.tenant_id');
        abort_unless(DB::table('tenant_memberships')->where(['id' => $data['membership_id'], 'tenant_id' => $tenantId, 'status' => 'active'])->exists(), 422, 'Anggota tidak tersedia.');
        $rules = DB::table('automatic_role_assignment_rules')->where(['tenant_id' => $tenantId, 'position_id' => $data['position_id'], 'is_active' => true])->get();
        foreach ($rules as $rule) {
            $source = 'hr-position:'.$data['assignment_id'].':'.$rule->id;
            if (! $data['active']) {
                RoleAssignment::query()->where(['membership_id' => $data['membership_id'], 'role_id' => $rule->role_id, 'source' => 'automatic', 'source_reference' => $source])->delete();

                continue;
            }
            abort_unless(is_string($rule->policy_code) && $rule->policy_code !== '', 422, 'Aturan role otomatis belum memiliki kebijakan data.');
            $policy = DB::table('app_data_policies')->where('code', $rule->policy_code)->first(['requires_legal_entity', 'requires_operating_unit']);
            abort_unless($policy && ! $policy->requires_legal_entity && $policy->requires_operating_unit, 422, 'Aturan role otomatis position hanya dapat memakai kebijakan data menurut unit kerja.');
            $assignment = RoleAssignment::query()->firstOrCreate(['membership_id' => $data['membership_id'], 'role_id' => $rule->role_id, 'source' => 'automatic', 'source_reference' => $source], ['status' => 'active', 'valid_from' => now()]);
            DB::table('role_assignment_data_policy_scopes')->updateOrInsert(
                ['role_assignment_id' => $assignment->id, 'policy_code' => $rule->policy_code, 'organization_id' => $data['operating_unit_id']],
                ['id' => (string) Str::ulid(), 'tenant_id' => $tenantId, 'legal_entity_id' => null, 'hierarchy_id' => null, 'hierarchy_version_id' => null, 'include_descendants' => false, 'valid_from' => now(), 'valid_until' => null, 'created_at' => now(), 'updated_at' => now()],
            );
        }
        DB::table('access_audit_events')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $tenantId, 'membership_id' => $data['membership_id'], 'action' => 'human-resources.position-assignment.synced', 'payload' => json_encode($data, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(status: 204);
    }
}
