<?php

namespace App\Actions\Onboarding;

use App\Actions\Access\CreateInvitation;
use App\Models\InvitationCode;
use App\Models\RoleAssignment;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\OrganizationScopeResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RedeemInvitation
{
    public function __construct(private readonly OrganizationScopeResolver $scopeResolver) {}

    /** @param array{code:string,name:string,email:string,password:string} $data */
    public function handle(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $invitation = InvitationCode::query()
                ->with('roles')
                ->where('code_hash', CreateInvitation::hash($data['code']))
                ->lockForUpdate()
                ->first();

            if (! $invitation || $invitation->used_at || $invitation->revoked_at || $invitation->expires_at->isPast()) {
                throw ValidationException::withMessages(['code' => 'Invitation code is invalid, expired, revoked, or already used.']);
            }
            if (User::query()->where('email', Str::lower($data['email']))->exists()) {
                throw ValidationException::withMessages(['email' => 'This flow only accepts a new identity.']);
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
            $scope = $this->scopeResolver->resolve($invitation->tenant_id, [
                'organization_id' => $invitation->organization_id,
                'hierarchy_id' => $invitation->hierarchy_id,
                'include_descendants' => $invitation->include_descendants,
            ]);
            foreach ($invitation->roles as $role) {
                RoleAssignment::create([
                    'membership_id' => $membership->id,
                    'role_id' => $role->id,
                    'source' => 'manual',
                    'status' => 'active',
                    'valid_from' => now(),
                ])->organizationScope()->create($scope);
            }
            $invitation->update(['used_at' => now()]);

            return $user;
        });
    }
}
