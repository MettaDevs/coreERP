<?php

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use App\Foundation\FinancePosting\Models\FinancePosting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Apperp\ManagementAset\Tests\Concerns\BerinteraksiDenganKonteksCore;
use Modules\Apperp\ManagementAset\Tests\Concerns\MenerbitkanJurnalPenerimaan;
use Modules\Apperp\ManagementAset\Tests\Concerns\MenyiapkanNilaiBukuAset;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\CocokDenganKontrak;
use Tests\TestCase;

/**
 * Dokumen penyesuaian nilai aset — penurunan nilai (`asset.write_down`) dan kenaikan nilai
 * (`asset.appreciation`): draf, pratinjau, posting yang mengubah nilai buku dan menerbitkan jurnalnya,
 * penyusutan berikutnya yang membagi nilai buku baru ke sisa masa manfaat, dan pelepasan yang membaliknya.
 */
class AssetValueAdjustmentTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, CocokDenganKontrak, MenerbitkanJurnalPenerimaan, MenyiapkanNilaiBukuAset, RefreshDatabase;

    private const SEMUA_IZIN = [
        'management-aset.penyesuaian-nilai-aset.read',
        'management-aset.penyesuaian-nilai-aset.create',
        'management-aset.penyesuaian-nilai-aset.update',
        'management-aset.penyesuaian-nilai-aset.archive',
        'management-aset.penyesuaian-nilai-aset.post',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanNilaiBukuAset();
    }

    public function test_posting_a_write_down_lowers_the_book_value_and_publishes_its_journal(): void
    {
        [$group, $komersial, $fiskal] = $this->groupLengkap('KENDARAAN', 'Kendaraan');
        $aset = $this->terimaSatu($group);
        $this->susutkan('2026-10-01', '2026-10-31');

        $id = $this->drafPenyesuaian('write_down', $komersial, '2026-10-31', [[$aset, 11000000]]);
        $this->assertSame('47000000.00', $this->lihat($id)->json('data.details.0.nilai_buku_sebelum'));
        $this->assertSame('36000000.00', $this->lihat($id)->json('data.details.0.nilai_buku_sesudah'));

        $pratinjau = $this->pratinjau($id)->assertOk()->json('data');
        $this->assertSame([], $pratinjau['blockers']);
        $this->assertSame(['AST-WDN-'.$id, 'pending', '11000000.00'], [$pratinjau['posting']['posting_id'], $pratinjau['posting']['status'], $pratinjau['posting']['total']]);
        $this->assertSame(0, FinancePosting::query()->where('posting_id', 'AST-WDN-'.$id)->count());

        $jawab = $this->posting($id)->assertOk();
        $this->assertSame(['posted', 'AST-WDN-'.$id], [$jawab->json('data.status'), $jawab->json('data.posting_id')]);
        $this->assertSame(['posting_id' => 'AST-WDN-'.$id, 'status' => 'pending'], $jawab->json('data.posting'));

        $payload = $this->payloadPosting('AST-WDN-'.$id);
        $this->assertCocokSkema($payload, 'FinancePosting');
        $this->assertSame('asset.write_down', $payload['posting_type']);
        $this->assertSame(['2026-10-31', '2026-10-31'], [$payload['posting_date'], $payload['document_date']]);
        $this->assertSame([
            ['6-5200', '11000000.00', '0.00', ['BUSINESS_UNIT:KLN-A', 'DEPARTMENT:POLI-UMUM']],
            ['1-2395', '0.00', '11000000.00', ['BUSINESS_UNIT:KLN-A']],
        ], $this->jurnal($payload));
        $this->assertSame(['47000000.00', '36000000.00', '11000000.00'], [
            $payload['details']['assets'][0]['net_book_value_before'],
            $payload['details']['assets'][0]['net_book_value_after'],
            $payload['details']['assets'][0]['adjustment_amount'],
        ]);

        // Buku komersial turun; buku fiskal tidak tersentuh.
        $this->assertSame(['48000000.00', '1000000.00', '11000000.00', '0.00', '36000000.00', 'active'], array_values($this->buku($aset, $komersial)));
        $this->assertSame('47000000.00', $this->buku($aset, $fiskal)['net_book_value']);
        // Nilai sebelum dan sesudah dibekukan di baris; dokumen terkunci.
        $this->assertSame(['47000000.00', '36000000.00'], [$jawab->json('data.details.0.nilai_buku_sebelum'), $jawab->json('data.details.0.nilai_buku_sesudah')]);
        $this->ubah($id, ['keterangan' => 'Ganti'])->assertUnprocessable();
        $this->posting($id)->assertUnprocessable();
    }

    public function test_the_next_straight_line_period_spreads_the_new_book_value_over_the_remaining_life(): void
    {
        [$group, $komersial, $fiskal] = $this->groupLengkap('KENDARAAN', 'Kendaraan');
        $aset = $this->terimaSatu($group);
        $this->susutkan('2026-10-01', '2026-10-31');
        $this->posting($this->drafPenyesuaian('write_down', $komersial, '2026-10-31', [[$aset, 11000000]]))->assertOk();

        $this->usulkanPeriode('2026-11-01', '2026-11-30');

        // 36.000.000 dibagi sisa 47 bulan, bukan 48.000.000 / 48 (IAS 36 ¶63). Buku fiskal tetap 1.000.000.
        $november = fn (string $buku): string => (string) DB::table('aset_tr_penyusutan_aset as p')
            ->join('aset_tr_buku_aset as b', 'b.id', '=', 'p.buku_aset_id')
            ->where('b.buku_id', $buku)->where('p.period_ends_on', '2026-11-30')->value('p.amount');
        $this->assertSame('765957.45', $november($komersial));
        $this->assertSame('1000000.00', $november($fiskal));
    }

    public function test_appreciation_raises_the_book_value_against_the_revaluation_offset(): void
    {
        [$group, $komersial] = $this->groupLengkap('KENDARAAN', 'Kendaraan');
        $aset = $this->terimaSatu($group);

        $id = $this->drafPenyesuaian('appreciation', $komersial, '2026-09-30', [[$aset, 2000000]]);
        $this->posting($id)->assertOk();

        $payload = $this->payloadPosting('AST-APR-'.$id);
        $this->assertSame('asset.appreciation', $payload['posting_type']);
        $this->assertSame([
            ['1-2310', '2000000.00', '0.00', ['BUSINESS_UNIT:KLN-A']],
            ['3-4100', '0.00', '2000000.00', ['BUSINESS_UNIT:KLN-A']],
        ], $this->jurnal($payload));
        $this->assertSame(['2000000.00', '50000000.00'], [$this->buku($aset, $komersial)['appreciation_amount'], $this->buku($aset, $komersial)['net_book_value']]);
    }

    public function test_a_book_that_is_not_posted_changes_only_the_register(): void
    {
        [$group, , $fiskal] = $this->groupLengkap('KENDARAAN', 'Kendaraan');
        $aset = $this->terimaSatu($group);

        $id = $this->drafPenyesuaian('write_down', $fiskal, '2026-09-30', [[$aset, 8000000]]);
        $this->assertStringContainsString('hanya mengubah nilai buku', (string) $this->pratinjau($id)->assertOk()->json('data.note'));
        $this->posting($id)->assertOk()->assertJsonPath('data.posting', null)->assertJsonPath('data.posting_id', null);

        $this->assertSame(0, FinancePosting::query()->where('posting_type', 'asset.write_down')->count());
        $this->assertSame('40000000.00', $this->buku($aset, $fiskal)['net_book_value']);
    }

    public function test_postings_are_refused_beyond_the_book_value_with_pending_depreciation_or_after_disposal(): void
    {
        [$group, $komersial] = $this->groupLengkap('KENDARAAN', 'Kendaraan');
        $aset = $this->terimaSatu($group);

        $terlalu = $this->drafPenyesuaian('write_down', $komersial, '2026-09-30', [[$aset, 48000001]]);
        $this->posting($terlalu)->assertUnprocessable()->assertJsonValidationErrors(['details.0.aset_id' => 'melebihi nilai bukunya']);

        $this->usulkanPeriode('2026-10-01', '2026-10-31');
        $tertahan = $this->drafPenyesuaian('write_down', $komersial, '2026-10-31', [[$aset, 1000000]]);
        $this->pratinjau($tertahan)->assertOk()->assertJsonPath('data.blockers.0.field', 'details.0.aset_id');
        $this->posting($tertahan)->assertUnprocessable()->assertJsonValidationErrors(['details.0.aset_id' => 'belum difinalkan']);
        $this->assertSame('48000000.00', $this->buku($aset, $komersial)['net_book_value']);

        // Aset yang sudah dilepas tidak dapat masuk dokumen baru.
        $lain = $this->terimaSatu($group);
        $this->hentikan($lain);
        $this->lepas('pemusnahan-aset', $lain, '2026-09-30')->assertOk();
        $this->kirimDrafPenyesuaian('write_down', $komersial, '2026-09-30', [[$lain, 1000]])
            ->assertUnprocessable()->assertJsonValidationErrors(['details' => 'sudah dilepas']);
    }

    public function test_disposal_reverses_the_recorded_write_down_and_appreciation(): void
    {
        [$group, $komersial] = $this->groupLengkap('KENDARAAN', 'Kendaraan');
        $aset = $this->terimaSatu($group);
        $this->posting($this->drafPenyesuaian('write_down', $komersial, '2026-09-30', [[$aset, 10000000]]))->assertOk();
        $this->posting($this->drafPenyesuaian('appreciation', $komersial, '2026-09-30', [[$aset, 4000000]]))->assertOk();
        $this->hentikan($aset);

        // Nilai buku 48 − 10 + 4 = 42 juta, dijual 40 juta: rugi 2 juta.
        $this->lepas('penjualan-aset', $aset, '2026-09-30', 40000000)->assertOk();

        $this->assertSame([
            ['1-2395', '10000000.00', '0.00', ['BUSINESS_UNIT:KLN-A']],
            ['1-2300', '0.00', '48000000.00', ['BUSINESS_UNIT:KLN-A']],
            ['1-2310', '0.00', '4000000.00', ['BUSINESS_UNIT:KLN-A']],
            ['1-1300', '40000000.00', '0.00', ['BUSINESS_UNIT:KLN-A']],
            ['8-1100', '2000000.00', '0.00', ['BUSINESS_UNIT:KLN-A', 'DEPARTMENT:POLI-UMUM']],
        ], $this->jurnal($this->payloadPosting('AST-DSP-'.$aset)));
    }

    public function test_an_unmapped_write_down_holds_the_posting_but_the_book_value_still_changes(): void
    {
        [$group, $komersial] = $this->groupLengkap('KENDARAAN', 'Kendaraan', ['write_down_expense_account_id']);
        $aset = $this->terimaSatu($group);

        $id = $this->drafPenyesuaian('write_down', $komersial, '2026-09-30', [[$aset, 3000000]]);
        $this->posting($id)->assertOk()->assertJsonPath('data.posting.status', 'held');

        $posting = FinancePosting::query()->where('posting_id', 'AST-WDN-'.$id)->firstOrFail();
        $this->assertStringContainsString('beban penurunan nilai', $posting->hold_reasons[0]['message']);
        $this->assertSame('45000000.00', $this->buku($aset, $komersial)['net_book_value']);
    }

    public function test_a_later_acquisition_correction_keeps_the_write_down_in_the_book_value(): void
    {
        [$group, $komersial] = $this->groupLengkap('KENDARAAN', 'Kendaraan');
        $aset = $this->terimaSatu($group);
        $this->posting($this->drafPenyesuaian('write_down', $komersial, '2026-09-30', [[$aset, 5000000]]))->assertOk();

        $this->sebagaiPengguna($this->tenantId, ['management-aset.aset.update'])
            ->patchJson(self::API.'aset/'.$aset, [
                'version' => DB::table('aset_tr_aset')->where('id', $aset)->value('version'),
                'acquisition_value' => 50000000, 'reason' => 'Faktur ternyata 50 juta', 'adjustment_date' => now()->toDateString(),
            ])->assertOk();

        $this->assertSame(['50000000.00', '5000000.00', '45000000.00'], [
            $this->buku($aset, $komersial)['acquisition_value'],
            $this->buku($aset, $komersial)['write_down_amount'],
            $this->buku($aset, $komersial)['net_book_value'],
        ]);
    }

    public function test_drafts_are_edited_line_by_line_archived_and_guarded_by_their_permissions(): void
    {
        [$group, $komersial] = $this->groupLengkap('KENDARAAN', 'Kendaraan');
        $satu = $this->terimaSatu($group);
        $dua = $this->terimaSatu($group);
        $id = $this->drafPenyesuaian('write_down', $komersial, '2026-09-30', [[$satu, 1000], [$dua, 2000]]);

        // Baris yang tidak dikirim lagi diarsipkan; baris yang tetap mempertahankan nomornya.
        $this->ubah($id, ['details' => [['aset_id' => $dua, 'nilai' => 2500]]])->assertOk()
            ->assertJsonPath('data.details.0.line_number', 2)->assertJsonPath('data.details.0.nilai', '2500.00');
        $this->assertCount(1, $this->lihat($id)->json('data.details'));

        // Menyusun tidak berarti boleh memposting.
        $this->sebagaiPengguna($this->tenantId, ['management-aset.penyesuaian-nilai-aset.update'])
            ->postJson(self::API.'penyesuaian-nilai-aset/'.$id.'/posting', ['version' => $this->versi($id)])->assertForbidden();
        $this->sebagaiPengguna($this->tenantId, ['management-aset.penyesuaian-nilai-aset.read'])
            ->getJson(self::API.'penyesuaian-nilai-aset/'.$id.'/pratinjau-posting')->assertForbidden();
        $this->sebagaiPengguna((string) Str::ulid(), self::SEMUA_IZIN)
            ->getJson(self::API.'penyesuaian-nilai-aset/'.$id)->assertNotFound();

        $this->sebagaiPengguna($this->tenantId, self::SEMUA_IZIN)
            ->deleteJson(self::API.'penyesuaian-nilai-aset/'.$id, ['version' => $this->versi($id)])->assertNoContent();
        $this->lihat($id, false)->assertNotFound();
        $this->assertNotNull(DB::table('aset_tr_penyesuaian_nilai_aset')->where('id', $id)->value('deleted_at'));
        $this->assertSame([], $this->sebagaiPengguna($this->tenantId, self::SEMUA_IZIN)->getJson(self::API.'penyesuaian-nilai-aset')->assertOk()->json('data'));
    }

    /**
     * @param  list<array{0: string, 1: int|string}>  $baris
     */
    private function drafPenyesuaian(string $jenis, string $buku, string $tanggal, array $baris): string
    {
        return (string) $this->kirimDrafPenyesuaian($jenis, $buku, $tanggal, $baris)->assertCreated()->json('data.id');
    }

    /**
     * @param  list<array{0: string, 1: int|string}>  $baris
     * @return TestResponse<Response>
     */
    private function kirimDrafPenyesuaian(string $jenis, string $buku, string $tanggal, array $baris): TestResponse
    {
        return $this->sebagaiPengguna($this->tenantId, self::SEMUA_IZIN)
            ->withHeader('Idempotency-Key', 'pnla-'.Str::ulid())
            ->postJson(self::API.'penyesuaian-nilai-aset', [
                'legal_entity_id' => $this->le,
                'responsible_org_unit_id' => $this->poli,
                'jenis' => $jenis,
                'buku_id' => $buku,
                'tanggal' => $tanggal,
                'keterangan' => 'Uji penurunan nilai PSAK 48',
                'details' => array_map(static fn (array $row): array => ['aset_id' => $row[0], 'nilai' => $row[1]], $baris),
            ]);
    }

    /**
     * @param  array<string, mixed>  $ubahan
     * @return TestResponse<Response>
     */
    private function ubah(string $id, array $ubahan): TestResponse
    {
        $sekarang = DB::table('aset_tr_penyesuaian_nilai_aset')->where('id', $id)->first();
        $baris = DB::table('aset_tr_penyesuaian_nilai_aset_details')->where('penyesuaian_nilai_aset_id', $id)->whereNull('deleted_at')
            ->get(['aset_id', 'nilai'])->map(static fn ($row): array => ['aset_id' => $row->aset_id, 'nilai' => $row->nilai])->all();

        return $this->sebagaiPengguna($this->tenantId, self::SEMUA_IZIN)
            ->patchJson(self::API.'penyesuaian-nilai-aset/'.$id, [
                'legal_entity_id' => $sekarang->legal_entity_id,
                'responsible_org_unit_id' => $sekarang->responsible_org_unit_id,
                'jenis' => $sekarang->jenis,
                'buku_id' => $sekarang->buku_id,
                'tanggal' => substr((string) $sekarang->tanggal, 0, 10),
                'keterangan' => $sekarang->keterangan,
                'details' => $baris,
                'version' => $sekarang->version,
                ...$ubahan,
            ]);
    }

    /** @return TestResponse<Response> */
    private function lihat(string $id, bool $ada = true): TestResponse
    {
        $jawab = $this->sebagaiPengguna($this->tenantId, self::SEMUA_IZIN)->getJson(self::API.'penyesuaian-nilai-aset/'.$id);

        return $ada ? $jawab->assertOk() : $jawab;
    }

    /** @return TestResponse<Response> */
    private function pratinjau(string $id): TestResponse
    {
        return $this->sebagaiPengguna($this->tenantId, ['management-aset.penyesuaian-nilai-aset.post'])
            ->getJson(self::API.'penyesuaian-nilai-aset/'.$id.'/pratinjau-posting');
    }

    /** @return TestResponse<Response> */
    private function posting(string $id): TestResponse
    {
        return $this->sebagaiPengguna($this->tenantId, ['management-aset.penyesuaian-nilai-aset.post'])
            ->postJson(self::API.'penyesuaian-nilai-aset/'.$id.'/posting', ['version' => $this->versi($id)]);
    }

    private function versi(string $id): int
    {
        return (int) DB::table('aset_tr_penyesuaian_nilai_aset')->where('id', $id)->value('version');
    }
}
