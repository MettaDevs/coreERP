<?php

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Apperp\ManagementAset\Tests\Concerns\BerinteraksiDenganKonteksCore;
use Tests\TestCase;

class DepreciationTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, RefreshDatabase;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = $this->buatTenantUji();
    }

    public function test_proposal_uses_usage_unit_effective_at_period_end_and_final_export_has_no_gl(): void
    {
        [$book, $usageUnit] = $this->book();
        $this->sebagaiPengguna($this->tenantId, ['management-aset.penyusutan.create', 'management-aset.penyusutan.finalize']);
        $period = $this->postJson('/api/modules/management-aset/v1/penyusutan/proposal', ['buku_aset_id' => $book, 'period_starts_on' => '2026-07-01', 'period_ends_on' => '2026-07-31'])->assertCreated()->json('data');

        $this->assertSame($usageUnit, $period['usage_org_unit_id']);
        $result = $this->postJson('/api/modules/management-aset/v1/penyusutan/'.$period['id'].'/finalisasi')->assertOk()->json('data');
        $payload = json_decode($result['export']['payload'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($usageUnit, $payload['usage_org_unit_id']);
        $this->assertArrayNotHasKey('debit', $payload);
        $this->assertArrayNotHasKey('credit', $payload);
        $this->assertArrayNotHasKey('coa', $payload);

        $retry = $this
            ->postJson('/api/modules/management-aset/v1/penyusutan/'.$period['id'].'/finalisasi')
            ->assertOk()
            ->json('data');
        $this->assertSame($period['id'], $retry['period']['id']);
        $this->assertSame(1, DB::table('aset_tr_export_penyusutan')->where('depreciation_period_id', $period['id'])->count());
    }

    public function test_reversal_creates_a_new_final_period_without_rewriting_the_original(): void
    {
        [$book] = $this->book();
        $this->sebagaiPengguna($this->tenantId, ['management-aset.penyusutan.create', 'management-aset.penyusutan.finalize', 'management-aset.penyusutan.correct']);
        $period = $this->postJson('/api/modules/management-aset/v1/penyusutan/proposal', ['buku_aset_id' => $book, 'period_starts_on' => '2026-07-01', 'period_ends_on' => '2026-07-31'])->assertCreated()->json('data');
        $this->postJson('/api/modules/management-aset/v1/penyusutan/'.$period['id'].'/finalisasi')->assertOk();
        $reversal = $this->postJson('/api/modules/management-aset/v1/penyusutan/'.$period['id'].'/reversal', ['reason' => 'Koreksi periode'])->assertCreated()->json('data.period');

        $this->assertSame($period['id'], $reversal['reverses_period_id']);
        $this->assertSame(-100.0, (float) $reversal['amount']);
        $this->assertDatabaseHas('aset_tr_penyusutan_aset', ['id' => $period['id'], 'status' => 'final']);
        $this->assertDatabaseCount('aset_tr_penyusutan_aset', 2);
        $this->postJson('/api/modules/management-aset/v1/penyusutan/'.$period['id'].'/reversal', ['reason' => 'Duplikat'])->assertConflict();
    }

    public function test_aset_books_and_periods_are_listed_only_inside_the_active_tenant(): void
    {
        [$book] = $this->book();
        $this->sebagaiPengguna($this->tenantId, ['management-aset.penyusutan.read', 'management-aset.penyusutan.create']);
        $this->postJson('/api/modules/management-aset/v1/penyusutan/proposal', ['buku_aset_id' => $book, 'period_starts_on' => '2026-07-01', 'period_ends_on' => '2026-07-31'])->assertCreated();
        $this->getJson('/api/modules/management-aset/v1/penyusutan/buku')->assertOk()->assertJsonPath('data.0.id', $book);
        $this->getJson('/api/modules/management-aset/v1/penyusutan')->assertOk()->assertJsonCount(1, 'data');
        $this->sebagaiPengguna((string) Str::ulid(), ['management-aset.penyusutan.read'])->getJson('/api/modules/management-aset/v1/penyusutan/buku')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_manual_schedule_uses_the_next_value_and_rejects_an_unplanned_period(): void
    {
        [$book] = $this->book();
        DB::table('aset_m_profil_penyusutan')->where('tenant_id', $this->tenantId)->update(['method' => 'manual', 'manual_schedule' => json_encode([['amount' => 90], ['amount' => 70]]), 'useful_life_periods' => null]);
        $this->sebagaiPengguna($this->tenantId, ['management-aset.penyusutan.create']);
        $first = $this->postJson('/api/modules/management-aset/v1/penyusutan/proposal', ['buku_aset_id' => $book, 'period_starts_on' => '2026-07-01', 'period_ends_on' => '2026-07-31'])->assertCreated()->json('data');
        $second = $this->postJson('/api/modules/management-aset/v1/penyusutan/proposal', ['buku_aset_id' => $book, 'period_starts_on' => '2026-08-01', 'period_ends_on' => '2026-08-31'])->assertCreated()->json('data');
        $this->assertSame(90.0, (float) $first['amount']);
        $this->assertSame(70.0, (float) $second['amount']);
        $this->postJson('/api/modules/management-aset/v1/penyusutan/proposal', ['buku_aset_id' => $book, 'period_starts_on' => '2026-09-01', 'period_ends_on' => '2026-09-30'])->assertStatus(422);
    }

    /** @return array{string, string} */
    private function book(): array
    {
        $now = now();
        $profile = (string) Str::ulid();
        $aset = (string) Str::ulid();
        $book = (string) Str::ulid();
        $usage = (string) Str::ulid();
        DB::table('aset_m_profil_penyusutan')->insert(['id' => $profile, 'tenant_id' => $this->tenantId, 'creation_key' => 'profile-'.Str::ulid(), 'kode' => 'PRF'.Str::random(5), 'nama' => 'Garis lurus', 'method' => 'straight_line', 'frequency' => 'monthly', 'year_basis' => 'calendar', 'useful_life_periods' => 12, 'aktif' => true, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('aset_tr_aset')->insert(['id' => $aset, 'tenant_id' => $this->tenantId, 'creation_key' => 'aset-'.Str::ulid(), 'kode' => 'AST'.Str::random(5), 'nama' => 'Aset penyusutan uji', 'legal_entity_id' => (string) Str::ulid(), ...$this->classification($now), 'acquired_on' => '2026-07-01', 'acquisition_value' => 1200, 'currency_code' => 'IDR', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('aset_tr_penempatan_aset')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $this->tenantId, 'aset_id' => $aset, 'usage_org_unit_id' => $usage, 'effective_on' => '2026-07-15', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('aset_tr_buku_aset')->insert(['id' => $book, 'tenant_id' => $this->tenantId, 'aset_id' => $aset, 'depreciation_profile_id' => $profile, 'book_code' => 'BOOK', 'acquisition_value' => 1200, 'net_book_value' => 1200, 'created_at' => $now, 'updated_at' => $now]);

        return [$book, $usage];
    }

    /**
     * Dua sumbu klasifikasi wajib milik aset, keduanya datar dan saling lepas.
     *
     * @return array{group_aset_id: string, jenis_aset_id: string}
     */
    private function classification(CarbonImmutable $now): array
    {
        $group = (string) Str::ulid();
        $type = (string) Str::ulid();
        DB::table('aset_m_group_aset')->insert(['id' => $group, 'tenant_id' => $this->tenantId, 'creation_key' => 'group-'.Str::ulid(), 'kode' => 'G'.Str::random(5), 'nama' => 'Group', 'aktif' => true, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('aset_m_jenis_aset')->insert(['id' => $type, 'tenant_id' => $this->tenantId, 'creation_key' => 'type-'.Str::ulid(), 'kode' => 'J'.Str::random(5), 'nama' => 'Jenis', 'aktif' => true, 'created_at' => $now, 'updated_at' => $now]);

        return ['group_aset_id' => $group, 'jenis_aset_id' => $type];
    }
}
