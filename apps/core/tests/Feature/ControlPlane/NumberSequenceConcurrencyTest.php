<?php

namespace Tests\Feature\ControlPlane;

use App\Actions\NumberSequence\NumberSequenceService;
use App\Models\NumberSequenceReference;
use App\Models\TenantNumberSequence;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * These tests exist because the number sequence design is a concurrency design. Every guarantee it makes lives in a
 * row lock, a SKIP LOCKED claim, or a unique index — and all three are silently no-ops on SQLite, so a SQLite suite
 * proves none of them.
 *
 * `pgsql_test_secondary` is a second connection to the same database. It stands in for a second Core API instance:
 * two sessions, one PostgreSQL primary, exactly the deployment the scale-out plan describes. Transactions are
 * interleaved by hand so each assertion is deterministic rather than timing-dependent.
 *
 * DatabaseTruncation rather than RefreshDatabase: RefreshDatabase wraps each test in a transaction that is never
 * committed, so the second connection would not be able to see any of the data under test.
 */
class NumberSequenceConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    /**
     * Reference data seeded by migrations rather than by a seeder. Nothing restores it between tests, so truncating
     * it here would silently break unrelated suites that run afterwards.
     *
     * This list must name every table a migration inserts into. When a new one is added and this list is not, the
     * failure lands in an unrelated test file and reads like that file's bug. The set is greppable:
     *
     *     grep -rho "DB::table('[a-z_]*')->insert" database/migrations/ | sort -u
     */
    protected array $exceptTables = [
        'country_regions',
        'hierarchy_purposes',
        'number_sequence_profiles',
        'party_types',
    ];

    /**
     * DatabaseTruncation commits its data, while the rest of the suite uses RefreshDatabase and would start its
     * transactions on top of whatever this class left behind. Truncating the two roots here cascades through every
     * tenant, organization, sequence, counter, pool, and issue row so the handoff is clean.
     */
    protected function tearDown(): void
    {
        DB::statement('TRUNCATE TABLE clients, apps RESTART IDENTITY CASCADE');

        parent::tearDown();
    }

    public function test_two_instances_never_claim_the_same_pooled_number(): void
    {
        [$sequence, $context] = $this->sequence(['is_continuous' => true, 'preallocation_enabled' => true, 'preallocation_quantity' => 5]);
        app(NumberSequenceService::class)->reserve($context, 'sample-app.document', 'warm-the-pool');

        $primary = DB::connection('pgsql_test');
        $secondary = DB::connection('pgsql_test_secondary');
        $primary->beginTransaction();
        $secondary->beginTransaction();

        try {
            $claimedByFirst = $this->claimAvailable($primary, $sequence->id);
            $claimedBySecond = $this->claimAvailable($secondary, $sequence->id);
        } finally {
            $primary->rollBack();
            $secondary->rollBack();
        }

        $this->assertNotNull($claimedByFirst);
        $this->assertNotNull($claimedBySecond, 'SKIP LOCKED must hand the second instance a different row, not nothing.');
        $this->assertNotSame($claimedByFirst, $claimedBySecond, 'Two instances claimed the same continuous number.');
    }

    public function test_a_second_instance_blocks_on_the_counter_row_lock(): void
    {
        [$sequence, $context] = $this->sequence(['preallocation_enabled' => false]);
        app(NumberSequenceService::class)->issue($context, 'sample-app.document', 'create-the-counter');

        $primary = DB::connection('pgsql_test');
        $secondary = DB::connection('pgsql_test_secondary');
        $secondary->statement("SET lock_timeout = '300ms'");

        $primary->beginTransaction();
        $primary->table('number_sequence_counters')->where('sequence_id', $sequence->id)->lockForUpdate()->get();
        $secondary->beginTransaction();

        $blocked = false;
        try {
            $secondary->table('number_sequence_counters')->where('sequence_id', $sequence->id)->lockForUpdate()->get();
        } catch (QueryException) {
            $blocked = true;
        } finally {
            $secondary->rollBack();
            $primary->rollBack();
        }

        $this->assertTrue($blocked, 'The counter row lock is not actually held, so two instances can advance it together.');
    }

    public function test_the_idempotency_index_rejects_a_duplicate_issue_from_a_second_instance(): void
    {
        [$sequence, $context] = $this->sequence(['preallocation_enabled' => false]);
        app(NumberSequenceService::class)->issue($context, 'sample-app.document', 'shared-key');

        $duplicated = false;
        try {
            DB::connection('pgsql_test_secondary')->table('number_sequence_issues')->insert([
                'id' => (string) Str::ulid(),
                'sequence_id' => $sequence->id,
                'app_id' => $context['app_id'],
                'scope_key' => 'tenant:'.$context['tenant_id'],
                'period_key' => 'all',
                'numeric_value' => 999,
                'formatted_value' => '000999',
                'idempotency_key' => 'shared-key',
                'is_manual' => false,
                'issued_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $duplicated = true;
        }

        $this->assertTrue($duplicated, 'A second instance was able to write a second number for one idempotency key.');
    }

    public function test_concurrent_issues_across_two_instances_never_repeat_a_number(): void
    {
        [$sequence, $context] = $this->sequence(['preallocation_enabled' => true, 'preallocation_quantity' => 3]);
        $service = app(NumberSequenceService::class);

        $numbers = [];
        foreach (range(1, 9) as $attempt) {
            $numbers[] = $service->issue($context, 'sample-app.document', 'txn-'.$attempt)['number'];
        }

        $this->assertCount(9, array_unique($numbers), 'Preallocated blocks handed out a duplicate number.');
        $this->assertSame(9, DB::table('number_sequence_issues')->where('sequence_id', $sequence->id)->count());
    }

    private function claimAvailable(Connection $connection, string $sequenceId): ?int
    {
        $value = $connection->table('number_sequence_continuous_pool')
            ->where('sequence_id', $sequenceId)
            ->where('status', 'available')
            ->orderBy('numeric_value')
            ->lock('for update skip locked')
            ->value('numeric_value');

        return $value === null ? null : (int) $value;
    }

    /** @param array<string, mixed> $overrides @return array{0:TenantNumberSequence,1:array{tenant_id:string,app_id:string}} */
    private function sequence(array $overrides = []): array
    {
        $clientId = (string) Str::ulid();
        $tenantId = (string) Str::ulid();
        $appId = 'sample-app';
        DB::table('clients')->insert(['id' => $clientId, 'legal_name' => 'Sample Client', 'slug' => 'sample-client-'.Str::lower(Str::random(6)), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tenants')->insert(['id' => $tenantId, 'client_id' => $clientId, 'name' => 'Sample Tenant', 'slug' => 'sample-tenant-'.Str::lower(Str::random(6)), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('apps')->insert(['id' => $appId, 'name' => 'Sample app', 'version' => '1.0.0', 'status' => 'available', 'database_name' => 'sample_app', 'created_at' => now(), 'updated_at' => now()]);
        $reference = NumberSequenceReference::query()->create(['app_id' => $appId, 'code' => 'sample-app.document', 'name' => 'Nomor dokumen', 'allowed_scopes' => ['tenant', 'legal_entity', 'operating_unit']]);
        $sequence = TenantNumberSequence::query()->create([
            'tenant_id' => $tenantId,
            'reference_id' => $reference->id,
            'profile_code' => 'non-continuous-default',
            'scope_type' => 'tenant',
            'status' => 'active',
            'is_continuous' => false,
            'allow_manual' => false,
            'reset_period' => 'never',
            'preallocation_enabled' => true,
            'preallocation_quantity' => 20,
            'minimum_number' => 1,
            'segments' => [['type' => 'number', 'length' => 6]],
            ...$overrides,
        ]);

        return [$sequence, ['tenant_id' => $tenantId, 'app_id' => $appId]];
    }
}
