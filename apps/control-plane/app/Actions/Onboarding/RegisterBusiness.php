<?php

namespace App\Actions\Onboarding;

use App\Actions\NumberSequence\EnsureNumberSequenceDrafts;
use App\Actions\ReferenceData\ProvisionDefaultUnitsOfMeasure;
use App\Jobs\DeployAppPlacement;
use App\Models\AppDataPolicy;
use App\Models\Client;
use App\Models\CoreApp;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\SecurityDuty;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;

use Illuminate\Validation\ValidationException;

class RegisterBusiness
{
    /**
     * @param  array{name:string,email:string,password:string,business_name:string,app_ids:list<string>}  $data
     */
    public function handle(array $data): User
    {
        $hashedPassword = Hash::make($data['password']);

        return DB::transaction(function () use ($data, $hashedPassword): User {
            $email = Str::lower(trim($data['email']));
            $user = User::create([
                'name' => $data['name'],
                'email' => $email,
                'phone_number' => $data['phone_number'] ?? null,
                'password' => $hashedPassword,
            ]);

            $this->provisionTenantForUser($user, $data['business_name'], $data['app_ids']);

            return $user;
        });
    }

    /**
     * Menambahkan bisnis / tenant baru untuk pengguna yang sudah terautentikasi.
     *
     * @param  array{business_name:string,app_ids:list<string>}  $data
     */
    public function createForUser(User $user, array $data): TenantMembership
    {
        return DB::transaction(function () use ($user, $data): TenantMembership {
            return $this->provisionTenantForUser($user, $data['business_name'], $data['app_ids']);
        });
    }

    /**
     * Provisioning tenant, client, deployment, membership, entitlements, roles, dan sequence drafts.
     *
     * @param  list<string>  $rawAppIds
     */
    public function provisionTenantForUser(User $user, string $businessName, array $rawAppIds): TenantMembership
    {
        $appIds = CoreApp::query()
            ->whereIn('id', $rawAppIds)
            ->where('status', 'available')
            ->pluck('id');

        if ($appIds->count() !== count(array_unique($rawAppIds))) {
            throw new RuntimeException('One or more selected apps are not available.');
        }

        $slug = $this->uniqueSlug($businessName);

        $client = Client::create([
            'legal_name' => $businessName,
            'slug' => $slug,
            'status' => 'active',
        ]);

        $tenant = Tenant::create([
            'client_id' => $client->id,
            'name' => $businessName,
            'slug' => $slug,
            'status' => 'active',
        ]);

        app(ProvisionDefaultUnitsOfMeasure::class)->forTenant($tenant->id);

        $profile = (string) config('coreerp.deployment.profile');
        $placement = (string) config('coreerp.deployment.placement');
        if (! in_array($profile, ['pooled', 'isolated'], true) || ! preg_match('/^[a-z0-9][a-z0-9-]{0,119}$/', $placement)) {
            throw new LogicException('CoreERP deployment profile or placement is invalid.');
        }
        if ($profile === 'isolated') {
            $placement .= '-'.Str::lower($tenant->id);
        }

        DB::table('tenant_deployments')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'profile' => $profile,
            'placement' => $placement,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $membership = TenantMembership::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'system_role' => 'owner',
            'status' => 'active',
        ]);

        foreach ($appIds as $appId) {
            $tenant->entitlements()->create([
                'app_id' => $appId,
                'status' => 'active',
                'starts_at' => now(),
                'ends_at' => null,
            ]);
        }

        $ownerRole = Role::create([
            'tenant_id' => $tenant->id,
            'name' => 'Owner',
            'is_active' => true,
        ]);
        $ownerRole->duties()->sync(
            SecurityDuty::query()->whereIn('app_id', $appIds)->pluck('code'),
        );
        $ownerAssignment = RoleAssignment::create([
            'membership_id' => $membership->id,
            'role_id' => $ownerRole->id,
            'source' => 'automatic',
            'status' => 'active',
            'valid_from' => now(),
        ]);
        foreach (AppDataPolicy::query()->whereIn('app_id', $appIds)->get() as $policy) {
            $ownerAssignment->dataPolicyScopes()->create([
                'tenant_id' => $tenant->id,
                'policy_code' => $policy->code,
                'legal_entity_id' => null,
                'organization_id' => null,
                'hierarchy_id' => null,
                'hierarchy_version_id' => null,
                'include_descendants' => false,
                'valid_from' => now(),
            ]);
        }

        DB::afterCommit(function () use ($appIds, $placement, $tenant): void {
            foreach ($appIds as $appId) {
                $ready = DB::table('app_placements')
                    ->where('app_id', $appId)
                    ->where('placement', $placement)
                    ->where('artifact_status', 'placed')
                    ->where('migration_status', 'succeeded')
                    ->where('runtime_status', 'ready')
                    ->whereNotNull('ready_at')
                    ->exists();
                if ($ready) {
                    continue;
                }

                DeployAppPlacement::dispatch($appId, $placement);
            }

            app(EnsureNumberSequenceDrafts::class)->forReadyTenant($tenant->id);
        });

        return $membership;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'business';
        $slug = $base;

        while (Tenant::query()->where('slug', $slug)->exists() || Client::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(6));
        }

        return $slug;
    }
}
