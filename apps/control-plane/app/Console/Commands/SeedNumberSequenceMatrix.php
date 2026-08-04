<?php

namespace App\Console\Commands;

use App\Actions\FiscalCalendar\FiscalCalendarService;
use App\Models\AppServiceCredential;
use App\Models\FiscalCalendar;
use App\Models\NumberSequenceReference;
use App\Models\TenantNumberSequence;
use App\Support\NumberSequenceMatrix;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds a realistic estate rather than a uniform one: every tenant has a legal entity with its own fiscal calendar
 * and several branches, and every legal number sequence configuration is live somewhere.
 *
 * A simulation where all tenants share one configuration only proves that one configuration works. This exists so
 * the load test exercises continuous pools, preallocated blocks, direct counters, fiscal resets, calendar resets and
 * per-branch scoping at the same time, against the same database.
 */
class SeedNumberSequenceMatrix extends Command
{
    protected $signature = 'number-sequences:matrix-seed
        {--tenants=100 : Tenants to create}
        {--branches=3 : Operating units per tenant}
        {--database=pgsql_test : Connection to seed}';

    protected $description = 'Seed tenants, branches, fiscal calendars, and every valid number sequence configuration.';

    public function handle(FiscalCalendarService $fiscal): int
    {
        if (app()->isProduction()) {
            $this->error('Refusing to seed in production.');

            return self::FAILURE;
        }

        config(['database.default' => (string) $this->option('database')]);
        $tenantCount = (int) $this->option('tenants');
        $branchCount = (int) $this->option('branches');
        $appId = 'matrix-app';
        $shapes = NumberSequenceMatrix::all();

        DB::table('apps')->insertOrIgnore([
            'id' => $appId, 'name' => 'Matrix app', 'version' => '1.0.0', 'status' => 'available',
            'database_name' => 'matrix_app', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('app_placements')->insertOrIgnore([
            'id' => (string) Str::ulid(), 'app_id' => $appId, 'release_version' => '1.0.0', 'profile' => 'pooled',
            'placement' => 'matrix-placement', 'artifact_status' => 'placed', 'migration_status' => 'succeeded',
            'runtime_status' => 'ready', 'ready_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $references = [];
        foreach ($shapes as $shape) {
            $references[$shape['key']] = NumberSequenceReference::query()->firstOrCreate(
                ['code' => $appId.'.'.$shape['key']],
                ['app_id' => $appId, 'name' => $shape['key'], 'allowed_scopes' => ['tenant', 'legal_entity', 'operating_unit']],
            );
        }

        [$credential, $token] = AppServiceCredential::issueToken($appId, 'matrix-load-'.Str::lower(Str::random(6)));
        $this->info('Service token: '.$token);

        $bar = $this->output->createProgressBar($tenantCount);
        $plan = [];

        foreach (range(1, $tenantCount) as $index) {
            $ids = $this->tenant($appId, $index, $branchCount, $fiscal);
            foreach ($shapes as $shape) {
                TenantNumberSequence::query()->create([
                    'tenant_id' => $ids['tenant_id'],
                    'reference_id' => $references[$shape['key']]->id,
                    'profile_code' => $shape['is_continuous'] ? 'continuous-strict' : ($shape['allow_manual'] ? 'manual-compatible' : 'non-continuous-default'),
                    'scope_type' => $shape['scope_type'],
                    'status' => 'active',
                    'is_continuous' => $shape['is_continuous'],
                    'allow_manual' => $shape['allow_manual'],
                    'reset_period' => $shape['reset_period'],
                    'preallocation_enabled' => $shape['preallocation_enabled'],
                    'preallocation_quantity' => max(1, $shape['preallocation_quantity']),
                    'minimum_number' => 1,
                    'segments' => $shape['segments'],
                ]);
            }
            $plan[] = $ids;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        file_put_contents(
            storage_path('app/number-sequence-matrix.json'),
            json_encode(['app_id' => $appId, 'token' => $token, 'shapes' => $shapes, 'tenants' => $plan], JSON_THROW_ON_ERROR),
        );

        $this->info('Tenants: '.count($plan).', sequences: '.TenantNumberSequence::query()->count().', shapes: '.count($shapes));
        $this->info('Plan written to '.storage_path('app/number-sequence-matrix.json'));

        return self::SUCCESS;
    }

    /** @return array{tenant_id:string,legal_entity_id:string,branches:list<string>} */
    private function tenant(string $appId, int $index, int $branchCount, FiscalCalendarService $fiscal): array
    {
        $now = now();
        $clientId = (string) Str::ulid();
        $tenantId = (string) Str::ulid();
        DB::table('clients')->insert(['id' => $clientId, 'legal_name' => "Matrix Client {$index}", 'slug' => "matrix-client-{$index}", 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('tenants')->insert(['id' => $tenantId, 'client_id' => $clientId, 'name' => "Matrix Tenant {$index}", 'slug' => "matrix-tenant-{$index}", 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('tenant_app_entitlements')->insert(['tenant_id' => $tenantId, 'app_id' => $appId, 'status' => 'active', 'starts_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('tenant_deployments')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $tenantId, 'profile' => 'pooled', 'placement' => 'matrix-placement', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);

        // Fiscal years start in a month that varies per tenant, so the simulation covers calendars that do not line
        // up with January and cannot be satisfied by a calendar-year shortcut.
        $startMonth = (($index - 1) % 12) + 1;
        $calendar = FiscalCalendar::query()->create(['tenant_id' => $tenantId, 'code' => 'FY', 'name' => "Fiscal {$startMonth}"]);

        // Anchor on the fiscal year that actually contains today. A calendar whose first year starts later leaves
        // the present uncovered, and the service refuses to issue into an undefined period rather than guessing.
        $anchor = Carbon::create($now->year, $startMonth, 1)->startOfDay();
        if ($anchor->greaterThan($now)) {
            $anchor->subYear();
        }
        foreach ([0, 1] as $offset) {
            $start = $anchor->copy()->addYears($offset);
            $fiscal->defineYear($calendar, 'FY'.$start->copy()->addMonths(11)->year, $start, $start->copy()->addYear()->subDay(), $fiscal->monthlyPeriods($start));
        }

        $legalEntityId = $this->organization($tenantId, 'legal_entity', "LE{$index}");
        DB::table('legal_entities')->insert(['organization_id' => $legalEntityId, 'company_code' => 'C'.str_pad((string) $index, 4, '0', STR_PAD_LEFT), 'country_code' => 'ID', 'fiscal_calendar_id' => $calendar->id, 'created_at' => $now, 'updated_at' => $now]);

        $branches = [];
        foreach (range(1, $branchCount) as $branch) {
            $branchId = $this->organization($tenantId, 'operating_unit', "BR{$index}-{$branch}");
            DB::table('operating_units')->insert(['organization_id' => $branchId, 'type' => 'business_unit', 'created_at' => $now, 'updated_at' => $now]);
            $branches[] = $branchId;
        }

        return ['tenant_id' => $tenantId, 'legal_entity_id' => $legalEntityId, 'branches' => $branches];
    }

    private function organization(string $tenantId, string $classification, string $code): string
    {
        $id = (string) Str::ulid();
        DB::table('organizations')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'name' => $code,
            'classification' => $classification, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }
}
