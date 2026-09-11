<?php

namespace App\Console\Commands;

use App\Models\ModuleInstallation;
use App\Models\NumberSequenceReference;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds a realistic multi-tenant estate so the number sequence service can be measured at the scale it will actually
 * run at, rather than at the scale the unit tests run at.
 *
 * Never run this against a real database: it writes thousands of tenants. Point it at a throwaway schema, e.g.
 *   DB_TEST_SCHEMA=coreerp_load php artisan number-sequences:load-seed --database=pgsql_test --tenants=1000
 */
class SeedNumberSequenceLoad extends Command
{
    protected $signature = 'number-sequences:load-seed
        {--tenants=1000 : How many tenants to create}
        {--references=3 : Number sequence references per app}
        {--database=pgsql_test : Connection to seed}';

    protected $description = 'Seed tenants and number sequences for load testing.';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Refusing to seed load data in production.');

            return self::FAILURE;
        }

        $connection = (string) $this->option('database');
        $tenantCount = (int) $this->option('tenants');
        $referenceCount = (int) $this->option('references');
        config(['database.default' => $connection]);

        $this->info("Seeding {$tenantCount} tenants on [{$connection}]...");

        $appId = 'load-app';
        DB::table('apps')->insertOrIgnore([
            'id' => $appId, 'name' => 'Load app', 'version' => '1.0.0', 'status' => 'available',
            'database_name' => 'load_app', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // A credential so the load driver can exercise the real HTTP path, including the auth middleware, rather than
        // calling the service directly and skipping everything an actual caller pays for.
        DB::table('app_service_credentials')->insertOrIgnore([
            'id' => (string) Str::ulid(), 'app_id' => $appId, 'tenant_id' => null, 'name' => 'load-test',
            'secret_hash' => bcrypt('load-test-token'), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $references = [];
        foreach (range(1, $referenceCount) as $index) {
            $references[] = NumberSequenceReference::query()->firstOrCreate(
                ['code' => "load-app.document-{$index}"],
                ['app_id' => $appId, 'name' => "Nomor dokumen {$index}", 'allowed_scopes' => ['tenant', 'legal_entity', 'operating_unit']],
            );
        }

        $bar = $this->output->createProgressBar($tenantCount);
        $now = now();

        foreach (array_chunk(range(1, $tenantCount), 100) as $chunk) {
            $clients = $tenants = $entitlements = $deployments = $installations = $sequences = [];

            foreach ($chunk as $index) {
                $clientId = (string) Str::ulid();
                $tenantId = (string) Str::ulid();
                $clients[] = ['id' => $clientId, 'legal_name' => "Load Client {$index}", 'slug' => "load-client-{$index}", 'status' => 'active', 'created_at' => $now, 'updated_at' => $now];
                $tenants[] = ['id' => $tenantId, 'client_id' => $clientId, 'name' => "Load Tenant {$index}", 'slug' => "load-tenant-{$index}", 'status' => 'active', 'created_at' => $now, 'updated_at' => $now];
                $entitlements[] = ['tenant_id' => $tenantId, 'app_id' => $appId, 'status' => 'active', 'starts_at' => $now, 'created_at' => $now, 'updated_at' => $now];
                $deployments[] = ['id' => (string) Str::ulid(), 'tenant_id' => $tenantId, 'profile' => 'pooled', 'placement' => 'load-placement', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now];
                // Kesiapan sebuah app bagi satu tenant dibaca dari catatan pemasangan module, dan
                // catatan itu per tenant — bukan satu baris penempatan yang dibagi seluruh estate.
                $installations[] = ['tenant_id' => $tenantId, 'module_id' => $appId, 'version' => '1.0.0', 'status' => ModuleInstallation::STATUS_INSTALLED, 'installed_at' => $now, 'created_at' => $now, 'updated_at' => $now];

                foreach ($references as $reference) {
                    $sequences[] = [
                        'id' => (string) Str::ulid(), 'tenant_id' => $tenantId, 'reference_id' => $reference->id,
                        'profile_code' => 'non-continuous-default', 'scope_type' => 'tenant', 'status' => 'active',
                        'is_continuous' => false, 'allow_manual' => false, 'reset_period' => 'never',
                        'preallocation_enabled' => true, 'preallocation_quantity' => 20, 'minimum_number' => 1,
                        'maximum_number' => null, 'segments' => json_encode([['type' => 'number', 'length' => 8]], JSON_THROW_ON_ERROR),
                        'created_at' => $now, 'updated_at' => $now,
                    ];
                }
            }

            DB::table('clients')->insert($clients);
            DB::table('tenants')->insert($tenants);
            DB::table('tenant_app_entitlements')->insert($entitlements);
            DB::table('tenant_deployments')->insert($deployments);
            DB::table('core_module_installations')->insert($installations);
            DB::table('tenant_number_sequences')->insert($sequences);
            $bar->advance(count($chunk));
        }

        $bar->finish();
        $this->newLine(2);
        $this->info('Tenants: '.DB::table('tenants')->count().', sequences: '.DB::table('tenant_number_sequences')->count());

        return self::SUCCESS;
    }
}
