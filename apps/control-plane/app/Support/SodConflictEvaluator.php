<?php

namespace App\Support;

use App\Models\TenantMembership;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SodConflictEvaluator
{
    public function __construct(private readonly RoleHierarchy $hierarchy) {}

    /** @param list<string> $manualRoleIds */
    public function assertManualAssignmentAllowed(TenantMembership $membership, array $manualRoleIds): void
    {
        $automaticRoleIds = DB::table('role_assignments')
            ->where('membership_id', $membership->id)
            ->where('source', '!=', 'manual')
            ->where('status', 'active')
            ->where('valid_from', '<=', now())
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhere('valid_until', '>', now()))
            ->pluck('role_id')
            ->all();

        $roleIds = $this->hierarchy->effectiveRoleIds(
            $membership->tenant_id,
            array_values(array_unique([...$automaticRoleIds, ...$manualRoleIds])),
        );
        if ($roleIds === []) {
            return;
        }

        $dutyCodes = DB::table('security_role_duties')
            ->whereIn('role_id', $roleIds)
            ->pluck('duty_code')
            ->all();
        if ($dutyCodes === []) {
            return;
        }

        $conflict = DB::table('sod_rules')
            ->where('tenant_id', $membership->tenant_id)
            ->where('is_active', true)
            ->whereIn('first_duty_code', $dutyCodes)
            ->whereIn('second_duty_code', $dutyCodes)
            ->first();
        if (! $conflict) {
            return;
        }

        throw ValidationException::withMessages([
            'assignments' => "Role ini memberi dua tugas yang tidak boleh dipegang bersama: {$conflict->risk}",
        ]);
    }
}
