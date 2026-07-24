<?php

namespace App\Actions\Access;

use App\Models\Role;
use App\Models\SecurityDuty;
use App\Models\TenantMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpsertRole
{
    /** @param array{name:string,duty_codes:list<string>} $data */
    public function handle(TenantMembership $actor, array $data, ?Role $role = null): Role
    {
        if (! $actor->canManageAccess() || ($role && $role->tenant_id !== $actor->tenant_id)) {
            throw new AuthorizationException;
        }
        $entitledAppIds = DB::table('tenant_app_entitlements')
            ->where('tenant_id', $actor->tenant_id)
            ->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->pluck('app_id');
        $validDuties = SecurityDuty::query()
            ->whereIn('app_id', $entitledAppIds)
            ->whereIn('code', $data['duty_codes'])
            ->pluck('code');

        if ($validDuties->count() !== count(array_unique($data['duty_codes']))) {
            throw ValidationException::withMessages(['duty_codes' => 'Tanggung jawab harus berasal dari produk yang boleh digunakan tenant.']);
        }

        return DB::transaction(function () use ($actor, $data, $role, $validDuties): Role {
            $role ??= new Role(['tenant_id' => $actor->tenant_id]);
            $role->fill([
                'name' => $data['name'],
                'is_active' => true,
            ])->save();
            $role->duties()->sync($validDuties);

            return $role->load('duties');
        });
    }
}
