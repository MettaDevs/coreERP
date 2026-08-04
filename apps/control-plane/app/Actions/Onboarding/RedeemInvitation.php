<?php

namespace App\Actions\Onboarding;

use App\Actions\Access\CreateInvitation;
use App\Models\InvitationCode;
use App\Models\RoleAssignment;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RedeemInvitation
{
    /** @param array{code:string,name:string,email:string,password:string} $data */
    public function handle(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $invitation = InvitationCode::query()
                ->with('roles')
                ->where('code_hash', CreateInvitation::hash($data['code']))
                ->lockForUpdate()
                ->first();

            if (! $invitation || $invitation->revoked_at || ($invitation->expires_at !== null && $invitation->expires_at->isPast())) {
                throw ValidationException::withMessages(['code' => 'Kode akses tidak valid, sudah dicabut, atau sudah kedaluwarsa.']);
            }
            if (User::query()->where('email', Str::lower($data['email']))->exists()) {
                throw ValidationException::withMessages(['email' => 'Email ini sudah memiliki akun. Silakan masuk menggunakan akun tersebut.']);
            }

            $user = User::create([
                'name' => $data['name'],
                'email' => Str::lower($data['email']),
                'password' => $data['password'],
            ]);
            $membership = TenantMembership::create([
                'tenant_id' => $invitation->tenant_id,
                'user_id' => $user->id,
                'system_role' => $invitation->system_role,
                'status' => 'active',
            ]);
            $assignments = collect();
            foreach ($invitation->roles as $role) {
                $assignments->put($role->id, RoleAssignment::create([
                    'membership_id' => $membership->id,
                    'role_id' => $role->id,
                    'source' => 'manual',
                    'source_reference' => 'invitation:'.$invitation->id,
                    'status' => 'active',
                    'valid_from' => now(),
                ]));
            }
            $scopes = DB::table('invitation_data_policy_scopes')->where('invitation_id', $invitation->id)->get();
            foreach ($scopes as $scope) {
                $assignment = $assignments->get($scope->role_id);
                if (! $assignment) continue;
                $assignment->dataPolicyScopes()->create([
                    'tenant_id' => $membership->tenant_id,
                    'policy_code' => $scope->policy_code,
                    'legal_entity_id' => $scope->legal_entity_id,
                    'organization_id' => $scope->organization_id,
                    'hierarchy_id' => $scope->hierarchy_id,
                    'hierarchy_version_id' => $scope->hierarchy_version_id,
                    'include_descendants' => $scope->include_descendants,
                    'valid_from' => now(),
                ]);
            }

            return $user;
        });
    }
}
