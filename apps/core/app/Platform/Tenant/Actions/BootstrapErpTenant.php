<?php

declare(strict_types=1);

namespace App\Platform\Tenant\Actions;

use App\Platform\Access\Models\AppDataPolicy;
use App\Platform\Access\Models\Role;
use App\Platform\Access\Models\RoleAssignment;
use App\Platform\Access\Support\OwnerRoleDuties;
use App\Platform\Environment\Models\Environment;
use App\Platform\Modules\Actions\InstallModule;
use App\Platform\Modules\Contracts\TenantProvisioned;
use App\Platform\Modules\Support\AppDependencyGraph;
use App\Platform\Modules\Support\ModuleEventDispatcher;
use App\Platform\Modules\Support\ModuleRegistry;
use App\Platform\Tenant\Events\ErpTenantInitialized;
use App\Platform\Tenant\Events\TenantModulesInstalled;
use App\Platform\Tenant\Support\ErpTenantSetup;
use App\Platform\Tenant\Support\TenantCreation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class BootstrapErpTenant
{
    public function __construct(private readonly AppDependencyGraph $dependencyGraph) {}

    public function handle(TenantCreation $creation, ErpTenantSetup $setup): ?Environment
    {
        return DB::transaction(function () use ($creation, $setup): ?Environment {
            $tenant = $creation->tenant;
            $membership = $creation->membership;
            $user = $creation->principal;
            $slug = $tenant->slug;
            $appIds = $this->dependencyGraph->resolveAvailable($setup->appIds);
            event(new ErpTenantInitialized($tenant->id));
            $eventId = (string) Str::ulid();
            DB::table('outbox_events')->insert([
                'id' => $eventId,
                'tenant_id' => $tenant->id,
                'type' => 'core.tenant.provisioned.v1',
                'correlation_id' => $tenant->id,
                'legal_entity_id' => null,
                'payload' => json_encode(['app_ids' => $appIds], JSON_THROW_ON_ERROR),
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $firstKind = $setup->kind;
            $environment = null;

            $hosting = $setup->hosting;
            $onClientServer = $hosting === Environment::HOSTING_CLIENT_SERVER;

            if ($firstKind !== 'none') {
                $isProduction = $firstKind === 'production';

                $environment = Environment::create([
                    'tenant_id' => $tenant->id,
                    'kind' => $firstKind,
                    'name' => $isProduction ? 'Production' : 'Peragaan',
                    'slug' => $isProduction ? $slug : 'peragaan',
                    'database_name' => null,
                    'hosting' => $hosting,
                    'status' => $isProduction && ! $onClientServer ? 'active' : 'provisioning',
                    'outbound_allowed' => $isProduction,
                    'expires_at' => $isProduction ? null : $setup->expiresAt,
                ]);
            }
            if ($environment instanceof Environment) {
                DB::table('environment_members')->insert([
                    'environment_id' => $environment->id,
                    'user_id' => $user->id,
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

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
                'is_owner' => true,
            ]);
            app(OwnerRoleDuties::class)->sync($ownerRole);
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

            DB::afterCommit(function () use ($appIds, $eventId, $tenant, $environment): void {

                if (! $environment instanceof Environment) {
                    return;
                }

                if ($environment->hostedOnClientServer()) {
                    return;
                }

                $registry = app(ModuleRegistry::class);

                foreach ($appIds as $appId) {
                    if ($registry->find($appId) === null) {
                        continue;
                    }
                    app(InstallModule::class)->handle($appId, $tenant->id, $environment);
                    app(ModuleEventDispatcher::class)->dispatch(
                        new TenantProvisioned($eventId, (string) $tenant->id, (string) $tenant->id, null, ['app_ids' => [$appId]]),
                        (string) $tenant->id,
                    );
                }

                event(new TenantModulesInstalled($tenant->id));

            });

            return $environment;
        });
    }
}
