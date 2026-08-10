<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithCoreErpContext;
use Tests\TestCase;

class DepreciationTest extends TestCase
{
    use InteractsWithCoreErpContext, RefreshDatabase;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = (string) Str::ulid();
        $this->configureCoreErpContext();
    }

    public function test_proposal_uses_usage_unit_effective_at_period_end_and_final_export_has_no_gl(): void
    {
        [$book, $usageUnit] = $this->book();
        $headers = $this->contextHeaders($this->tenantId, ['management-aset.penyusutan.create', 'management-aset.penyusutan.finalize']);
        $period = $this->withHeaders($headers)->postJson('/api/v1/penyusutan/proposal', ['asset_book_id' => $book, 'period_starts_on' => '2026-07-01', 'period_ends_on' => '2026-07-31'])->assertCreated()->json('data');

        $this->assertSame($usageUnit, $period['usage_org_unit_id']);
        $result = $this->withHeaders($headers)->postJson('/api/v1/penyusutan/'.$period['id'].'/finalisasi')->assertOk()->json('data');
        $payload = json_decode($result['export']['payload'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($usageUnit, $payload['usage_org_unit_id']);
        $this->assertArrayNotHasKey('debit', $payload);
        $this->assertArrayNotHasKey('credit', $payload);
        $this->assertArrayNotHasKey('coa', $payload);

        $retry = $this->withHeaders($headers)
            ->postJson('/api/v1/penyusutan/'.$period['id'].'/finalisasi')
            ->assertOk()
            ->json('data');
        $this->assertSame($period['id'], $retry['period']['id']);
        $this->assertSame(1, DB::table('tr_export_penyusutan')->where('depreciation_period_id', $period['id'])->count());
    }

    public function test_reversal_creates_a_new_final_period_without_rewriting_the_original(): void
    {
        [$book] = $this->book();
        $headers = $this->contextHeaders($this->tenantId, ['management-aset.penyusutan.create', 'management-aset.penyusutan.finalize', 'management-aset.penyusutan.correct']);
        $period = $this->withHeaders($headers)->postJson('/api/v1/penyusutan/proposal', ['asset_book_id' => $book, 'period_starts_on' => '2026-07-01', 'period_ends_on' => '2026-07-31'])->assertCreated()->json('data');
        $this->withHeaders($headers)->postJson('/api/v1/penyusutan/'.$period['id'].'/finalisasi')->assertOk();
        $reversal = $this->withHeaders($headers)->postJson('/api/v1/penyusutan/'.$period['id'].'/reversal', ['reason' => 'Koreksi periode'])->assertCreated()->json('data.period');

        $this->assertSame($period['id'], $reversal['reverses_period_id']);
        $this->assertSame(-100.0, (float) $reversal['amount']);
        $this->assertDatabaseHas('tr_penyusutan_aset', ['id' => $period['id'], 'status' => 'final']);
        $this->assertDatabaseCount('tr_penyusutan_aset', 2);
        $this->withHeaders($headers)->postJson('/api/v1/penyusutan/'.$period['id'].'/reversal', ['reason' => 'Duplikat'])->assertConflict();
    }

    public function test_asset_books_and_periods_are_listed_only_inside_the_active_tenant(): void
    {
        [$book] = $this->book();
        $headers = $this->contextHeaders($this->tenantId, ['management-aset.penyusutan.read', 'management-aset.penyusutan.create']);
        $this->withHeaders($headers)->postJson('/api/v1/penyusutan/proposal', ['asset_book_id' => $book, 'period_starts_on' => '2026-07-01', 'period_ends_on' => '2026-07-31'])->assertCreated();
        $this->withHeaders($headers)->getJson('/api/v1/penyusutan/buku')->assertOk()->assertJsonPath('data.0.id', $book);
        $this->withHeaders($headers)->getJson('/api/v1/penyusutan')->assertOk()->assertJsonCount(1, 'data');
        $this->withHeaders($this->contextHeaders((string) Str::ulid(), ['management-aset.penyusutan.read']))->getJson('/api/v1/penyusutan/buku')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_manual_schedule_uses_the_next_value_and_rejects_an_unplanned_period(): void
    {
        [$book] = $this->book();
        DB::table('m_profil_penyusutan')->where('tenant_id', $this->tenantId)->update(['method' => 'manual', 'manual_schedule' => json_encode([['amount' => 90], ['amount' => 70]]), 'useful_life_periods' => null]);
        $headers = $this->contextHeaders($this->tenantId, ['management-aset.penyusutan.create']);
        $first = $this->withHeaders($headers)->postJson('/api/v1/penyusutan/proposal', ['asset_book_id' => $book, 'period_starts_on' => '2026-07-01', 'period_ends_on' => '2026-07-31'])->assertCreated()->json('data');
        $second = $this->withHeaders($headers)->postJson('/api/v1/penyusutan/proposal', ['asset_book_id' => $book, 'period_starts_on' => '2026-08-01', 'period_ends_on' => '2026-08-31'])->assertCreated()->json('data');
        $this->assertSame(90.0, (float) $first['amount']);
        $this->assertSame(70.0, (float) $second['amount']);
        $this->withHeaders($headers)->postJson('/api/v1/penyusutan/proposal', ['asset_book_id' => $book, 'period_starts_on' => '2026-09-01', 'period_ends_on' => '2026-09-30'])->assertStatus(422);
    }

    private function book(): array
    {
        $now = now();
        $profile = (string) Str::ulid();
        $asset = (string) Str::ulid();
        $book = (string) Str::ulid();
        $usage = (string) Str::ulid();
        DB::table('m_profil_penyusutan')->insert(['id' => $profile, 'tenant_id' => $this->tenantId, 'creation_key' => 'profile-'.Str::ulid(), 'kode' => 'PRF'.Str::random(5), 'nama' => 'Garis lurus', 'method' => 'straight_line', 'frequency' => 'monthly', 'year_basis' => 'calendar', 'useful_life_periods' => 12, 'aktif' => true, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('tr_penerimaan_aset')->insert(['id' => $asset, 'tenant_id' => $this->tenantId, 'creation_key' => 'asset-'.Str::ulid(), 'kode' => 'AST'.Str::random(5), 'legal_entity_id' => (string) Str::ulid(), ...$this->classification($now), 'acquired_on' => '2026-07-01', 'acquisition_value' => 1200, 'currency_code' => 'IDR', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('tr_penempatan_aset')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $this->tenantId, 'asset_id' => $asset, 'usage_org_unit_id' => $usage, 'effective_on' => '2026-07-15', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('tr_buku_aset')->insert(['id' => $book, 'tenant_id' => $this->tenantId, 'asset_id' => $asset, 'depreciation_profile_id' => $profile, 'book_code' => 'BOOK', 'acquisition_value' => 1200, 'net_book_value' => 1200, 'created_at' => $now, 'updated_at' => $now]);

        return [$book, $usage];
    }

    /**
     * Dua sumbu klasifikasi wajib milik aset, keduanya datar dan saling lepas.
     *
     * @return array{group_aset_id: string, jenis_aset_id: string}
     */
    private function classification($now): array
    {
        $group = (string) Str::ulid();
        $type = (string) Str::ulid();
        DB::table('m_group_aset')->insert(['id' => $group, 'tenant_id' => $this->tenantId, 'creation_key' => 'group-'.Str::ulid(), 'kode' => 'G'.Str::random(5), 'nama' => 'Group', 'aktif' => true, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('m_jenis_aset')->insert(['id' => $type, 'tenant_id' => $this->tenantId, 'creation_key' => 'type-'.Str::ulid(), 'kode' => 'J'.Str::random(5), 'nama' => 'Jenis', 'aktif' => true, 'created_at' => $now, 'updated_at' => $now]);

        return ['group_aset_id' => $group, 'jenis_aset_id' => $type];
    }
}
