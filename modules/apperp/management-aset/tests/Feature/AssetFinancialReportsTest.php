<?php

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use App\Foundation\FinancePosting\Models\FinancePosting;
use App\Platform\Modules\Contracts\TenantRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Apperp\ManagementAset\Reporting\PenyediaLaporan;
use Modules\Apperp\ManagementAset\Reporting\ReportRegistry;
use Modules\Apperp\ManagementAset\Tests\Concerns\BerinteraksiDenganKonteksCore;
use Modules\Apperp\ManagementAset\Tests\Concerns\MenerbitkanJurnalPenerimaan;
use Modules\Apperp\ManagementAset\Tests\Concerns\MenyiapkanNilaiBukuAset;
use Tests\TestCase;

/**
 * Laporan keuangan aset di atas satu riwayat yang sama, dibentuk lewat API seperti oleh pengguna:
 *
 * - AST satu: 48 juta (Kendaraan), disusutkan Oktober 1 juta, diturunkan nilainya 2 juta, lalu 25% dipecah ke
 *   aset tiga pada 31 Oktober;
 * - AST dua: 24 juta (Kendaraan), disusutkan Oktober 0,5 juta, lalu pindah ke group Alat kesehatan 31 Oktober;
 * - AST tiga: pecahan aset satu (12 juta, akumulasi 0,25 juta, penurunan 0,5 juta), dijual 15 November.
 *
 * Yang diuji: mutasi nilai buku per rentang dan persamaannya terhadap register, rekonsiliasi per group dan
 * akun terhadap keadaan posting di feed, proyeksi dari `DepreciationCalculator`, daftar perolehan tanpa aset
 * pecahan, dan laporan penyusutan yang ikut membaca akumulasi pecahan.
 */
class AssetFinancialReportsTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, MenerbitkanJurnalPenerimaan, MenyiapkanNilaiBukuAset, RefreshDatabase;

    private string $komersial;

    /** @var array<string, string> */
    private array $aset = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanNilaiBukuAset();

        [$kendaraan, $this->komersial, $fiskal] = $this->groupLengkap('KENDARAAN', 'Kendaraan');
        $alkes = $this->groupBukuSama('ALKES', 'Alat kesehatan', $this->komersial, $fiskal);
        $this->aset['satu'] = $this->terimaSatu($kendaraan);
        $this->aset['dua'] = $this->terimaSatu($kendaraan, 24000000);
        $this->susutkan('2026-10-01', '2026-10-31');
        $this->turunkanNilai($this->aset['satu'], $this->komersial, '2026-10-31', 2000000);
        $this->reklas('pindah_group', [['aset_id' => $this->aset['dua'], 'group_aset_tujuan_id' => $alkes]]);
        $this->aset['tiga'] = (string) $this->reklas('pecah', [['aset_id' => $this->aset['satu'], 'persen' => 25]]);
        $this->hentikan($this->aset['tiga']);
        $this->lepas('penjualan-aset', $this->aset['tiga'], '2026-11-15', 11000000)->assertOk();
    }

    public function test_book_value_report_rolls_every_movement_forward_to_the_register_balance(): void
    {
        $laporan = $this->dataset('laporan-nilai-buku-aset', ['dari' => '2026-10-01', 'sampai' => '2026-11-30']);
        $baris = $this->perKode($laporan['tables']['baris']);
        $this->assertRowKeysDeclared('laporan-nilai-buku-aset', $laporan['tables']['baris'][0]);

        $this->assertAmounts([
            'harga_perolehan_awal' => 48000000, 'perolehan' => 0, 'reklasifikasi_harga_perolehan' => -12000000, 'harga_perolehan_akhir' => 36000000,
            'akumulasi_awal' => 0, 'penyusutan' => 1000000, 'reklasifikasi_akumulasi' => -250000, 'akumulasi_akhir' => 750000,
            'nilai_buku_awal' => 48000000, 'penurunan_nilai' => 2000000, 'kenaikan_nilai' => 0,
            'reklasifikasi_masuk' => 0, 'reklasifikasi_keluar' => 11250000, 'pelepasan' => 0, 'nilai_buku_akhir' => 33750000,
        ], $baris[$this->kode('satu')]);
        // Pindah group tidak mengubah saldo buku asetnya.
        $this->assertAmounts(['nilai_buku_awal' => 24000000, 'penyusutan' => 500000, 'reklasifikasi_masuk' => 0, 'reklasifikasi_keluar' => 0, 'nilai_buku_akhir' => 23500000], $baris[$this->kode('dua')]);
        $this->assertAmounts([
            'nilai_buku_awal' => 0, 'reklasifikasi_masuk' => 11250000, 'pelepasan' => 11250000, 'pelepasan_harga_perolehan' => 12000000,
            'pelepasan_akumulasi' => 250000, 'harga_perolehan_akhir' => 0, 'nilai_buku_akhir' => 0,
        ], $baris[$this->kode('tiga')]);

        // Persamaan mutasi berlaku di setiap baris, dan saldo akhir sama dengan register untuk buku yang aktif.
        foreach ($laporan['tables']['baris'] as $row) {
            $hitung = (float) $row['nilai_buku_awal'] + (float) $row['perolehan'] - (float) $row['penyusutan'] - (float) $row['penurunan_nilai']
                + (float) $row['kenaikan_nilai'] + (float) $row['reklasifikasi_masuk'] - (float) $row['reklasifikasi_keluar'] - (float) $row['pelepasan'];
            $this->assertEqualsWithDelta($hitung, (float) $row['nilai_buku_akhir'], 0.001, $row['kode']);
        }
        foreach (['satu', 'dua'] as $nama) {
            $this->assertEqualsWithDelta((float) $this->buku($this->aset[$nama], $this->komersial)['net_book_value'], (float) $baris[$this->kode($nama)]['nilai_buku_akhir'], 0.001);
        }
        $this->assertEqualsWithDelta(72000000, (float) $laporan['fields']['total_nilai_buku_awal'], 0.001);
        $this->assertEqualsWithDelta(57250000, (float) $laporan['fields']['total_nilai_buku_akhir'], 0.001);

        // Rentang perolehan: aset pecahan tidak punya perolehan sendiri dan tidak tampil sebelum ia lahir.
        $september = $this->perKode($this->dataset('laporan-nilai-buku-aset', ['dari' => '2026-09-01', 'sampai' => '2026-09-30'])['tables']['baris']);
        $this->assertSame([$this->kode('satu'), $this->kode('dua')], array_keys($september));
        $this->assertAmounts(['harga_perolehan_awal' => 0, 'perolehan' => 48000000, 'nilai_buku_akhir' => 48000000], $september[$this->kode('satu')]);

        // Layar membaca dataset yang sama lewat pratinjau.
        $this->sebagaiPengguna($this->tenantId, ['management-aset.penyusutan.read'])
            ->getJson(self::API.'laporan/laporan-nilai-buku-aset?dari=2026-10-01&sampai=2026-11-30')
            ->assertOk()->assertJsonCount(3, 'data.tables.baris');
    }

    public function test_reconciliation_splits_each_group_account_by_the_state_of_its_postings(): void
    {
        $baris = $this->perAkun($this->dataset('laporan-rekonsiliasi-aset-buku-besar', ['per_tanggal' => '2026-11-30'])['tables']['baris']);

        // Kendaraan: 48 + 24 juta diperoleh, 24 juta keluar ke Alat kesehatan, 12 juta pecahan dijual. Pecah di
        // dalam satu group tidak dijurnal dan saling meniadakan.
        $this->assertSame('1-2300', $baris['KENDARAAN|Harga perolehan']['kode_akun']);
        $this->assertAmounts(['saldo_register' => 36000000, 'menunggu' => 36000000, 'belum_diterbitkan' => 0, 'selisih' => 36000000], $baris['KENDARAAN|Harga perolehan']);
        // Penyusutan Oktober belum di-post ke finance; yang keluar lewat reklasifikasi dan pelepasan sudah terbit.
        $this->assertAmounts(['saldo_register' => 750000, 'belum_diterbitkan' => 1500000, 'menunggu' => -750000], $baris['KENDARAAN|Akumulasi penyusutan']);
        $this->assertAmounts(['saldo_register' => 1500000, 'menunggu' => 1500000], $baris['KENDARAAN|Akumulasi penurunan nilai']);
        $this->assertSame('1-2400', $baris['ALKES|Harga perolehan']['kode_akun']);
        $this->assertAmounts(['saldo_register' => 24000000, 'menunggu' => 24000000], $baris['ALKES|Harga perolehan']);
        $this->assertAmounts(['saldo_register' => 500000, 'menunggu' => 500000], $baris['ALKES|Akumulasi penyusutan']);

        // Saldo register per group sama dengan saldo buku komersial aset yang sekarang ada di group itu.
        $this->assertEqualsWithDelta((float) $this->buku($this->aset['satu'], $this->komersial)['acquisition_value'], (float) $baris['KENDARAAN|Harga perolehan']['saldo_register'], 0.001);

        // Jurnal perolehan aset satu dibukukan aplikasi finance: pindah ke kolomnya, selisihnya mengecil.
        $receipt = (string) DB::table('aset_tr_aset')->where('id', $this->aset['satu'])->value('penerimaan_aset_id');
        FinancePosting::query()->where('posting_id', 'AST-ACQ-'.$receipt)->update(['status' => 'posted', 'external_reference' => 'JV-2026-0001', 'acknowledged_at' => now()]);
        $sesudah = $this->perAkun($this->dataset('laporan-rekonsiliasi-aset-buku-besar', ['per_tanggal' => '2026-11-30'])['tables']['baris']);
        $this->assertAmounts(['saldo_register' => 36000000, 'sudah_dibukukan' => 48000000, 'menunggu' => -12000000, 'selisih' => -12000000], $sesudah['KENDARAAN|Harga perolehan']);

        // Per tanggal sebelum reklasifikasi: aset dua masih di Kendaraan.
        $oktober = $this->perAkun($this->dataset('laporan-rekonsiliasi-aset-buku-besar', ['per_tanggal' => '2026-10-30'])['tables']['baris']);
        $this->assertAmounts(['saldo_register' => 72000000], $oktober['KENDARAAN|Harga perolehan']);
        $this->assertArrayNotHasKey('ALKES|Harga perolehan', $oktober);
    }

    public function test_projection_uses_the_depreciation_calculator_from_the_current_book_state(): void
    {
        $laporan = $this->dataset('laporan-proyeksi-penyusutan-aset', ['dari' => '2026-12', 'sampai' => '2027-02']);
        $this->assertRowKeysDeclared('laporan-proyeksi-penyusutan-aset', $laporan['tables']['baris'][0]);
        $per = [];
        foreach ($laporan['tables']['baris'] as $row) {
            $per[$row['kode'].'|'.$row['periode']] = $row;
        }

        // Aset satu sudah diturunkan nilainya: nilai buku 33,75 juta dibagi sisa umur (November 47, Desember 46…).
        $this->assertSame('718085.11', $per[$this->kode('satu').'|2026-12']['penyusutan']);
        $this->assertSame('2026-12-31', $per[$this->kode('satu').'|2026-12']['akhir_periode']);
        // Aset dua garis lurus biasa: 24 juta / 48. Aset tiga sudah dilepas, jadi tidak diproyeksikan.
        $this->assertSame('500000.00', $per[$this->kode('dua').'|2027-02']['penyusutan']);
        $this->assertSame('21500000.00', $per[$this->kode('dua').'|2027-02']['nilai_buku']);
        $this->assertCount(6, $laporan['tables']['baris']);
        $this->assertSame(2, $laporan['fields']['jumlah_aset']);

        // Proyeksi bulan pertama sama dengan usulan yang kelak dibuat proses penyusutan.
        $this->usulkanPeriode('2026-11-01', '2026-11-30');
        $usulan = (string) DB::table('aset_tr_penyusutan_aset as p')->join('aset_tr_buku_aset as b', 'b.id', '=', 'p.buku_aset_id')
            ->where(['b.aset_id' => $this->aset['satu'], 'b.buku_id' => $this->komersial])->where('p.period_ends_on', '2026-11-30')->value('p.amount');
        $november = $this->dataset('laporan-proyeksi-penyusutan-aset', ['dari' => '2026-11', 'sampai' => '2026-11'])['tables']['baris'];
        $this->assertSame($usulan, collect($november)->firstWhere('kode', $this->kode('satu'))['penyusutan']);

        $this->assertGagal('paling panjang', fn () => $this->dataset('laporan-proyeksi-penyusutan-aset', ['dari' => '2026-01', 'sampai' => '2031-12']));
    }

    public function test_acquisition_list_shows_acquired_assets_at_their_original_cost_without_split_off_parts(): void
    {
        $laporan = $this->dataset('laporan-perolehan-aset', ['dari' => '2026-09-01', 'sampai' => '2026-11-30']);
        $this->assertRowKeysDeclared('laporan-perolehan-aset', $laporan['tables']['baris'][0]);
        $baris = $this->perKode($laporan['tables']['baris']);

        $this->assertSame([$this->kode('satu'), $this->kode('dua')], array_keys($baris));
        $this->assertSame(['48000000.00', 'Pembelian'], [$baris[$this->kode('satu')]['nilai_perolehan'], $baris[$this->kode('satu')]['cara_perolehan']]);
        $this->assertNotSame('—', $baris[$this->kode('satu')]['dokumen_asal']);
        $this->assertSame('72000000.00', $laporan['fields']['total_nilai_perolehan']);
        $this->assertSame(0, $this->dataset('laporan-perolehan-aset', ['dari' => '2026-10-01', 'sampai' => '2026-12-31'])['fields']['jumlah_aset']);
    }

    public function test_depreciation_report_reads_the_accumulated_depreciation_moved_by_a_split(): void
    {
        $baris = $this->perKode($this->dataset('laporan-penyusutan-aset', ['periode' => '2026-11'])['tables']['baris']);

        $this->assertSame('750000.00', $baris[$this->kode('satu')]['akumulasi_penyusutan']);
        $this->assertSame('250000.00', $baris[$this->kode('tiga')]['akumulasi_penyusutan']);
    }

    public function test_reports_need_their_data_permission(): void
    {
        foreach (['laporan-nilai-buku-aset', 'laporan-rekonsiliasi-aset-buku-besar', 'laporan-proyeksi-penyusutan-aset'] as $kode) {
            $this->assertSame('management-aset.penyusutan.read', app(ReportRegistry::class)->get($kode)->permission());
            $this->assertGagal('tidak berhak', fn () => $this->dataset($kode, [], ['management-aset.aset.read']));
        }
        $this->assertGagal('tidak berhak', fn () => $this->dataset('laporan-perolehan-aset', [], ['management-aset.penyusutan.read']));
    }

    /**
     * Membuat dan memposting satu reklasifikasi bertanggal 31 Oktober; id aset baru baris pertamanya, bila ada.
     *
     * @param  list<array<string, mixed>>  $baris
     */
    private function reklas(string $jenis, array $baris): ?string
    {
        $izin = ['management-aset.reklasifikasi-aset.create', 'management-aset.reklasifikasi-aset.read', 'management-aset.reklasifikasi-aset.post'];
        $id = (string) $this->sebagaiPengguna($this->tenantId, $izin)
            ->withHeader('Idempotency-Key', 'rkla-'.Str::ulid())
            ->postJson(self::API.'reklasifikasi-aset', [
                'legal_entity_id' => $this->le, 'responsible_org_unit_id' => $this->poli, 'jenis' => $jenis,
                'tanggal' => '2026-10-31', 'keterangan' => 'Penataan register', 'details' => $baris,
            ])->assertCreated()->json('data.id');

        return $this->sebagaiPengguna($this->tenantId, $izin)
            ->postJson(self::API.'reklasifikasi-aset/'.$id.'/posting', ['version' => DB::table('aset_tr_reklasifikasi_aset')->where('id', $id)->value('version')])
            ->assertOk()->json('data.details.0.aset_baru_id');
    }

    /**
     * @param  array<string, mixed>  $parameter
     * @param  list<string>|null  $izin
     * @return array{fields: array<string, mixed>, tables: array<string, mixed>, file_name: string}
     */
    private function dataset(string $kode, array $parameter, ?array $izin = null): array
    {
        $konteks = [
            'tenant_id' => $this->tenantId,
            'legal_entity_id' => $this->le,
            'org_unit_id' => $this->poli,
            'user_id' => (string) Str::ulid(),
            'permissions' => $izin ?? [app(ReportRegistry::class)->get($kode)->permission()],
            'data_policies' => ['management-aset.asset-responsibility' => ['all' => true, 'scope_grants' => []]],
            'timezone' => 'UTC',
        ];

        return app(TenantRunner::class)->runFor($this->tenantId, fn (): array => app(PenyediaLaporan::class)->dataset($kode, $konteks, $parameter));
    }

    /** @param array<string, mixed> $row */
    private function assertRowKeysDeclared(string $kode, array $row): void
    {
        $declared = [];
        foreach (app(ReportRegistry::class)->get($kode)->fields() as $field) {
            if ($field['table'] === 'baris') {
                $declared[] = substr($field['key'], strlen('baris.'));
            }
        }
        $this->assertEqualsCanonicalizing($declared, array_keys($row), "Kolom baris {$kode} tidak sama dengan fields().");
    }

    /**
     * @param  array<string, int|float>  $harapan
     * @param  array<string, mixed>  $row
     */
    private function assertAmounts(array $harapan, array $row): void
    {
        foreach ($harapan as $kolom => $nilai) {
            $this->assertEqualsWithDelta($nilai, (float) $row[$kolom], 0.001, $kolom);
        }
    }

    /** @param callable(): mixed $aksi */
    private function assertGagal(string $potongan, callable $aksi): void
    {
        try {
            $aksi();
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($potongan, $e->getMessage());

            return;
        }
        $this->fail('Panggilan yang seharusnya ditolak justru berhasil.');
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function perKode(array $rows): array
    {
        $hasil = [];
        foreach ($rows as $row) {
            $hasil[(string) $row['kode']] = $row;
        }

        return $hasil;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function perAkun(array $rows): array
    {
        $hasil = [];
        foreach ($rows as $row) {
            $hasil[$row['kode_group'].'|'.$row['akun']] = $row;
        }

        return $hasil;
    }

    private function kode(string $nama): string
    {
        return (string) DB::table('aset_tr_aset')->where('id', $this->aset[$nama])->value('kode');
    }
}
