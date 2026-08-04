<?php

namespace Tests\Feature\ControlPlane;

use App\Actions\NumberSequence\EnsureNumberSequenceDrafts;
use App\Actions\NumberSequence\NumberSequenceService;
use App\Models\NumberSequenceReference;
use App\Models\TenantNumberSequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Guards against the failure modes that only appear at scale or under abuse. Each of these was measured on a seeded
 * estate of a thousand tenants before being fixed; these tests keep the fixes from silently regressing, because none
 * of them would show up in a functional test on a small database.
 */
class NumberSequenceScaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_settings_sweep_touches_only_the_current_tenant(): void
    {
        [, $context] = $this->sequence();
        $this->readyAppForTenant($context['tenant_id'], $context['app_id']);
        $otherTenants = [];
        foreach (range(1, 5) as $index) {
            $tenantId = $this->tenant();
            $this->readyAppForTenant($tenantId, $context['app_id']);
            $otherTenants[] = $tenantId;
        }
        TenantNumberSequence::query()->delete();

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        app(EnsureNumberSequenceDrafts::class)->forReadyTenant($context['tenant_id']);

        // The page-load path must not scale with the size of the estate. The old implementation walked every entitled
        // tenant on every request, which cost seconds once there were a thousand of them.
        $this->assertLessThan(10, $queries, "Draft sweep issued {$queries} queries for a single tenant.");
        $this->assertSame(1, TenantNumberSequence::query()->where('tenant_id', $context['tenant_id'])->count());
        $this->assertSame(0, TenantNumberSequence::query()->whereIn('tenant_id', $otherTenants)->count());
    }

    public function test_exhausted_allocation_blocks_do_not_accumulate(): void
    {
        [$sequence, $context] = $this->sequence(['preallocation_enabled' => true, 'preallocation_quantity' => 2]);
        $service = app(NumberSequenceService::class);

        foreach (range(1, 20) as $index) {
            $service->issue($context, 'sample-app.document', 'burn-'.$index);
        }

        // Ten blocks were consumed. Anything but a small constant here means every future issue() pays to scan them.
        // The block exhausted by the final issue is still present: pruning runs when the next block is allocated, so
        // at most one dead block is ever waiting. The recover job sweeps that last one.
        $this->assertLessThanOrEqual(1, DB::table('number_sequence_allocations')
            ->where('sequence_id', $sequence->id)->whereColumn('next_number', '>', 'last_number')->count());
        $this->assertLessThanOrEqual(2, DB::table('number_sequence_allocations')->where('sequence_id', $sequence->id)->count());
    }

    public function test_an_app_that_never_confirms_cannot_drain_the_pool(): void
    {
        config(['coreerp.max_outstanding_reservations' => 3]);
        [, $context] = $this->sequence(['is_continuous' => true, 'preallocation_enabled' => true, 'preallocation_quantity' => 10]);
        $service = app(NumberSequenceService::class);

        foreach (range(1, 3) as $index) {
            $service->reserve($context, 'sample-app.document', 'leak-'.$index);
        }

        // A broken integration should be stopped with a named error, not allowed to make the sequence unusable.
        $this->expectException(ValidationException::class);
        $service->reserve($context, 'sample-app.document', 'leak-4');
    }

    public function test_advance_rejects_a_number_that_cannot_be_rendered(): void
    {
        [$sequence, $context] = $this->sequence(['segments' => [['type' => 'number', 'length' => 4]]]);

        // Advancing is one-way, so a fat-fingered jump must fail now rather than break every future issue().
        $this->expectException(ValidationException::class);
        app(NumberSequenceService::class)->advance($sequence, $context, 999999, null);
    }

    public function test_recover_prunes_confirmed_pool_rows_and_stale_audit_events(): void
    {
        [$sequence, $context] = $this->sequence(['is_continuous' => true, 'preallocation_enabled' => true, 'preallocation_quantity' => 5]);
        $service = app(NumberSequenceService::class);
        $reservation = $service->reserve($context, 'sample-app.document', 'retained');
        $service->confirm($context, $reservation['id']);

        DB::table('number_sequence_continuous_pool')->where('status', 'confirmed')->update(['updated_at' => now()->subDays(90)]);
        DB::table('number_sequence_audit_events')->update(['occurred_at' => now()->subDays(500)]);

        $this->artisan('number-sequences:recover')->assertSuccessful();

        $this->assertSame(0, DB::table('number_sequence_continuous_pool')->where('status', 'confirmed')->count());
        $this->assertSame(0, DB::table('number_sequence_audit_events')->count());
        // The issue record is the durable proof a number was used, so it must survive the pruning.
        $this->assertSame(1, DB::table('number_sequence_issues')->where('sequence_id', $sequence->id)->count());
    }

    private function tenant(): string
    {
        $clientId = (string) Str::ulid();
        $tenantId = (string) Str::ulid();
        DB::table('clients')->insert(['id' => $clientId, 'legal_name' => 'C', 'slug' => 'c-'.Str::lower(Str::random(8)), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tenants')->insert(['id' => $tenantId, 'client_id' => $clientId, 'name' => 'T', 'slug' => 't-'.Str::lower(Str::random(8)), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return $tenantId;
    }

    /** @param array<string, mixed> $overrides @return array{0:TenantNumberSequence,1:array{tenant_id:string,app_id:string}} */
    private function sequence(array $overrides = []): array
    {
        $tenantId = $this->tenant();
        $appId = 'sample-app';
        DB::table('apps')->insertOrIgnore(['id' => $appId, 'name' => 'Sample app', 'version' => '1.0.0', 'status' => 'available', 'database_name' => 'sample_app', 'created_at' => now(), 'updated_at' => now()]);
        $reference = NumberSequenceReference::query()->firstOrCreate(
            ['code' => 'sample-app.document'],
            ['app_id' => $appId, 'name' => 'Nomor dokumen', 'allowed_scopes' => ['tenant', 'legal_entity', 'operating_unit']],
        );
        $sequence = TenantNumberSequence::query()->create([
            'tenant_id' => $tenantId, 'reference_id' => $reference->id, 'profile_code' => 'non-continuous-default',
            'scope_type' => 'tenant', 'status' => 'active', 'is_continuous' => false, 'allow_manual' => false,
            'reset_period' => 'never', 'preallocation_enabled' => true, 'preallocation_quantity' => 20,
            'minimum_number' => 1, 'segments' => [['type' => 'number', 'length' => 6]], ...$overrides,
        ]);

        return [$sequence, ['tenant_id' => $tenantId, 'app_id' => $appId]];
    }

    private function readyAppForTenant(string $tenantId, string $appId): void
    {
        DB::table('tenant_app_entitlements')->insert(['tenant_id' => $tenantId, 'app_id' => $appId, 'status' => 'active', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tenant_deployments')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $tenantId, 'profile' => 'pooled', 'placement' => 'sample-placement', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('app_placements')->insertOrIgnore(['id' => (string) Str::ulid(), 'app_id' => $appId, 'release_version' => '1.0.0', 'profile' => 'pooled', 'placement' => 'sample-placement', 'artifact_status' => 'placed', 'migration_status' => 'succeeded', 'runtime_status' => 'ready', 'ready_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }
}
