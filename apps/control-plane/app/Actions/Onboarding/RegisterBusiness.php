<?php

namespace App\Actions\Onboarding;

use App\Actions\Modules\InstallModule;
use App\Actions\NumberSequence\EnsureNumberSequenceDrafts;
use App\Actions\ReferenceData\ProvisionDefaultUnitsOfMeasure;
use App\Jobs\DeployAppPlacement;
use App\Models\AppDataPolicy;
use App\Models\Client;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\SecurityDuty;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\AppDependencyGraph;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use LogicException;

class RegisterBusiness
{
    public function __construct(private AppDependencyGraph $dependencyGraph) {}

    /**
     * @param  array{name:string,email:string,password:string,business_name:string,app_ids:list<string>}  $data
     */
    public function handle(array $data): User
    {
        $hashedPassword = Hash::make($data['password']);

        return DB::transaction(function () use ($data, $hashedPassword): User {
            $appIds = $this->dependencyGraph->resolveAvailable($data['app_ids']);
            $slug = $this->uniqueSlug($data['business_name']);
            $user = User::create([
                'name' => $data['name'],
                'email' => Str::lower($data['email']),
                'password' => $hashedPassword,
            ]);
            $client = Client::create([
                'legal_name' => $data['business_name'],
                'slug' => $slug,
                'status' => 'active',
            ]);
            $tenant = Tenant::create([
                'client_id' => $client->id,
                'name' => $data['business_name'],
                'slug' => $slug,
                'status' => 'active',
            ]);
            app(ProvisionDefaultUnitsOfMeasure::class)->forTenant($tenant->id);
            // Tenant provisioning adalah fakta lintas app. Payload starter sengaja
            // kosong: setiap app memilih template versinya sendiri dari konfigurasi,
            // sedangkan Core hanya meneruskan tenant context yang tepercaya.
            DB::table('outbox_events')->insert([
                'id' => (string) Str::ulid(),
                'tenant_id' => $tenant->id,
                'type' => 'core.tenant.provisioned.v1',
                'correlation_id' => $tenant->id,
                'legal_entity_id' => null,
                'payload' => json_encode(['app_ids' => $appIds], JSON_THROW_ON_ERROR),
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
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
                $registry = app(ModuleRegistry::class);

                foreach ($appIds as $appId) {
                    // Dua jalur, dipilih dari satu pertanyaan: apakah id ini ada sebagai
                    // folder di modules/. Bila ya, tidak ada container yang perlu
                    // ditempatkan — memasangnya berarti menjalankan migration, mencatat
                    // pemasangan, dan mengisi data awal, semuanya di proses ini juga.
                    //
                    // Jalur container dipertahankan selama masih ada app yang belum
                    // dipindah. Ia dibuang pada fase 7, bukan sekarang.
                    if ($registry->cari($appId) !== null) {
                        app(InstallModule::class)->handle($appId, $tenant->id);

                        continue;
                    }

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

            return $user;
        });
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
