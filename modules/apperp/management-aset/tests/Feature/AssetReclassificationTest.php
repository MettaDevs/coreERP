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
 * Dokumen reklasifikasi aset — pindah group aset dan pecah aset, padanan FA Reclass. Journal BC: draf,
 * pratinjau, posting yang memindah saldo setiap buku, jurnal `asset.reclassification` bila group berubah, dan
 * penyusutan berikutnya yang melanjutkan umur yang sudah berjalan.
 */
class AssetReclassificationTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, CocokDenganKontrak, MenerbitkanJurnalPenerimaan, MenyiapkanNilaiBukuAset, RefreshDatabase;

    private const SEMUA_IZIN = [
        'management-aset.reklasifikasi-aset.read',
        'management-aset.reklasifikasi-aset.create',
        'management-aset.reklasifikasi-aset.update',
        'management-aset.reklasifikasi-aset.archive',
        'management-aset.reklasifikasi-aset.post',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanNilaiBukuAset();
    }

    public function test_moving_an_asset_to_another_group_moves_every_balance_between_the_group_accounts(): void
    {
        [$kendaraan, $komersial, $fiskal] = $this->groupLengkap('KENDARAAN', 'Kendaraan');
        $alkes = $this->groupBukuSama('ALKES', 'Alat kesehatan', $komersial, $fiskal);
        $aset = $this->terimaSatu($kendaraan);
        $this->susutkan('2026-10-01', '2026-10-31');
        $this->turunkanNilai($aset, $komersial, '2026-10-31', 2000000);

        $id = $this->drafReklas('pindah_group', '2026-10-31', [['aset_id' => $aset, 'group_aset_tujuan_id' => $alkes]]);
        $pratinjau = $this->pratinjauReklas($id)->assertOk()->json('data');
        $this->assertSame([], $pratinjau['blockers']);
        $this->assertSame(['AST-RCL-'.$id, 'pending', '51000000.00'], [$pratinjau['posting']['posting_id'], $pratinjau['posting']['status'], $pratinjau['posting']['total']]);
        $this->assertSame(0, FinancePosting::query()->where('posting_id', 'AST-RCL-'.$id)->count());

        $jawab = $this->postingReklas($id)->assertOk();
        $this->assertSame(['posted', 'AST-RCL-'.$id, 'pending'], [$jawab->json('data.status'), $jawab->json('data.posting_id'), $jawab->json('data.posting.status')]);

        $payload = $this->payloadPosting('AST-RCL-'.$id);
        $this->assertCocokSkema($payload, 'FinancePosting');
        $this->assertSame('asset.reclassification', $payload['posting_type']);
        $this->assertSame(['2026-10-31', '2026-10-31'], [$payload['posting_date'], $payload['document_date']]);
        $this->assertSame([
            ['1-2400', '48000000.00', '0.00'],
            ['1-2390', '1000000.00', '0.00'],
            ['1-2395', '2000000.00', '0.00'],
            ['1-2490', '0.00', '1000000.00'],
            ['1-2495', '0.00', '2000000.00'],
            ['1-2300', '0.00', '48000000.00'],
        ], array_map(static fn (array $baris): array => array_slice($baris, 0, 3), $this->jurnal($payload)));
        $this->assertSame(['KENDARAAN', 'ALKES', '48000000.00'], [
            $payload['details']['assets'][0]['from_group'],
            $payload['details']['assets'][0]['to_group'],
            $payload['details']['assets'][0]['acquisition_value'],
        ]);

        // Aset yang sama pindah group; saldonya tidak berubah, pemindahannya tercatat per buku.
        $this->assertSame($alkes, DB::table('aset_tr_aset')->where('id', $aset)->value('group_aset_id'));
        $this->assertSame(['48000000.00', '1000000.00', '2000000.00', '0.00', '45000000.00', 'active'], array_values($this->buku($aset, $komersial)));
        $this->assertEqualsCanonicalizing([[$komersial, true], [$fiskal, false]], DB::table('aset_tr_reklasifikasi_aset_buku')->where('reklasifikasi_aset_id', $id)
            ->get(['buku_id', 'dijurnal'])->map(static fn ($row): array => [$row->buku_id, (bool) $row->dijurnal])->all());
        $this->assertSame($kendaraan, DB::table('aset_tr_reklasifikasi_aset_details')->where('reklasifikasi_aset_id', $id)->value('group_aset_asal_id'));

        // Terkunci sesudah diposting.
        $this->postingReklas($id)->assertUnprocessable();
    }

    public function test_splitting_by_percentage_within_a_group_moves_every_book_proportionally_without_a_journal(): void
    {
        [$kendaraan, $komersial, $fiskal] = $this->groupLengkap('KENDARAAN', 'Kendaraan');
        $aset = $this->terimaSatu($kendaraan);
        $this->susutkan('2026-10-01', '2026-10-31');

        $id = $this->drafReklas('pecah', '2026-10-31', [['aset_id' => $aset, 'persen' => 25, 'nama_aset_baru' => 'Ambulans — kotak medis']]);
        $this->assertStringContainsString('tidak ada jurnal', (string) $this->pratinjauReklas($id)->assertOk()->json('data.note'));
        $this->assertSame('12000000.00', $this->pratinjauReklas($id)->json('data.moves.0.books.0.nilai_perolehan'));

        $jawab = $this->postingReklas($id)->assertOk()->assertJsonPath('data.posting', null)->assertJsonPath('data.posting_id', null);
        $baru = (string) $jawab->json('data.details.0.aset_baru_id');
        $this->assertNotSame('', (string) $jawab->json('data.details.0.aset_baru_kode'));
        $this->assertSame(0, FinancePosting::query()->where('posting_type', 'asset.reclassification')->count());

        // Seperempat setiap saldo setiap buku pindah ke aset baru, termasuk umur yang sudah berjalan.
        $this->assertSame(['36000000.00', '750000.00', '0.00', '0.00', '35250000.00', 'active'], array_values($this->buku($aset, $komersial)));
        $this->assertSame(['12000000.00', '250000.00', '0.00', '0.00', '11750000.00', 'active'], array_values($this->buku($baru, $komersial)));
        $this->assertSame('11750000.00', $this->buku($baru, $fiskal)['net_book_value']);
        $this->assertSame(1, (int) DB::table('aset_tr_buku_aset')->where(['aset_id' => $baru, 'buku_id' => $komersial])->value('elapsed_periods_offset'));
        $this->assertSame(['36000000.00', '12000000.00'], [
            (string) DB::table('aset_tr_aset')->where('id', $aset)->value('acquisition_value'),
            (string) DB::table('aset_tr_aset')->where('id', $baru)->value('acquisition_value'),
        ]);
        $asetBaru = DB::table('aset_tr_aset')->where('id', $baru)->first();
        $this->assertSame(['Ambulans — kotak medis', $kendaraan, null], [$asetBaru->nama, $asetBaru->group_aset_id, $asetBaru->serial_number]);
        $this->assertSame($this->poli, DB::table('aset_tr_penempatan_aset')->where('aset_id', $baru)->value('usage_org_unit_id'));

        // Penyusutan berikutnya: 36 juta / 48 + 12 juta / 48 = 1 juta, sama dengan sebelum dipecah.
        $this->usulkanPeriode('2026-11-01', '2026-11-30');
        $november = static fn (string $asetId): string => (string) DB::table('aset_tr_penyusutan_aset as p')
            ->join('aset_tr_buku_aset as b', 'b.id', '=', 'p.buku_aset_id')
            ->where(['b.aset_id' => $asetId, 'b.buku_id' => $komersial])->where('p.period_ends_on', '2026-11-30')->value('p.amount');
        $this->assertSame(['750000.00', '250000.00'], [$november($aset), $november($baru)]);
    }

    public function test_splitting_an_amount_into_another_group_journals_only_the_moved_part(): void
    {
        [$kendaraan, $komersial, $fiskal] = $this->groupLengkap('KENDARAAN', 'Kendaraan');
        $alkes = $this->groupBukuSama('ALKES', 'Alat kesehatan', $komersial, $fiskal);
        $aset = $this->terimaSatu($kendaraan);
        $this->susutkan('2026-10-01', '2026-10-31');

        $id = $this->drafReklas('pecah', '2026-10-31', [
            ['aset_id' => $aset, 'nilai_perolehan' => 12000000, 'group_aset_tujuan_id' => $alkes],
            ['aset_id' => $aset, 'persen' => 10],
        ]);
        $this->postingReklas($id)->assertOk();

        $this->assertSame([
            ['1-2400', '12000000.00', '0.00'],
            ['1-2390', '250000.00', '0.00'],
            ['1-2490', '0.00', '250000.00'],
            ['1-2300', '0.00', '12000000.00'],
        ], array_map(static fn (array $baris): array => array_slice($baris, 0, 3), $this->jurnal($this->payloadPosting('AST-RCL-'.$id))));
        // Dua baris dihitung dari saldo sebelum diposting: 25% dan 10% dari 48 juta.
        $this->assertSame(['31200000.00', '650000.00'], [$this->buku($aset, $komersial)['acquisition_value'], $this->buku($aset, $komersial)['accumulated_depreciation']]);
        $baru = DB::table('aset_tr_reklasifikasi_aset_details')->where('reklasifikasi_aset_id', $id)->orderBy('line_number')->pluck('aset_baru_id')->all();
        $this->assertSame([$alkes, $kendaraan], DB::table('aset_tr_aset')->whereIn('id', $baru)->orderByRaw('array_position(?::text[], id::text)', ['{'.implode(',', $baru).'}'])->pluck('group_aset_id')->all());
    }

    public function test_postings_are_refused_with_pending_depreciation_too_large_a_share_or_a_different_posted_book(): void
    {
        [$kendaraan, $komersial] = $this->groupLengkap('KENDARAAN', 'Kendaraan');
        [$gedung] = $this->groupLengkap('GEDUNG', 'Gedung');
        $aset = $this->terimaSatu($kendaraan);

        $beda = $this->drafReklas('pindah_group', '2026-09-30', [['aset_id' => $aset, 'group_aset_tujuan_id' => $gedung]]);
        $this->postingReklas($beda)->assertUnprocessable()->assertJsonValidationErrors(['details.0.group_aset_tujuan_id' => 'buku yang sama']);
        $sama = $this->drafReklas('pindah_group', '2026-09-30', [['aset_id' => $aset, 'group_aset_tujuan_id' => $kendaraan]]);
        $this->postingReklas($sama)->assertUnprocessable()->assertJsonValidationErrors(['details.0.group_aset_tujuan_id' => 'sudah berada']);

        $terlalu = $this->drafReklas('pecah', '2026-09-30', [['aset_id' => $aset, 'persen' => 60], ['aset_id' => $aset, 'nilai_perolehan' => 19200000]]);
        $this->postingReklas($terlalu)->assertUnprocessable()->assertJsonValidationErrors(['details.1.nilai_perolehan' => '100%']);

        $this->usulkanPeriode('2026-10-01', '2026-10-31');
        $tertahan = $this->drafReklas('pecah', '2026-10-31', [['aset_id' => $aset, 'persen' => 10]]);
        $this->pratinjauReklas($tertahan)->assertOk()->assertJsonPath('data.blockers.0.field', 'details.0.aset_id');
        $this->postingReklas($tertahan)->assertUnprocessable()->assertJsonValidationErrors(['details.0.aset_id' => 'belum difinalkan']);
        $this->assertSame('48000000.00', $this->buku($aset, $komersial)['acquisition_value']);

        // Isian yang salah ditolak saat disimpan.
        $this->kirimDrafReklas('pecah', '2026-10-31', [['aset_id' => $aset, 'persen' => 10, 'nilai_perolehan' => 1000]])
            ->assertUnprocessable()->assertJsonValidationErrors(['details.0.persen' => 'salah satu']);
        $this->kirimDrafReklas('pindah_group', '2026-10-31', [['aset_id' => $aset, 'group_aset_tujuan_id' => $gedung], ['aset_id' => $aset, 'group_aset_tujuan_id' => $gedung]])
            ->assertUnprocessable()->assertJsonValidationErrors(['details' => 'sekali']);
    }

    public function test_drafts_are_edited_archived_and_guarded_by_their_permissions(): void
    {
        [$kendaraan] = $this->groupLengkap('KENDARAAN', 'Kendaraan');
        $aset = $this->terimaSatu($kendaraan);
        $id = $this->drafReklas('pecah', '2026-09-30', [['aset_id' => $aset, 'persen' => 10], ['aset_id' => $aset, 'persen' => 20]]);
        $baris = $this->lihatReklas($id)->json('data.details');

        // Baris yang dikirim dengan id-nya tetap bernomor sama; yang tidak dikirim diarsipkan.
        $this->ubahReklas($id, ['details' => [['id' => $baris[1]['id'], 'aset_id' => $aset, 'persen' => 30]]])->assertOk()
            ->assertJsonPath('data.details.0.line_number', 2)->assertJsonPath('data.details.0.persen', '30.000000');
        $this->ubahReklas($id, ['jenis' => 'pindah_group', 'details' => [['aset_id' => $aset, 'group_aset_tujuan_id' => $kendaraan]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['jenis']);

        $this->sebagaiPengguna($this->tenantId, ['management-aset.reklasifikasi-aset.update'])
            ->postJson(self::API.'reklasifikasi-aset/'.$id.'/posting', ['version' => $this->versiReklas($id)])->assertForbidden();
        $this->sebagaiPengguna((string) Str::ulid(), self::SEMUA_IZIN)->getJson(self::API.'reklasifikasi-aset/'.$id)->assertNotFound();

        $this->sebagaiPengguna($this->tenantId, self::SEMUA_IZIN)
            ->deleteJson(self::API.'reklasifikasi-aset/'.$id, ['version' => $this->versiReklas($id)])->assertNoContent();
        $this->sebagaiPengguna($this->tenantId, self::SEMUA_IZIN)->getJson(self::API.'reklasifikasi-aset/'.$id)->assertNotFound();
        $this->assertSame([], $this->sebagaiPengguna($this->tenantId, self::SEMUA_IZIN)->getJson(self::API.'reklasifikasi-aset')->assertOk()->json('data'));
    }

    /** @param  list<array<string, mixed>>  $baris */
    private function drafReklas(string $jenis, string $tanggal, array $baris): string
    {
        return (string) $this->kirimDrafReklas($jenis, $tanggal, $baris)->assertCreated()->json('data.id');
    }

    /**
     * @param  list<array<string, mixed>>  $baris
     * @return TestResponse<Response>
     */
    private function kirimDrafReklas(string $jenis, string $tanggal, array $baris): TestResponse
    {
        return $this->sebagaiPengguna($this->tenantId, self::SEMUA_IZIN)
            ->withHeader('Idempotency-Key', 'rkla-'.Str::ulid())
            ->postJson(self::API.'reklasifikasi-aset', [
                'legal_entity_id' => $this->le,
                'responsible_org_unit_id' => $this->poli,
                'jenis' => $jenis,
                'tanggal' => $tanggal,
                'keterangan' => 'Pemisahan komponen aset',
                'details' => $baris,
            ]);
    }

    /**
     * @param  array<string, mixed>  $ubahan
     * @return TestResponse<Response>
     */
    private function ubahReklas(string $id, array $ubahan): TestResponse
    {
        $sekarang = DB::table('aset_tr_reklasifikasi_aset')->where('id', $id)->first();

        return $this->sebagaiPengguna($this->tenantId, self::SEMUA_IZIN)
            ->patchJson(self::API.'reklasifikasi-aset/'.$id, [
                'legal_entity_id' => $sekarang->legal_entity_id,
                'responsible_org_unit_id' => $sekarang->responsible_org_unit_id,
                'jenis' => $sekarang->jenis,
                'tanggal' => substr((string) $sekarang->tanggal, 0, 10),
                'keterangan' => $sekarang->keterangan,
                'version' => $sekarang->version,
                ...$ubahan,
            ]);
    }

    /** @return TestResponse<Response> */
    private function lihatReklas(string $id): TestResponse
    {
        return $this->sebagaiPengguna($this->tenantId, self::SEMUA_IZIN)->getJson(self::API.'reklasifikasi-aset/'.$id)->assertOk();
    }

    /** @return TestResponse<Response> */
    private function pratinjauReklas(string $id): TestResponse
    {
        return $this->sebagaiPengguna($this->tenantId, ['management-aset.reklasifikasi-aset.post'])
            ->getJson(self::API.'reklasifikasi-aset/'.$id.'/pratinjau-posting');
    }

    /** @return TestResponse<Response> */
    private function postingReklas(string $id): TestResponse
    {
        return $this->sebagaiPengguna($this->tenantId, ['management-aset.reklasifikasi-aset.post'])
            ->postJson(self::API.'reklasifikasi-aset/'.$id.'/posting', ['version' => $this->versiReklas($id)]);
    }

    private function versiReklas(string $id): int
    {
        return (int) DB::table('aset_tr_reklasifikasi_aset')->where('id', $id)->value('version');
    }
}
