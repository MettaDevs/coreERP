<?php

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use App\Models\CurrencyPrecision;
use App\Models\FinancePosting;
use App\Models\OrganizationHierarchyVersion;
use App\Support\Finance\MoneyPrecision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Apperp\ManagementAset\Tests\Concerns\BerinteraksiDenganKonteksCore;
use Modules\Apperp\ManagementAset\Tests\Concerns\MenerbitkanJurnalPenerimaan;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Koreksi nilai perolehan dan jurnal `asset.acquisition_adjustment` (feed posting finance, TODO 12; K-10,
 * K-33..K-36): selisihnya dijurnal ke akun yang sama dengan jurnal perolehannya, dengan mode yang tercatat
 * di jurnal itu, bertanggal hari koreksi, dan tidak dikirim bila jurnal asalnya dicatat manual.
 */
class AcquisitionAdjustmentTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, MenerbitkanJurnalPenerimaan, RefreshDatabase;

    private const ALASAN = 'Faktur ternyata 510.000';

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanJurnalPenerimaan();
    }

    public function test_a_higher_value_debits_the_asset_and_credits_the_payable_of_the_original_journal(): void
    {
        $group = $this->groupTerpetakan('KENDARAAN', 'Kendaraan');
        [$penerimaan, $aset] = $this->terimaAset($group);

        $hasil = $this->koreksi($aset, 510000)->assertOk()->json('data.adjustment');
        $postingId = 'AST-ADJ-'.$aset.'-1';
        $this->assertSame(['note' => null, 'posting' => ['posting_id' => $postingId, 'status' => 'pending']], $hasil);

        $payload = $this->payload($postingId);
        $this->assertSame('asset.acquisition_adjustment', $payload['posting_type']);
        $this->assertSame('AST-ACQ-'.$penerimaan, $payload['adjusts_posting_id']);
        $this->assertSame('direct_payable', $payload['settlement_mode']);
        $this->assertSame($this->vendor, $payload['vendor']['id']);
        // Bertanggal hari koreksi dilakukan, bukan tanggal perolehannya (K-34).
        $this->assertSame([$this->hariIni(), $this->hariIni()], [$payload['posting_date'], $payload['document_date']]);
        $this->assertSame([
            ['1-2300', '10000.00', '0.00', ['BUSINESS_UNIT:KLN-A']],
            ['2-1100', '0.00', '10000.00', ['BUSINESS_UNIT:KLN-A']],
        ], $this->barisJurnal($payload));
        $this->assertSame('Koreksi nilai perolehan '.$this->kodeAset($aset).': '.self::ALASAN, $payload['source_document']['description']);
        $this->assertSame(['500000.00', '510000.00', '10000.00'], [
            $payload['details']['assets'][0]['acquisition_value_before'],
            $payload['details']['assets'][0]['acquisition_value_after'],
            $payload['details']['assets'][0]['adjustment_amount'],
        ]);

        // Register dan bukunya ikut menjadi 510.000.
        $this->assertSame('510000.00', (string) DB::table('aset_tr_aset')->where('id', $aset)->value('acquisition_value'));
        $this->assertSame(['510000.00', '510000.00'], array_values((array) DB::table('aset_tr_buku_aset')->where('aset_id', $aset)->first(['acquisition_value', 'net_book_value'])));
    }

    public function test_the_correction_keeps_the_mode_recorded_on_the_original_journal(): void
    {
        $group = $this->groupTerpetakan('KENDARAAN', 'Kendaraan');
        [, $aset] = $this->terimaAset($group);
        // Mode clearing ditambahkan sesudah perolehan, berlaku mundur sebelum tanggal perolehannya:
        // menghitung ulang dari setelan akan memilih perantara, padahal jurnal aslinya ke hutang (K-10).
        $this->actingAs($this->owner)->postJson("/api/v1/organizations/{$this->le}/finance-posting/settlement-modes", [
            'mode' => 'clearing', 'effective_from' => '2026-08-01',
        ])->assertCreated();

        $this->koreksi($aset, 510000)->assertOk();

        $payload = $this->payload('AST-ADJ-'.$aset.'-1');
        $this->assertSame('direct_payable', $payload['settlement_mode']);
        $this->assertSame('2-1100', $this->barisJurnal($payload)[1][0]);
    }

    public function test_a_clearing_original_credits_the_clearing_account(): void
    {
        $this->actingAs($this->owner)->postJson("/api/v1/organizations/{$this->le}/finance-posting/settlement-modes", [
            'mode' => 'clearing', 'effective_from' => '2026-01-01',
        ])->assertCreated();
        $group = $this->groupTerpetakan('KENDARAAN', 'Kendaraan');
        [, $aset] = $this->terimaAset($group);

        $this->koreksi($aset, 510000)->assertOk();

        $payload = $this->payload('AST-ADJ-'.$aset.'-1');
        $this->assertSame('clearing', $payload['settlement_mode']);
        $this->assertSame([['1-2300', '10000.00', '0.00'], ['2-1900', '0.00', '10000.00']], array_map(static fn (array $baris): array => array_slice($baris, 0, 3), $this->barisJurnal($payload)));
    }

    public function test_the_correction_follows_the_assets_current_dimension(): void
    {
        $group = $this->groupTerpetakan('KENDARAAN', 'Kendaraan');
        [, $aset] = $this->terimaAset($group);
        $klinikB = $this->businessUnitBaru('Klinik Metta B', 'KLN-B');
        // Mutasi memperbarui dimensi keuangan aset; koreksi sesudahnya memakai dimensi itu, seperti
        // transaksi aset tetap di F&O yang memakai dimensi aset saat transaksinya dibuat.
        DB::table('aset_tr_aset')->where('id', $aset)->update(['financial_dimension_org_unit_id' => $klinikB]);

        $this->koreksi($aset, 510000)->assertOk();

        $this->assertSame([['BUSINESS_UNIT:KLN-B'], ['BUSINESS_UNIT:KLN-B']], array_column($this->barisJurnal($this->payload('AST-ADJ-'.$aset.'-1')), 3));
    }

    public function test_a_lower_value_reverses_the_direction(): void
    {
        $group = $this->groupTerpetakan('KENDARAAN', 'Kendaraan');
        [, $aset] = $this->terimaAset($group, nilai: 510000);

        $this->koreksi($aset, 500000)->assertOk();

        $payload = $this->payload('AST-ADJ-'.$aset.'-1');
        $this->assertSame([['1-2300', '0.00', '10000.00'], ['2-1100', '10000.00', '0.00']], array_map(static fn (array $baris): array => array_slice($baris, 0, 3), $this->barisJurnal($payload)));
        $this->assertSame('-10000.00', $payload['details']['assets'][0]['adjustment_amount']);
    }

    public function test_grants_and_opening_balances_credit_the_offset_of_their_original_journal(): void
    {
        $group = $this->groupTerpetakan('KENDARAAN', 'Kendaraan');
        [, $hibah] = $this->terimaAset($group, ['cara_perolehan' => 'hibah', 'vendor_id' => null]);
        [$saldoAwal, $lama] = $this->terimaAset($group, ['cara_perolehan' => 'saldo_awal', 'vendor_id' => null, 'tanggal' => '2022-01-15', 'tanggal_siap_pakai' => '2022-01-15'], saldoAwal: true);

        $this->koreksi($hibah, 510000)->assertOk();
        $this->koreksi($lama, 510000)->assertOk();

        $koreksiHibah = $this->payload('AST-ADJ-'.$hibah.'-1');
        $this->assertSame('3-5100', $this->barisJurnal($koreksiHibah)[1][0]);
        $this->assertNull($koreksiHibah['vendor']);
        $koreksiLama = $this->payload('AST-ADJ-'.$lama.'-1');
        $this->assertSame('AST-OPB-'.$saldoAwal, $koreksiLama['adjusts_posting_id']);
        $this->assertSame('3-9000', $this->barisJurnal($koreksiLama)[1][0]);
    }

    public function test_a_manual_original_journal_keeps_the_correction_manual(): void
    {
        $group = $this->groupTerpetakan('KENDARAAN', 'Kendaraan');
        // Sebelum cutover: jurnal perolehannya dicatat manual.
        [$penerimaan, $aset] = $this->terimaAset($group, ['tanggal' => '2025-12-15', 'tanggal_siap_pakai' => '2025-12-15']);
        $this->assertSame('manual', $this->posting($penerimaan)->status);

        $hasil = $this->koreksi($aset, 510000)->assertOk()->json('data.adjustment');

        $this->assertNull($hasil['posting']);
        $this->assertSame('Jurnal perolehan aset ini dicatat manual di aplikasi finance, jadi koreksinya juga dicatat manual di sana. Nilai di register aset tetap berubah.', $hasil['note']);
        $this->assertSame(0, FinancePosting::query()->where('posting_type', 'asset.acquisition_adjustment')->count());
        $this->assertSame('510000.00', (string) DB::table('aset_tr_aset')->where('id', $aset)->value('acquisition_value'));
    }

    public function test_an_asset_without_an_acquisition_journal_changes_only_the_register(): void
    {
        $group = $this->groupTerpetakan('KENDARAAN', 'Kendaraan');
        // Penerimaan bernilai nol tidak menerbitkan jurnal perolehan.
        [, $aset] = $this->terimaAset($group, nilai: 0);

        $hasil = $this->koreksi($aset, 510000)->assertOk()->json('data.adjustment');

        $this->assertNull($hasil['posting']);
        $this->assertStringStartsWith('Aset ini tidak punya jurnal perolehan', $hasil['note']);
        $this->assertSame(0, FinancePosting::query()->where('posting_type', 'asset.acquisition_adjustment')->count());
    }

    public function test_a_reason_and_todays_date_are_required_only_when_the_value_changes(): void
    {
        $group = $this->groupTerpetakan('KENDARAAN', 'Kendaraan');
        [, $aset] = $this->terimaAset($group);

        $this->koreksi($aset, 510000, alasan: '')->assertStatus(422)->assertJsonValidationErrors(['reason']);
        $this->koreksi($aset, 510000, tanggal: now()->subDays(3)->toDateString())->assertStatus(422)->assertJsonValidationErrors(['adjustment_date']);
        $this->koreksi($aset, 510000, tanggal: null)->assertStatus(422)->assertJsonValidationErrors(['adjustment_date']);
        $this->assertSame(0, FinancePosting::query()->where('posting_type', 'asset.acquisition_adjustment')->count());
        $this->assertSame('500000.00', (string) DB::table('aset_tr_aset')->where('id', $aset)->value('acquisition_value'));

        // Nilai yang sama, atau koreksi field lain, tidak menuntut alasan dan tidak menerbitkan apa pun.
        $this->koreksi($aset, 500000, alasan: '', tanggal: null)->assertOk()->assertJsonPath('data.adjustment', null);
        $this->sebagaiPengguna($this->tenantId, ['management-aset.aset.update'])
            ->patchJson(self::API.'aset/'.$aset, ['serial_number' => 'SN-01'])
            ->assertOk()->assertJsonPath('data.adjustment', null);
        $this->assertSame(0, FinancePosting::query()->where('posting_type', 'asset.acquisition_adjustment')->count());
    }

    public function test_values_finer_than_the_register_or_the_currency_precision_are_refused(): void
    {
        $group = $this->groupTerpetakan('KENDARAAN', 'Kendaraan');
        [, $aset] = $this->terimaAset($group);

        // Register menyimpan dua desimal; yang lebih halus akan dibulatkan database tanpa kabar. Ditolak
        // aturan dua desimal itu sendiri, bukan hanya oleh presisi mata uang yang kebetulan juga dua.
        $this->koreksi($aset, '510000.005')->assertStatus(422)->assertJsonValidationErrors(['acquisition_value' => 'decimal places']);

        CurrencyPrecision::query()->create(['tenant_id' => $this->tenantId, 'currency_code' => 'IDR', 'amount_decimals' => 0, 'unit_amount_decimals' => 0]);
        app(MoneyPrecision::class)->forget();
        $this->koreksi($aset, '510000.50')->assertStatus(422)->assertJsonValidationErrors([
            'acquisition_value' => 'Selisih koreksinya lebih halus dari presisi IDR (0 desimal), jadi jurnal koreksinya tidak akan sama persis dengan register. Tulis nilai perolehan dengan paling banyak 0 desimal.',
        ]);
        $this->assertSame('500000.00', (string) DB::table('aset_tr_aset')->where('id', $aset)->value('acquisition_value'));
        $this->koreksi($aset, 510000)->assertOk();
        $this->assertSame('10000', $this->payload('AST-ADJ-'.$aset.'-1')['totals']['debit']);
    }

    public function test_each_correction_gets_the_next_number_and_refers_to_the_acquisition(): void
    {
        $group = $this->groupTerpetakan('KENDARAAN', 'Kendaraan');
        [$penerimaan, $aset] = $this->terimaAset($group);

        $this->koreksi($aset, 510000)->assertOk()->assertJsonPath('data.adjustment.posting.posting_id', 'AST-ADJ-'.$aset.'-1');
        $this->koreksi($aset, 505000)->assertOk()->assertJsonPath('data.adjustment.posting.posting_id', 'AST-ADJ-'.$aset.'-2');

        $kedua = $this->payload('AST-ADJ-'.$aset.'-2');
        $this->assertSame('AST-ACQ-'.$penerimaan, $kedua['adjusts_posting_id']);
        // Selisih dihitung dari nilai sesudah koreksi pertama.
        $this->assertSame([['1-2300', '0.00', '5000.00'], ['2-1100', '5000.00', '0.00']], array_map(static fn (array $baris): array => array_slice($baris, 0, 3), $this->barisJurnal($kedua)));
    }

    public function test_the_preview_shows_the_journal_the_correction_publishes(): void
    {
        $group = $this->groupTerpetakan('KENDARAAN', 'Kendaraan');
        [, $aset] = $this->terimaAset($group);

        $pratinjau = $this->pratinjauKoreksi($aset, 510000)->assertOk()->json('data');
        $this->assertSame(['500000.00', '510000', '10000.00', 'IDR', [], null], [
            $pratinjau['before'], $pratinjau['after'], $pratinjau['difference'], $pratinjau['currency_code'], $pratinjau['blockers'], $pratinjau['note'],
        ]);
        $this->assertSame(['AST-ADJ-'.$aset.'-1', 'pending', $this->hariIni(), '10000.00'], [
            $pratinjau['posting']['posting_id'], $pratinjau['posting']['status'], $pratinjau['posting']['posting_date'], $pratinjau['posting']['total'],
        ]);
        // Pratinjau tidak menyimpan apa pun.
        $this->assertSame(0, FinancePosting::query()->where('posting_type', 'asset.acquisition_adjustment')->count());
        $this->assertSame('500000.00', (string) DB::table('aset_tr_aset')->where('id', $aset)->value('acquisition_value'));

        $this->koreksi($aset, 510000)->assertOk();
        $this->assertSame(
            array_map(static fn (array $baris): array => [$baris['account_code'], $baris['debit'], $baris['credit']], $pratinjau['posting']['lines']),
            array_map(static fn (array $baris): array => array_slice($baris, 0, 3), $this->barisJurnal($this->payload('AST-ADJ-'.$aset.'-1'))),
        );
    }

    public function test_an_unmapped_group_holds_the_correction(): void
    {
        $group = $this->groupTerpetakan('KENDARAAN', 'Kendaraan', tanpaLawan: true);
        [, $aset] = $this->terimaAset($group, ['cara_perolehan' => 'hibah', 'vendor_id' => null]);

        $pratinjau = $this->pratinjauKoreksi($aset, 510000)->assertOk()->json('data.posting');
        $this->assertSame('held', $pratinjau['status']);
        $this->assertContains('ACCOUNT_NOT_MAPPED', array_column($pratinjau['problems'], 'code'));

        // Pemetaan kosong tidak menahan koreksinya (K-18): posting terbit `held`, register tetap berubah.
        $this->koreksi($aset, 510000)->assertOk()->assertJsonPath('data.adjustment.posting.status', 'held');
        $this->assertSame('510000.00', (string) DB::table('aset_tr_aset')->where('id', $aset)->value('acquisition_value'));
    }

    public function test_an_asset_with_depreciation_periods_still_refuses_value_changes(): void
    {
        $group = $this->groupTerpetakan('KENDARAAN', 'Kendaraan');
        [, $aset] = $this->terimaAset($group);
        $buku = DB::table('aset_tr_buku_aset')->where('aset_id', $aset)->first(['id', 'tenant_id']);
        DB::table('aset_tr_penyusutan_aset')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $buku->tenant_id, 'buku_aset_id' => $buku->id, 'legal_entity_id' => $this->le,
            'usage_org_unit_id' => $this->poli, 'period_starts_on' => '2026-09-01', 'period_ends_on' => '2026-09-30',
            'amount' => 1000, 'status' => 'proposed', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->koreksi($aset, 510000)->assertStatus(409);
        $this->pratinjauKoreksi($aset, 510000)->assertOk()
            ->assertJsonPath('data.blockers.0.message', 'Nilai perolehan dan residu tidak dapat diubah setelah ada periode penyusutan. Balikkan periodenya terlebih dahulu.')
            ->assertJsonPath('data.posting', null);
        $this->assertSame(0, FinancePosting::query()->where('posting_type', 'asset.acquisition_adjustment')->count());
    }

    public function test_the_preview_needs_the_update_permission_and_stays_in_the_tenant(): void
    {
        $group = $this->groupTerpetakan('KENDARAAN', 'Kendaraan');
        [, $aset] = $this->terimaAset($group);

        $this->sebagaiPengguna($this->tenantId, ['management-aset.aset.read'])
            ->getJson(self::API.'aset/'.$aset.'/pratinjau-koreksi?'.http_build_query(['acquisition_value' => 510000, 'adjustment_date' => $this->hariIni()]))
            ->assertForbidden();
        $this->sebagaiPengguna((string) Str::ulid(), ['management-aset.aset.update'])
            ->getJson(self::API.'aset/'.$aset.'/pratinjau-koreksi?'.http_build_query(['acquisition_value' => 510000, 'adjustment_date' => $this->hariIni()]))
            ->assertNotFound();
    }

    /** Group dengan satu buku yang di-post, dipetakan ke seluruh akun perolehan dan lawannya. */
    private function groupTerpetakan(string $kode, string $nama, bool $tanpaLawan = false): string
    {
        $group = $this->group($kode, $nama);
        $this->petakan($group, [
            'acquisition_account_id' => $this->akun['kendaraan'],
            'payable_account_id' => $this->akun['hutang'],
            'clearing_account_id' => $this->akun['perantara'],
            'opening_balance_offset_account_id' => $this->akun['penyeimbang'],
            ...($tanpaLawan ? [] : ['grant_offset_account_id' => $this->akun['hibah']]),
        ]);

        return $group;
    }

    /** Business unit baru langsung di bawah entitas legal, lewat versi hierarki manajemen berikutnya. */
    private function businessUnitBaru(string $nama, string $nomor): string
    {
        $id = $this->organisasi(['classification' => 'operating_unit', 'name' => $nama, 'operating_unit_type' => 'business_unit', 'operating_unit_number' => $nomor]);
        $terbit = OrganizationHierarchyVersion::query()->where('status', 'published')
            ->whereHas('hierarchy', fn ($query) => $query->where('name', 'Struktur manajemen'))->firstOrFail();
        $this->actingAs($this->owner)->post("/settings/organization/hierarchy-versions/{$terbit->id}/drafts", ['effective_from' => '2026-06-01'])->assertSessionHasNoErrors();
        $draf = OrganizationHierarchyVersion::query()->where('status', 'draft')->where('hierarchy_id', $terbit->hierarchy_id)->firstOrFail();
        $this->post("/settings/organization/hierarchy-versions/{$draf->id}/placements", ['organization_id' => $id, 'parent_organization_id' => $this->le])->assertSessionHasNoErrors();
        $this->post("/settings/organization/hierarchy-versions/{$draf->id}/publish")->assertSessionHasNoErrors();

        return $id;
    }

    /**
     * Menerima satu aset bernilai `$nilai` lewat penerimaan yang diselesaikan.
     *
     * @param  array<string, mixed>  $header
     * @return array{0: string, 1: string} id penerimaan, id aset
     */
    private function terimaAset(string $group, array $header = [], int|string $nilai = 500000, bool $saldoAwal = false): array
    {
        $baris = $this->baris($group, 1, $nilai);
        if ($saldoAwal) {
            $baris = [...$baris, 'akumulasi_per_unit' => '0', 'periode_berjalan' => 0, 'saldo_awal_buku' => []];
        }
        $penerimaan = $this->draf(['tanggal' => '2026-09-01', 'tanggal_siap_pakai' => '2026-09-01', ...$header], [$baris]);
        $this->selesaikan($penerimaan)->assertOk();

        return [$penerimaan, (string) DB::table('aset_tr_aset')->where('penerimaan_aset_id', $penerimaan)->value('id')];
    }

    /** @return TestResponse<Response> */
    private function koreksi(string $aset, int|string $nilai, ?string $alasan = self::ALASAN, ?string $tanggal = 'hari-ini'): TestResponse
    {
        return $this->sebagaiPengguna($this->tenantId, ['management-aset.aset.update'])
            ->patchJson(self::API.'aset/'.$aset, array_filter([
                'acquisition_value' => $nilai,
                'reason' => $alasan,
                'adjustment_date' => $tanggal === 'hari-ini' ? $this->hariIni() : $tanggal,
            ], static fn ($nilai): bool => $nilai !== null));
    }

    /** @return TestResponse<Response> */
    private function pratinjauKoreksi(string $aset, int|string $nilai): TestResponse
    {
        return $this->sebagaiPengguna($this->tenantId, ['management-aset.aset.update'])
            ->getJson(self::API.'aset/'.$aset.'/pratinjau-koreksi?'.http_build_query([
                'acquisition_value' => $nilai, 'adjustment_date' => $this->hariIni(), 'reason' => self::ALASAN,
            ]));
    }

    private function hariIni(): string
    {
        return now()->toDateString();
    }

    private function kodeAset(string $aset): string
    {
        return (string) DB::table('aset_tr_aset')->where('id', $aset)->value('kode');
    }

    /** @return array<string, mixed> */
    private function payload(string $postingId): array
    {
        return FinancePosting::query()->where('posting_id', $postingId)->firstOrFail()->payload;
    }

    /**
     * Baris jurnal payload sebagai [kode akun, debit, kredit, [KODE:nilai dimensi]].
     *
     * @param  array<string, mixed>  $payload
     * @return list<array{0: string, 1: string, 2: string, 3: list<string>}>
     */
    private function barisJurnal(array $payload): array
    {
        return array_values(array_map(static fn (array $baris): array => [
            (string) $baris['account']['code'],
            (string) $baris['debit'],
            (string) $baris['credit'],
            array_values(array_map(static fn (array $dimensi): string => $dimensi['code'].':'.$dimensi['value_code'], $baris['financial_dimensions'])),
        ], $payload['journal_lines']));
    }
}
