<?php

namespace Modules\Apperp\ManagementAset\Tests\Feature;

use App\Support\Modules\Contracts\PelaksanaUntukTenant;
use App\Support\Modules\Contracts\ReportFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Apperp\ManagementAset\Reporting\PenyediaLaporan;
use Modules\Apperp\ManagementAset\Reporting\ReportContext;
use Modules\Apperp\ManagementAset\Reporting\ReportData;
use Modules\Apperp\ManagementAset\Reporting\ReportDefinition;
use Modules\Apperp\ManagementAset\Reporting\ReportRegistry;
use Modules\Apperp\ManagementAset\Tests\Concerns\BerinteraksiDenganKonteksCore;
use Tests\TestCase;

/**
 * Laporan module seperti yang dibaca mesin laporan Core: definisi, layout bawaan, dan dataset.
 *
 * Dulu ketiganya endpoint HTTP `internal/v1/laporan/...` yang dipanggil Core dengan token
 * konteks pengguna. Sejak F3-12 Core membacanya langsung lewat `PenyediaLaporan` di dalam
 * proses yang sama, jadi test ini memanggil penyedianya, bukan rutenya.
 *
 * Yang diuji tidak berubah: module menegakkan permission dan scope organisasi pada jalur ini
 * persis seperti pada layar. Yang berubah, konteksnya datang sebagai argumen — bentuknya sama
 * dengan yang dulu dibawa token, dan itu memang alasan bentuknya dipertahankan.
 */
class PenyediaLaporanTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, RefreshDatabase;

    private string $tenantId;

    private string $legalEntityId;

    private string $orgUnitId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = $this->buatTenantUji();
        $this->legalEntityId = (string) Str::ulid();
        $this->orgUnitId = (string) Str::ulid();
    }

    public function test_definisi_menyebut_placeholder_dan_layout_bawaan(): void
    {
        $definisi = $this->penyedia()->definisi('work-order', $this->konteks(['management-aset.pemeliharaan-aset.read']));

        $this->assertSame(['id'], $definisi['parameters']);
        $this->assertContains(
            ['key' => 'baris.aset_kode', 'label' => 'Kode aset', 'table' => 'baris'],
            $definisi['fields'],
        );

        $isi = $this->penyedia()->layoutBawaan('work-order', 'standar', $this->konteks(['management-aset.pemeliharaan-aset.read']));
        $this->assertNotSame('', $isi, 'Berkas layout bawaan terbaca kosong dari folder module.');

        $this->assertFalse($this->penyedia()->punya('tidak-ada'));
    }

    public function test_dataset_menuntut_izin_data_dan_menghormati_scope_organisasi(): void
    {
        $workOrder = $this->workOrder();

        // Izin yang salah ditolak, dan penolakannya berbunyi seperti yang dilihat pengguna.
        // Core sudah memeriksa "boleh menjalankan laporan ini"; yang diperiksa di sini adalah
        // "boleh membaca data yang dilaporkan", dan keduanya memang pertanyaan berbeda.
        $this->assertGagalDengan(
            'Anda tidak berhak membaca data laporan ini.',
            fn () => $this->penyedia()->dataset('work-order', $this->konteks(['management-aset.aset.read']), ['id' => $workOrder]),
        );

        $data = $this->penyedia()->dataset(
            'work-order',
            $this->konteks(['management-aset.pemeliharaan-aset.read']),
            ['id' => $workOrder],
        );

        $this->assertSame('PMHA-000001', $data['fields']['kode']);
        $this->assertSame('Korektif', $data['fields']['tipe_work_order']);
        $this->assertSame('AST-WO-1', $data['tables']['baris'][0]['aset_kode']);
        $this->assertSame('Ganti ban', $data['tables']['baris'][0]['jenis_pekerjaan']);
        $this->assertSame('PMHA-000001', $data['file_name']);

        // Di luar scope organisasi pengguna: pesan yang sama dengan layar. Core mengubahnya
        // menjadi baris ekspor yang gagal dengan pesan itu, bukan menjadi kesalahan server.
        $this->assertGagalDengan(
            'Work order tidak ditemukan atau berada di luar unit kerja yang dapat Anda akses.',
            fn () => $this->penyedia()->dataset(
                'work-order',
                $this->konteks(['management-aset.pemeliharaan-aset.read'], lingkupLain: true),
                ['id' => $workOrder],
            ),
        );

        $this->assertGagalDengan(
            'Parameter laporan tidak diterima',
            fn () => $this->penyedia()->dataset(
                'work-order',
                $this->konteks(['management-aset.pemeliharaan-aset.read']),
                ['id' => 'bukan-ulid'],
            ),
        );
    }

    public function test_daftar_work_order_tersaring_status_dan_satu_baris_per_work_order(): void
    {
        $this->workOrder();
        $konteks = $this->konteks(['management-aset.pemeliharaan-aset.read']);

        $draft = $this->penyedia()->dataset('daftar-work-order', $konteks, ['status' => 'draft']);
        $this->assertSame(1, $draft['fields']['jumlah_work_order']);
        $this->assertSame('PMHA-000001', $draft['tables']['baris'][0]['kode']);
        $this->assertSame(1, $draft['tables']['baris'][0]['jumlah_baris']);

        $ditutup = $this->penyedia()->dataset('daftar-work-order', $konteks, ['status' => 'ditutup']);
        $this->assertSame(0, $ditutup['fields']['jumlah_work_order']);
        $this->assertSame([], $ditutup['tables']['baris']);
    }

    public function test_pratinjau_menampilkan_baris_yang_sama_dengan_hasil_cetak(): void
    {
        $this->workOrder();
        $izin = ['management-aset.pemeliharaan-aset.read'];

        // Yang diuji endpoint pratinjau generik, jadi laporan contohnya boleh laporan mana pun
        // yang sudah ada. Janjinya satu: yang dilihat di layar sama dengan yang tercetak.
        $layar = $this->headers($izin)
            ->getJson('/api/modules/management-aset/v1/laporan/daftar-work-order')
            ->assertOk()
            ->json('data');
        $cetak = app(ReportFormatter::class)->display(
            $this->tenantId,
            $this->penyedia()->definisi('daftar-work-order', $this->konteks($izin))['fields'],
            $this->penyedia()->dataset('daftar-work-order', $this->konteks($izin), []),
            'UTC',
        );

        $this->assertNotEmpty($layar['tables']['baris']);
        $this->assertEquals(json_decode((string) json_encode($cetak['tables']), true), $layar['tables']);

        // Filter di layar sampai ke laporan, bukan disaring ulang di browser.
        $this->headers($izin)
            ->getJson('/api/modules/management-aset/v1/laporan/daftar-work-order?status=ditutup')
            ->assertOk()
            ->assertJsonPath('data.fields.jumlah_work_order', 0)
            ->assertJsonPath('data.tables.baris', []);
    }

    public function test_pratinjau_menolak_tanpa_izin_data_kode_asing_dan_parameter_salah(): void
    {
        $this->workOrder();
        $url = '/api/modules/management-aset/v1/laporan/daftar-work-order';

        // Pengguna ini lolos pintu module karena memegang izin aset, jadi yang menolaknya
        // adalah pemeriksaan izin laporan itu sendiri. Pengguna tanpa izin sama sekali
        // sudah ditolak middleware lebih dulu dan tidak menguji apa pun di sini.
        $this->headers(['management-aset.aset.read'])->getJson($url)->assertForbidden();

        $izin = ['management-aset.pemeliharaan-aset.read'];
        $this->headers($izin)
            ->getJson('/api/modules/management-aset/v1/laporan/tidak-ada')
            ->assertNotFound();
        $this->headers($izin)
            ->getJson($url.'?dari=bukan-tanggal')
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $pesan): bool => str_contains($pesan, 'Parameter laporan tidak diterima'));
    }

    public function test_preview_formats_typed_values_like_the_printed_document(): void
    {
        // Laporan uji yang menyatakan tipe kolomnya. Datasetnya mentah; yang memformat Core,
        // lewat aturan yang sama dengan pengisi dokumen Word.
        app(ReportRegistry::class)->register(new class implements ReportDefinition
        {
            public function code(): string
            {
                return 'uji-format';
            }

            public function name(): string
            {
                return 'Uji format';
            }

            public function description(): string
            {
                return '';
            }

            public function permission(): string
            {
                return 'management-aset.aset.read';
            }

            public function builtinLayouts(): array
            {
                return [];
            }

            public function parameterRules(): array
            {
                return [];
            }

            public function fields(): array
            {
                return [
                    ['key' => 'total', 'label' => 'Total', 'table' => null, 'type' => 'money'],
                    ['key' => 'baris.nama', 'label' => 'Nama', 'table' => 'baris'],
                    ['key' => 'baris.nilai', 'label' => 'Nilai', 'table' => 'baris', 'type' => 'money'],
                    ['key' => 'baris.tanggal', 'label' => 'Tanggal', 'table' => 'baris', 'type' => 'date'],
                ];
            }

            public function data(ReportContext $context, array $parameters): ReportData
            {
                return new ReportData(
                    ['total' => '1500.50'],
                    ['baris' => [['nama' => 'Kursi', 'nilai' => 1000, 'tanggal' => '2026-07-23']]],
                    'uji-format',
                );
            }
        });

        $this->headers(['management-aset.aset.read'])
            ->getJson('/api/modules/management-aset/v1/laporan/uji-format')
            ->assertOk()
            ->assertJsonPath('data.fields.total', 'Rp 1.500,50')
            ->assertJsonPath('data.tables.baris.0', ['nama' => 'Kursi', 'nilai' => 'Rp 1.000,00', 'tanggal' => '23/07/2026']);
    }

    /**
     * B-7 (area 7): module mengirim waktu dalam UTC dan Core menulisnya menurut zona pengguna. "Hari ini"
     * milik module — nama berkas, periode bawaan, batas tanggal filter — dihitung di zona pengguna.
     */
    public function test_work_order_times_are_sent_in_utc_and_days_follow_the_user_zone(): void
    {
        // 17.30 UTC tanggal 31 Agustus adalah 00.30 WIB tanggal 1 September.
        $this->travelTo(CarbonImmutable::parse('2026-08-31 17:30:00', 'UTC'));
        $this->workOrder();
        $izin = ['management-aset.pemeliharaan-aset.read'];
        $wib = $this->konteks($izin, zona: 'Asia/Jakarta');

        $data = $this->penyedia()->dataset('daftar-work-order', $wib, []);

        // Dikirim mentah dalam UTC; jadwal yang diketik pengguna tetap dikirim apa adanya.
        $this->assertSame('2026-08-31T17:30:00Z', $data['fields']['dicetak_pada']);
        $this->assertSame('2026-08-31 17:30:00', $data['tables']['baris'][0]['dibuat_pada']);
        $this->assertSame('15/08/2026 08:00', $data['tables']['baris'][0]['diharapkan_mulai']);
        $this->assertSame('daftar-work-order-20260901-0030', $data['file_name']);

        $cetak = app(ReportFormatter::class)->display(
            $this->tenantId,
            $this->penyedia()->definisi('daftar-work-order', $wib)['fields'],
            $data,
            'Asia/Jakarta',
        );
        $this->assertSame('01/09/2026 00:30 WIB', $cetak['fields']['dicetak_pada']);
        $this->assertSame('01/09/2026 00:30 WIB', $cetak['tables']['baris'][0]['dibuat_pada']);

        // Work order yang dibuat 00.30 WIB tanggal 1 termasuk "dari tanggal 1" bagi pengguna WIB,
        // walau dalam UTC ia tercatat tanggal 31.
        $this->assertSame(1, $this->penyedia()->dataset('daftar-work-order', $wib, ['dari' => '2026-09-01'])['fields']['jumlah_work_order']);
        $this->assertSame(0, $this->penyedia()->dataset('daftar-work-order', $wib, ['sampai' => '2026-08-31'])['fields']['jumlah_work_order']);
        $utc = $this->konteks($izin);
        $this->assertSame(0, $this->penyedia()->dataset('daftar-work-order', $utc, ['dari' => '2026-09-01'])['fields']['jumlah_work_order']);

        // Periode bawaan laporan penyusutan juga bulan pengguna, bukan bulan menurut UTC.
        $penyusutan = $this->penyedia()->dataset('laporan-penyusutan-aset', $this->konteks(['management-aset.penyusutan.read'], zona: 'Asia/Jakarta'), []);
        $this->assertSame('2026-09', $penyusutan['fields']['filter_periode']);
        $this->assertSame('laporan-penyusutan-aset-2026-09', $penyusutan['file_name']);
    }

    public function test_berita_acara_hanya_dapat_dicetak_setelah_mutasi_diselesaikan(): void
    {
        $mutasi = $this->mutasi();
        $konteks = $this->konteks(['management-aset.mutasi-aset.read']);

        // Berita acara adalah bukti bahwa sesuatu sudah terjadi. Mencetaknya dari draf
        // menghasilkan lembar bertanda tangan untuk perpindahan yang belum berlangsung,
        // dan lembar itu tidak bisa ditarik kembali setelah ditandatangani.
        $this->assertGagalDengan(
            'Berita acara hanya dapat dicetak setelah mutasi diselesaikan.',
            fn () => $this->penyedia()->dataset('berita-acara-serah-terima', $konteks, ['id' => $mutasi]),
        );

        $this->selesaikanMutasi($mutasi);
        $data = $this->penyedia()->dataset('berita-acara-serah-terima', $konteks, ['id' => $mutasi]);

        $this->assertSame('MUTA-000001', $data['fields']['kode']);
        // Tanggal pada dokumen bertanda tangan ditulis dalam bahasa Indonesia, bukan d/m/Y.
        $this->assertSame('17 September 2026', $data['fields']['tanggal']);
        $this->assertSame(1, $data['fields']['jumlah_aset']);
        $this->assertSame('AST-MUT-1', $data['tables']['baris'][0]['aset_kode']);
        $this->assertSame('Gudang Cakung', $data['tables']['baris'][0]['asal_lokasi']);
        $this->assertSame('bast-MUTA-000001', $data['file_name']);

        $this->assertGagalDengan(
            'Mutasi tidak ditemukan atau berada di luar unit kerja yang dapat Anda akses.',
            fn () => $this->penyedia()->dataset(
                'berita-acara-serah-terima',
                $this->konteks(['management-aset.mutasi-aset.read'], lingkupLain: true),
                ['id' => $mutasi],
            ),
        );
    }

    public function test_daftar_mutasi_satu_baris_per_aset_dan_hanya_yang_selesai(): void
    {
        $mutasi = $this->mutasi();
        $konteks = $this->konteks(['management-aset.mutasi-aset.read']);

        // Bawaannya hanya dokumen selesai: draf belum memindahkan apa pun, dan
        // memasukkannya membuat total laporan tidak cocok dengan keadaan aset.
        $kosong = $this->penyedia()->dataset('daftar-mutasi-aset', $konteks, []);
        $this->assertSame(0, $kosong['fields']['jumlah_baris']);

        $this->selesaikanMutasi($mutasi);
        $data = $this->penyedia()->dataset('daftar-mutasi-aset', $konteks, []);

        $this->assertSame(1, $data['fields']['jumlah_baris']);
        $baris = $data['tables']['baris'][0];
        $this->assertSame('MUTA-000001', $baris['kode']);
        $this->assertSame('AST-MUT-1', $baris['aset_kode']);
        $this->assertSame('Gudang Cakung', $baris['asal_lokasi']);
        $this->assertSame('Ruang Implementor', $baris['tujuan_lokasi']);
        $this->assertSame('17/09/2026', $baris['tanggal']);
    }

    public function test_depreciation_report_reads_amounts_from_the_asset_book_records(): void
    {
        $data = $this->depreciationBooks();
        $izin = ['management-aset.penyusutan.read'];

        $laporan = $this->penyedia()->dataset('laporan-penyusutan-aset', $this->konteks($izin), ['periode' => '2026-08']);

        // Aset yang diperoleh sesudah Agustus dan buku yang ditutup sebelum Agustus tidak ikut;
        // buku fiskal tidak ikut karena tanpa pilihan buku yang dipakai buku komersial.
        $this->assertSame(['AST-DEP-1', 'AST-DEP-4'], array_column($laporan['tables']['baris'], 'kode'));
        $baris = $laporan['tables']['baris'][0];
        $this->assertRowKeysDeclared('laporan-penyusutan-aset', $baris);
        // Saldo awal 10 juta + Juni + Juli + Agustus, dikurangi pembalik Juli. Usulan Mei yang
        // belum difinalkan dan periode September tidak ikut.
        $this->assertSame('15000000.00', $baris['akumulasi_penyusutan']);
        $this->assertSame('2500000.00', $baris['penyusutan_bulan_ini']);
        $this->assertSame('5000000.00', $baris['penyusutan_tahun_berjalan']);
        $this->assertSame('105000000.00', $baris['nilai_buku_akhir']);
        $this->assertSame('Komersial', $baris['buku']);
        $this->assertSame('2025-12', $baris['bulan_perolehan']);
        // Empat bulan dari sistem lama + tiga periode final - satu yang dibalik.
        $this->assertSame([4, 48, 6, 42], [
            $baris['umur_ekonomis_tahun'], $baris['umur_ekonomis_bulan'],
            $baris['umur_ekonomis_saat_ini'], $baris['sisa_umur_ekonomis_bulan'],
        ]);
        $this->assertEquals(25, $baris['persentase_penyusutan']);

        // Buku yang belum pernah disusutkan tampil apa adanya, tanpa akumulasi perkiraan.
        $this->assertSame('0.00', $laporan['tables']['baris'][1]['akumulasi_penyusutan']);
        $this->assertSame('30000000.00', $laporan['tables']['baris'][1]['nilai_buku_akhir']);

        $this->assertSame('150000000.00', $laporan['fields']['total_nilai_perolehan']);
        $this->assertSame('15000000.00', $laporan['fields']['total_akumulasi_penyusutan']);
        $this->assertSame('Semua buku komersial', $laporan['fields']['filter_buku']);
        $this->assertSame('Semua', $laporan['fields']['filter_group']);

        // Layar memakai format yang sama dengan hasil cetak.
        $this->headers($izin)
            ->getJson('/api/modules/management-aset/v1/laporan/laporan-penyusutan-aset?periode=2026-08')
            ->assertOk()
            ->assertJsonPath('data.fields.filter_periode', 'Agustus 2026')
            ->assertJsonPath('data.fields.total_akumulasi_penyusutan', 'Rp 15.000.000,00')
            ->assertJsonPath('data.tables.baris.0.akumulasi_penyusutan', 'Rp 15.000.000,00')
            ->assertJsonPath('data.tables.baris.0.bulan_perolehan', 'Desember 2025')
            ->assertJsonPath('data.tables.baris.0.umur_ekonomis_tahun', '4')
            ->assertJsonPath('data.tables.baris.0.persentase_penyusutan', '25%');

        $this->assertGagalDengan(
            'Anda tidak berhak membaca data laporan ini.',
            fn () => $this->penyedia()->dataset('laporan-penyusutan-aset', $this->konteks(['management-aset.aset.read']), []),
        );

        // Kepala laporan menyebut nama filter, bukan id-nya.
        $denganGroup = $this->penyedia()->dataset('laporan-penyusutan-aset', $this->konteks($izin), [
            'periode' => '2026-08', 'group_aset_id' => $data['group'],
        ]);
        $this->assertSame('Elektronik', $denganGroup['fields']['filter_group']);
    }

    /**
     * Filter master pilihan banyak (K-28): beberapa pilihan pada satu filter berarti "atau", filter yang
     * berbeda tetap "dan", dan kepala laporan menyebut nama setiap pilihan, bukan id-nya.
     */
    public function test_master_filters_take_several_choices_as_or_and_name_them_in_the_header(): void
    {
        $this->pastikanOrganisasiAda($this->tenantId, $this->legalEntityId, 'legal_entity');
        $elektronik = $this->master('aset_m_group_aset', 'Elektronik', 'GRPA-C1');
        $kendaraan = $this->master('aset_m_group_aset', 'Kendaraan', 'GRPA-C2');
        $mebel = $this->master('aset_m_group_aset', 'Mebel', 'GRPA-C3');
        $jenis = $this->master('aset_m_jenis_aset', 'Umum', 'JNSA-C1');
        $tipe = $this->master('aset_m_tipe_lokasi_aset', 'Ruang', 'TLKA-C1');
        $gudang = $this->master('aset_m_lokasi_aset', 'Gudang', 'LOCA-C1', ['tipe_lokasi_id' => $tipe]);
        $kantor = $this->master('aset_m_lokasi_aset', 'Kantor', 'LOCA-C2', ['tipe_lokasi_id' => $tipe]);
        $baik = $this->master('aset_m_kondisi_aset', 'Baik', 'KDSA-C1');
        $komersial = $this->master('aset_m_buku_penyusutan', 'Komersial', 'KOM-C1', ['posting_layer' => 'current']);
        $profil = $this->master('aset_m_profil_penyusutan', 'Garis lurus', 'PRF-C1', [
            'method' => 'straight_line', 'frequency' => 'monthly', 'year_basis' => 'calendar', 'useful_life_periods' => 48,
        ]);
        foreach ([
            ['AST-C1', $elektronik, $gudang, $baik],
            ['AST-C2', $kendaraan, $kantor, null],
            ['AST-C3', $mebel, $gudang, $baik],
        ] as [$kode, $group, $lokasi, $kondisi]) {
            $asetId = $this->insertAsset($kode, '2026-01-10', 10000000, $group, $jenis);
            DB::table('aset_tr_aset')->where('id', $asetId)->update(['lokasi_aset_id' => $lokasi, 'kondisi_aset_id' => $kondisi]);
            DB::table('aset_tr_buku_aset')->insert([
                'id' => (string) Str::ulid(), 'tenant_id' => $this->tenantId, 'aset_id' => $asetId, 'buku_id' => $komersial,
                'depreciation_profile_id' => $profil, 'book_code' => $komersial, 'useful_life_periods' => 48,
                'acquisition_value' => 10000000, 'accumulated_depreciation' => 0, 'net_book_value' => 10000000,
                'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $konteks = $this->konteks(['management-aset.penyusutan.read']);
        $kode = fn (array $laporan): array => array_column($laporan['tables']['baris'], 'kode');

        $duaGroup = $this->penyedia()->dataset('laporan-penyusutan-aset', $konteks, ['periode' => '2026-08', 'group_aset_id' => [$elektronik, $kendaraan]]);
        $this->assertSame(['AST-C1', 'AST-C2'], $kode($duaGroup));
        $this->assertSame('Elektronik, Kendaraan', $duaGroup['fields']['filter_group']);
        $this->assertSame('Semua', $duaGroup['fields']['filter_lokasi']);

        // Filter yang berbeda tetap "dan": group Elektronik atau Mebel, di Gudang, berkondisi Baik.
        $gabungan = $this->penyedia()->dataset('laporan-penyusutan-aset', $konteks, [
            'periode' => '2026-08', 'group_aset_id' => [$elektronik, $mebel, $kendaraan], 'lokasi_aset_id' => [$gudang], 'kondisi_aset_id' => [$baik],
        ]);
        $this->assertSame(['AST-C1', 'AST-C3'], $kode($gabungan));
        $this->assertSame('Gudang', $gabungan['fields']['filter_lokasi']);
        $this->assertSame('Baik', $gabungan['fields']['filter_kondisi']);

        // Satu nilai tanpa daftar tetap berlaku, supaya opsi dan tautan lama tidak rusak.
        $satu = $this->penyedia()->dataset('laporan-penyusutan-aset', $konteks, ['periode' => '2026-08', 'lokasi_aset_id' => $kantor]);
        $this->assertSame(['AST-C2'], $kode($satu));

        // Id yang tidak ada pada tenant ini disebut "Tidak ditemukan", bukan id-nya.
        $asing = (string) Str::ulid();
        $tidakAda = $this->penyedia()->dataset('laporan-penyusutan-aset', $konteks, ['periode' => '2026-08', 'group_aset_id' => [$kendaraan, $asing]]);
        $this->assertSame('Kendaraan, Tidak ditemukan', $tidakAda['fields']['filter_group']);

        $this->assertGagalDengan(
            'Parameter laporan tidak diterima',
            fn () => $this->penyedia()->dataset('laporan-penyusutan-aset', $konteks, ['periode' => '2026-08', 'group_aset_id' => ['bukan-ulid']]),
        );

        // Layar mengirim pilihan banyak sebagai `group_aset_id[]=...`, dan kepala laporannya sama dengan cetakan.
        $this->headers(['management-aset.penyusutan.read'])
            ->getJson('/api/modules/management-aset/v1/laporan/laporan-penyusutan-aset?periode=2026-08&group_aset_id[]='.$elektronik.'&group_aset_id[]='.$kendaraan)
            ->assertOk()
            ->assertJsonPath('data.fields.filter_group', 'Elektronik, Kendaraan')
            ->assertJsonCount(2, 'data.tables.baris');
    }

    public function test_depreciation_report_follows_the_fiscal_year_and_the_chosen_book(): void
    {
        $data = $this->depreciationBooks();
        $konteks = $this->konteks(['management-aset.penyusutan.read']);

        // Tahun buku Juli–Juni: tahun berjalan pada Agustus hanya Juli (dibalik) dan Agustus.
        $this->buatKalenderFiskalUji($this->tenantId, $this->legalEntityId, '2026-07-01', '2027-06-30');
        $komersial = $this->penyedia()->dataset('laporan-penyusutan-aset', $konteks, ['periode' => '2026-08']);
        $this->assertSame('2500000.00', $komersial['tables']['baris'][0]['penyusutan_tahun_berjalan']);

        $fiskal = $this->penyedia()->dataset('laporan-penyusutan-aset', $konteks, ['periode' => '2026-08', 'buku_id' => $data['fiskal']]);
        $this->assertSame(['AST-DEP-1'], array_column($fiskal['tables']['baris'], 'kode'));
        $this->assertSame('Fiskal', $fiskal['tables']['baris'][0]['buku']);
        $this->assertSame('Fiskal', $fiskal['fields']['filter_buku']);

        $this->assertGagalDengan(
            'Parameter laporan tidak diterima',
            fn () => $this->penyedia()->dataset('laporan-penyusutan-aset', $konteks, ['periode' => '2026-13']),
        );
    }

    public function test_disposal_report_lists_scrapped_assets_with_their_book_values(): void
    {
        $this->scrappedAssets();
        $izin = ['management-aset.pemusnahan-aset.read'];

        $laporan = $this->penyedia()->dataset('laporan-pemusnahan-aset', $this->konteks($izin), []);

        // Hanya pemusnahan, berurutan menurut tanggal; penjualan di bulan yang sama tidak ikut.
        $this->assertSame(['AST-PMS-2', 'AST-PMS-1', 'AST-PMS-3'], array_column($laporan['tables']['baris'], 'kode_aset'));
        [$juli, $agustus, $tanpaBuku] = $laporan['tables']['baris'];
        $this->assertRowKeysDeclared('laporan-pemusnahan-aset', $agustus);
        $this->assertSame(
            ['2026-08-10', 'Rusak berat', 'Komersial', '20000000.00', '5000000.00', 'Terbakar'],
            [$agustus['tanggal'], $agustus['kondisi_aset'], $agustus['buku'], $agustus['nilai_perolehan'], $agustus['nilai_buku_akhir'], $agustus['keterangan']],
        );
        // Kondisi yang tidak tercatat tidak ditulis "Rusak", dan aset tanpa buku tidak bernilai nol.
        $this->assertSame('—', $juli['kondisi_aset']);
        $this->assertSame(['—', null, null], [$tanpaBuku['buku'], $tanpaBuku['nilai_perolehan'], $tanpaBuku['nilai_buku_akhir']]);
        // Dokumen pemusnahan selalu berstatus `draft`; kolom itu tidak ditampilkan lagi.
        $this->assertArrayNotHasKey('status', $agustus);

        $this->assertSame('30000000.00', $laporan['fields']['total_nilai_perolehan']);
        $this->assertSame('7500000.00', $laporan['fields']['total_nilai_buku']);
        $this->assertSame(3, $laporan['fields']['jumlah_dokumen']);
        $this->assertSame(['Semua', 'Semua buku komersial'], [$laporan['fields']['filter_dari'], $laporan['fields']['filter_buku']]);

        // Layar memakai format yang sama dengan hasil cetak, dan rentang tanggal sampai ke laporan.
        $this->headers($izin)
            ->getJson('/api/modules/management-aset/v1/laporan/laporan-pemusnahan-aset?dari=2026-08-01')
            ->assertOk()
            ->assertJsonPath('data.fields.filter_dari', '01/08/2026')
            ->assertJsonPath('data.fields.jumlah_dokumen', 2)
            ->assertJsonPath('data.tables.baris.0.tanggal', '10/08/2026')
            ->assertJsonPath('data.tables.baris.0.nilai_buku_akhir', 'Rp 5.000.000,00')
            ->assertJsonPath('data.tables.baris.1.nilai_buku_akhir', '');
    }

    public function test_disposal_report_shows_the_chosen_book_without_dropping_documents(): void
    {
        $data = $this->scrappedAssets();

        $laporan = $this->penyedia()->dataset('laporan-pemusnahan-aset', $this->konteks(['management-aset.pemusnahan-aset.read']), [
            'buku_id' => $data['fiskal'], 'group_aset_id' => $data['group'],
        ]);

        // Buku fiskal AST-PMS-1 bernilai buku 8 juta. AST-PMS-2 dan AST-PMS-3 tidak punya buku
        // fiskal, tetapi pemusnahannya tetap tercatat.
        $this->assertSame(['AST-PMS-2', 'AST-PMS-1', 'AST-PMS-3'], array_column($laporan['tables']['baris'], 'kode_aset'));
        $this->assertSame(['Fiskal', '8000000.00'], [$laporan['tables']['baris'][1]['buku'], $laporan['tables']['baris'][1]['nilai_buku_akhir']]);
        $this->assertNull($laporan['tables']['baris'][0]['nilai_buku_akhir']);
        $this->assertSame(['Fiskal', 'Elektronik'], [$laporan['fields']['filter_buku'], $laporan['fields']['filter_group']]);

        $this->assertGagalDengan(
            'Anda tidak berhak membaca data laporan ini.',
            fn () => $this->penyedia()->dataset('laporan-pemusnahan-aset', $this->konteks(['management-aset.aset.read']), []),
        );
    }

    public function test_sale_report_counts_each_sale_once_and_reads_the_chosen_book(): void
    {
        $data = $this->soldAssets();
        $izin = ['management-aset.penjualan-aset.read'];

        $laporan = $this->penyedia()->dataset('laporan-penjualan-aset', $this->konteks($izin), []);

        // Hanya penjualan, berurutan menurut tanggal; pemusnahan tidak ikut.
        $this->assertSame(['AST-JUAL-2', 'AST-JUAL-1', 'AST-JUAL-3'], array_column($laporan['tables']['baris'], 'kode_aset'));
        [$tanpaNilai, $lelang, $tanpaBuku] = $laporan['tables']['baris'];
        $this->assertRowKeysDeclared('laporan-penjualan-aset', $lelang);
        // AST-JUAL-1 punya buku komersial dan fiskal; tanpa pilihan hanya komersial yang dibaca.
        $this->assertSame(
            ['Komersial', '10000000.00', '6000000.00', '4000000.00'],
            [$lelang['buku'], $lelang['nilai_penjualan'], $lelang['nilai_buku'], $lelang['laba_rugi']],
        );
        // Nilai penjualan yang tidak diisi tidak ditulis nol, dan laba/ruginya ikut kosong.
        $this->assertSame([null, '3000000.00', null], [$tanpaNilai['nilai_penjualan'], $tanpaNilai['nilai_buku'], $tanpaNilai['laba_rugi']]);
        $this->assertSame(['—', '500000.00', null], [$tanpaBuku['buku'], $tanpaBuku['nilai_penjualan'], $tanpaBuku['nilai_buku']]);
        $this->assertArrayNotHasKey('status_dokumen', $lelang);

        // Penjualan dijumlah sekali, bukan sekali per buku.
        $this->assertSame(3, $laporan['fields']['jumlah_penjualan']);
        $this->assertSame('10500000.00', $laporan['fields']['total_nilai_penjualan']);
        $this->assertSame('9000000.00', $laporan['fields']['total_nilai_buku']);
        $this->assertSame('4000000.00', $laporan['fields']['total_laba_rugi']);

        $fiskal = $this->penyedia()->dataset('laporan-penjualan-aset', $this->konteks($izin), ['buku_id' => $data['fiskal']]);
        $this->assertSame(['Fiskal', '9000000.00', '1000000.00'], [
            $fiskal['tables']['baris'][1]['buku'], $fiskal['tables']['baris'][1]['nilai_buku'], $fiskal['tables']['baris'][1]['laba_rugi'],
        ]);
        $this->assertSame(['Fiskal', '10500000.00'], [$fiskal['fields']['filter_buku'], $fiskal['fields']['total_nilai_penjualan']]);

        // Layar memakai format yang sama dengan hasil cetak.
        $this->headers($izin)
            ->getJson('/api/modules/management-aset/v1/laporan/laporan-penjualan-aset?dari=2026-08-01')
            ->assertOk()
            ->assertJsonPath('data.fields.jumlah_penjualan', 2)
            ->assertJsonPath('data.fields.total_nilai_penjualan', 'Rp 10.500.000,00')
            ->assertJsonPath('data.tables.baris.0.tanggal_penjualan', '12/08/2026')
            ->assertJsonPath('data.tables.baris.0.laba_rugi', 'Rp 4.000.000,00');

        $this->assertGagalDengan(
            'Anda tidak berhak membaca data laporan ini.',
            fn () => $this->penyedia()->dataset('laporan-penjualan-aset', $this->konteks(['management-aset.mutasi-aset.read']), []),
        );
    }

    public function test_sale_value_is_totalled_once_when_an_asset_has_two_commercial_books(): void
    {
        // Satu group boleh memetakan dua buku berlapis Komersial; asetnya lalu punya dua buku yang
        // sama-sama dibaca tanpa pilihan buku. Barisnya dua, penjualannya tetap satu.
        $group = $this->master('aset_m_group_aset', 'Mesin', 'GRPA-J2');
        $jenis = $this->master('aset_m_jenis_aset', 'Genset', 'JNSA-J2');
        $asetId = $this->insertAsset('AST-JUAL-4', '2020-01-01', 40000000, $group, $jenis);
        foreach (['KOM-A', 'KOM-B'] as $kode) {
            $bukuId = $this->master('aset_m_buku_penyusutan', 'Komersial '.$kode, $kode, ['posting_layer' => 'current']);
            DB::table('aset_tr_buku_aset')->insert([
                'id' => (string) Str::ulid(), 'tenant_id' => $this->tenantId, 'aset_id' => $asetId, 'buku_id' => $bukuId,
                'book_code' => $kode, 'acquisition_value' => 40000000, 'accumulated_depreciation' => 30000000,
                'net_book_value' => 10000000, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('aset_tr_aset')->where('id', $asetId)->update(['lifecycle_state' => 'decommissioned']);
        $this->sebagaiPengguna($this->tenantId, ['management-aset.penjualan-aset.create'])
            ->withHeader('Idempotency-Key', 'penjualan-'.Str::ulid())
            ->postJson('/api/modules/management-aset/v1/penjualan-aset', [
                'legal_entity_id' => $this->legalEntityId, 'responsible_org_unit_id' => $this->orgUnitId,
                'aset_id' => $asetId, 'tanggal' => '2026-08-30', 'nilai' => 12000000,
            ])
            ->assertCreated();

        $laporan = $this->penyedia()->dataset('laporan-penjualan-aset', $this->konteks(['management-aset.penjualan-aset.read']), []);

        $this->assertCount(2, $laporan['tables']['baris']);
        $this->assertSame(1, $laporan['fields']['jumlah_penjualan']);
        $this->assertSame('12000000.00', $laporan['fields']['total_nilai_penjualan']);
    }

    public function test_every_registered_report_reaches_the_print_catalog(): void
    {
        // Dialog cetak mencari laporan di katalog Core. Katalog itu diisi `app:register-manifest`
        // dari definisi laporan module lewat `catalog()`, bukan dari manifest, jadi laporan yang
        // terdaftar di `ReportRegistry` tidak bisa lagi terlewat seperti saat blok `reports` masih
        // ditulis tangan dan tombol Cetak-nya menjawab 404. Yang dijaga di sini jalurnya sampai ke
        // tabel katalog.
        $this->assertSame(0, Artisan::call('app:register-manifest', ['module' => 'management-aset']), Artisan::output());

        $catalog = DB::table('app_reports')->where('app_id', 'management-aset')->get()->keyBy('code');
        $definitions = app(ReportRegistry::class)->all();
        $this->assertCount(count($definitions), $catalog);

        foreach ($definitions as $definition) {
            $code = 'management-aset.'.$definition->code();
            /** @var object{permission: string, parameters: string, builtin_layouts: string}|null $row */
            $row = $catalog->get($code);
            $this->assertNotNull($row, "Laporan `{$code}` tidak sampai ke katalog Core.");
            $this->assertSame($definition->permission(), $row->permission, $code);
            // Nama parameter adalah kunci aturannya, tanpa aturan per butir filter pilihan banyak (`group_aset_id.*`).
            $this->assertSame(
                array_values(array_filter(array_keys($definition->parameterRules()), static fn (string $key): bool => ! str_contains($key, '.'))),
                json_decode($row->parameters, true),
                $code,
            );
            $layouts = json_decode($row->builtin_layouts, true);
            $this->assertIsArray($layouts);
            $this->assertSame(
                array_map(static fn ($layout): array => [$layout->key, $layout->format], $definition->builtinLayouts()),
                array_map(static fn (array $layout): array => [$layout['key'], $layout['format']], $layouts),
                $code,
            );
        }
    }

    /**
     * Buku aset untuk laporan penyusutan Agustus 2026.
     *
     * - AST-DEP-1: buku komersial dengan saldo awal sistem lama, periode final Juni sampai
     *   Agustus (Juli dibalik), usulan Mei yang belum difinalkan, dan periode September;
     *   ditambah buku fiskal tanpa periode.
     * - AST-DEP-2: diperoleh September, sesudah periode laporan.
     * - AST-DEP-3: bukunya ditutup Juli karena asetnya dilepas.
     * - AST-DEP-4: diperoleh Agustus, belum pernah disusutkan.
     *
     * @return array{group: string, fiskal: string}
     */
    private function depreciationBooks(): array
    {
        $this->pastikanOrganisasiAda($this->tenantId, $this->legalEntityId, 'legal_entity');
        $group = $this->master('aset_m_group_aset', 'Elektronik', 'GRPA-D1');
        $jenis = $this->master('aset_m_jenis_aset', 'Laptop', 'JNSA-D1');
        $profil = $this->master('aset_m_profil_penyusutan', 'Garis lurus 4 tahun', 'PRF-D1', [
            'method' => 'straight_line', 'frequency' => 'monthly', 'year_basis' => 'calendar', 'useful_life_periods' => 48,
        ]);
        $komersial = $this->master('aset_m_buku_penyusutan', 'Komersial', 'KOM-D1', ['posting_layer' => 'current']);
        $fiskal = $this->master('aset_m_buku_penyusutan', 'Fiskal', 'FIS-D1', ['posting_layer' => 'tax']);

        $aset = fn (string $kode, string $diperoleh, int $nilai): string => $this->insertAsset($kode, $diperoleh, $nilai, $group, $jenis);
        $buku = function (string $asetId, string $bukuId, int $nilai, array $extra = []) use ($profil): string {
            $id = (string) Str::ulid();
            DB::table('aset_tr_buku_aset')->insert([
                'id' => $id, 'tenant_id' => $this->tenantId, 'aset_id' => $asetId, 'buku_id' => $bukuId,
                'depreciation_profile_id' => $profil, 'book_code' => $bukuId, 'useful_life_periods' => 48,
                'acquisition_value' => $nilai, 'accumulated_depreciation' => 0, 'net_book_value' => $nilai,
                'status' => 'active', ...$extra, 'created_at' => now(), 'updated_at' => now(),
            ]);

            return $id;
        };

        $pertama = $aset('AST-DEP-1', '2025-12-10', 120000000);
        $bukuPertama = $buku($pertama, $komersial, 120000000, [
            'opening_accumulated_depreciation' => 10000000, 'elapsed_periods_offset' => 4,
        ]);
        $buku($pertama, $fiskal, 120000000);
        $this->period($bukuPertama, '2026-05-31', 2500000, 'proposed');
        $this->period($bukuPertama, '2026-06-30', 2500000);
        $juli = $this->period($bukuPertama, '2026-07-31', 2500000);
        $this->period($bukuPertama, '2026-07-31', -2500000, reverses: $juli);
        $this->period($bukuPertama, '2026-08-31', 2500000);
        $this->period($bukuPertama, '2026-09-30', 2500000);

        $buku($aset('AST-DEP-2', '2026-09-05', 50000000), $komersial, 50000000);
        $buku($aset('AST-DEP-3', '2024-01-01', 80000000), $komersial, 80000000, ['status' => 'closed', 'closed_on' => '2026-07-20']);
        $buku($aset('AST-DEP-4', '2026-08-20', 30000000), $komersial, 30000000);

        return ['group' => $group, 'fiskal' => $fiskal];
    }

    /**
     * Aset yang dimusnahkan lewat API seperti oleh pengguna. Persetujuan dekomisioning berjalan
     * lewat workflow Core dan dilewati di sini, seperti di `SiklusHidupAsetTest`; dokumen
     * pemusnahannya sendiri melepas aset dan menutup bukunya.
     *
     * - AST-PMS-1: rusak berat, buku komersial dan fiskal, dimusnahkan 10 Agustus;
     * - AST-PMS-2: kondisi tidak tercatat, buku komersial saja, dimusnahkan 15 Juli;
     * - AST-PMS-3: tanpa buku, dimusnahkan 20 Agustus;
     * - AST-JUAL-1: dijual 12 Agustus, bukan dimusnahkan.
     *
     * @return array{group: string, fiskal: string}
     */
    private function scrappedAssets(): array
    {
        $group = $this->master('aset_m_group_aset', 'Elektronik', 'GRPA-P1');
        $jenis = $this->master('aset_m_jenis_aset', 'Laptop', 'JNSA-P1');
        $rusak = $this->master('aset_m_kondisi_aset', 'Rusak berat', 'KDA-P1');
        $komersial = $this->master('aset_m_buku_penyusutan', 'Komersial', 'KOM-P1', ['posting_layer' => 'current']);
        $fiskal = $this->master('aset_m_buku_penyusutan', 'Fiskal', 'FIS-P1', ['posting_layer' => 'tax']);
        $buku = function (string $asetId, string $bukuId, int $perolehan, int $nilaiBuku): void {
            DB::table('aset_tr_buku_aset')->insert([
                'id' => (string) Str::ulid(), 'tenant_id' => $this->tenantId, 'aset_id' => $asetId, 'buku_id' => $bukuId,
                'book_code' => $bukuId, 'acquisition_value' => $perolehan, 'accumulated_depreciation' => $perolehan - $nilaiBuku,
                'net_book_value' => $nilaiBuku, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);
        };

        $pertama = $this->insertAsset('AST-PMS-1', '2024-01-10', 20000000, $group, $jenis);
        DB::table('aset_tr_aset')->where('id', $pertama)->update(['kondisi_aset_id' => $rusak]);
        $buku($pertama, $komersial, 20000000, 5000000);
        $buku($pertama, $fiskal, 20000000, 8000000);
        $kedua = $this->insertAsset('AST-PMS-2', '2023-05-01', 10000000, $group, $jenis);
        $buku($kedua, $komersial, 10000000, 2500000);
        $ketiga = $this->insertAsset('AST-PMS-3', '2022-02-01', 3000000, $group, $jenis);
        $dijual = $this->insertAsset('AST-JUAL-1', '2023-03-01', 15000000, $group, $jenis);
        $buku($dijual, $komersial, 15000000, 6000000);

        foreach ([
            [$pertama, 'pemusnahan-aset', '2026-08-10', 'Terbakar'],
            [$kedua, 'pemusnahan-aset', '2026-07-15', null],
            [$ketiga, 'pemusnahan-aset', '2026-08-20', null],
            [$dijual, 'penjualan-aset', '2026-08-12', null],
        ] as [$asetId, $dokumen, $tanggal, $keterangan]) {
            DB::table('aset_tr_aset')->where('id', $asetId)->update(['lifecycle_state' => 'decommissioned']);
            $this->sebagaiPengguna($this->tenantId, ['management-aset.'.$dokumen.'.create'])
                ->withHeader('Idempotency-Key', $dokumen.'-'.Str::ulid())
                ->postJson('/api/modules/management-aset/v1/'.$dokumen, [
                    'legal_entity_id' => $this->legalEntityId, 'responsible_org_unit_id' => $this->orgUnitId,
                    'aset_id' => $asetId, 'tanggal' => $tanggal, 'keterangan' => $keterangan,
                ])
                ->assertCreated();
        }

        return ['group' => $group, 'fiskal' => $fiskal];
    }

    /**
     * Setiap kolom baris dataset harus dinyatakan di `fields()`, dan sebaliknya. Halaman Layout
     * laporan menawarkan placeholder dari `fields()`; kunci yang berbeda dengan datanya membuat
     * layout buatan tenant mencetak kolom kosong tanpa satu pun kesalahan.
     *
     * @param  array<string, mixed>  $row
     */
    private function assertRowKeysDeclared(string $kode, array $row): void
    {
        $declared = array_values(array_filter(array_map(
            static fn (array $field): ?string => $field['table'] === 'baris' ? substr($field['key'], strlen('baris.')) : null,
            $this->penyedia()->definisi($kode, $this->konteks([app(ReportRegistry::class)->get($kode)->permission()]))['fields'],
        )));
        $this->assertEqualsCanonicalizing($declared, array_keys($row), "Kolom baris {$kode} tidak sama dengan fields().");
    }

    /**
     * Aset yang dijual lewat API seperti oleh pengguna, dengan dekomisioning dilewati seperti di
     * `SiklusHidupAsetTest`; dokumen penjualannya sendiri melepas aset dan menutup bukunya.
     *
     * - AST-JUAL-1: buku komersial (nilai buku 6 juta) dan fiskal (9 juta), dijual 12 Agustus 10 juta;
     * - AST-JUAL-2: buku komersial (3 juta), dijual 20 Juli tanpa nilai penjualan;
     * - AST-JUAL-3: tanpa buku, dijual 25 Agustus 500 ribu;
     * - AST-PMS-9: dimusnahkan 1 Agustus, bukan dijual.
     *
     * @return array{fiskal: string}
     */
    private function soldAssets(): array
    {
        $group = $this->master('aset_m_group_aset', 'Kendaraan', 'GRPA-J1');
        $jenis = $this->master('aset_m_jenis_aset', 'Mobil', 'JNSA-J1');
        $komersial = $this->master('aset_m_buku_penyusutan', 'Komersial', 'KOM-J1', ['posting_layer' => 'current']);
        $fiskal = $this->master('aset_m_buku_penyusutan', 'Fiskal', 'FIS-J1', ['posting_layer' => 'tax']);
        $buku = function (string $asetId, string $bukuId, int $perolehan, int $nilaiBuku): void {
            DB::table('aset_tr_buku_aset')->insert([
                'id' => (string) Str::ulid(), 'tenant_id' => $this->tenantId, 'aset_id' => $asetId, 'buku_id' => $bukuId,
                'book_code' => $bukuId, 'acquisition_value' => $perolehan, 'accumulated_depreciation' => $perolehan - $nilaiBuku,
                'net_book_value' => $nilaiBuku, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);
        };

        $lelang = $this->insertAsset('AST-JUAL-1', '2022-01-10', 15000000, $group, $jenis);
        $buku($lelang, $komersial, 15000000, 6000000);
        $buku($lelang, $fiskal, 15000000, 9000000);
        $tanpaNilai = $this->insertAsset('AST-JUAL-2', '2023-02-01', 8000000, $group, $jenis);
        $buku($tanpaNilai, $komersial, 8000000, 3000000);
        $tanpaBuku = $this->insertAsset('AST-JUAL-3', '2021-06-01', 2000000, $group, $jenis);
        $dimusnahkan = $this->insertAsset('AST-PMS-9', '2021-06-01', 1000000, $group, $jenis);

        foreach ([
            [$lelang, 'penjualan-aset', '2026-08-12', 10000000, 'Lelang'],
            [$tanpaNilai, 'penjualan-aset', '2026-07-20', null, null],
            [$tanpaBuku, 'penjualan-aset', '2026-08-25', 500000, null],
            [$dimusnahkan, 'pemusnahan-aset', '2026-08-01', null, null],
        ] as [$asetId, $dokumen, $tanggal, $nilai, $keterangan]) {
            DB::table('aset_tr_aset')->where('id', $asetId)->update(['lifecycle_state' => 'decommissioned']);
            $this->sebagaiPengguna($this->tenantId, ['management-aset.'.$dokumen.'.create'])
                ->withHeader('Idempotency-Key', $dokumen.'-'.Str::ulid())
                ->postJson('/api/modules/management-aset/v1/'.$dokumen, [
                    'legal_entity_id' => $this->legalEntityId, 'responsible_org_unit_id' => $this->orgUnitId,
                    'aset_id' => $asetId, 'tanggal' => $tanggal, 'nilai' => $nilai, 'keterangan' => $keterangan,
                ])
                ->assertCreated();
        }

        return ['fiskal' => $fiskal];
    }

    private function insertAsset(string $kode, string $diperoleh, int $nilai, string $group, string $jenis): string
    {
        $id = (string) Str::ulid();
        DB::table('aset_tr_aset')->insert([
            'id' => $id, 'tenant_id' => $this->tenantId, 'creation_key' => 'seed-'.Str::ulid(), 'kode' => $kode,
            'nama' => 'Aset '.$kode, 'legal_entity_id' => $this->legalEntityId, 'responsible_org_unit_id' => $this->orgUnitId,
            'group_aset_id' => $group, 'jenis_aset_id' => $jenis, 'serial_number' => 'SN-'.$kode,
            'acquired_on' => $diperoleh, 'acquisition_value' => $nilai, 'currency_code' => 'IDR',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function period(string $bukuAsetId, string $akhir, int $nilai, string $status = 'final', ?string $reverses = null): string
    {
        $id = (string) Str::ulid();
        DB::table('aset_tr_penyusutan_aset')->insert([
            'id' => $id, 'tenant_id' => $this->tenantId, 'buku_aset_id' => $bukuAsetId,
            'legal_entity_id' => $this->legalEntityId, 'usage_org_unit_id' => $this->orgUnitId,
            'period_starts_on' => substr($akhir, 0, 8).'01', 'period_ends_on' => $akhir, 'amount' => $nilai,
            'status' => $status, 'reverses_period_id' => $reverses, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /** @param list<string> $permissions */
    private function headers(array $permissions): static
    {
        return $this->sebagaiPengguna($this->tenantId, $permissions);
    }

    /** Draf mutasi satu aset, dibuat lewat API seperti pengguna. */
    private function mutasi(): string
    {
        $seed = [
            'group' => $this->master('aset_m_group_aset', 'Elektronik', 'GRPA-M1'),
            'jenis' => $this->master('aset_m_jenis_aset', 'Laptop', 'JNSA-M1'),
            'tipeLokasi' => $this->master('aset_m_tipe_lokasi_aset', 'Ruang', 'TLKA-M1'),
        ];
        $asal = $this->master('aset_m_lokasi_aset', 'Gudang Cakung', 'LOCA-M1', ['tipe_lokasi_id' => $seed['tipeLokasi']]);
        $tujuan = $this->master('aset_m_lokasi_aset', 'Ruang Implementor', 'LOCA-M2', ['tipe_lokasi_id' => $seed['tipeLokasi']]);

        $asetId = (string) Str::ulid();
        DB::table('aset_tr_aset')->insert([
            'id' => $asetId, 'tenant_id' => $this->tenantId, 'creation_key' => 'seed-'.Str::ulid(), 'kode' => 'AST-MUT-1',
            'nama' => 'Laptop MSI', 'legal_entity_id' => $this->legalEntityId, 'responsible_org_unit_id' => $this->orgUnitId,
            'group_aset_id' => $seed['group'], 'jenis_aset_id' => $seed['jenis'], 'lokasi_aset_id' => $asal,
            'acquired_on' => '2026-08-01', 'acquisition_value' => 20000000, 'currency_code' => 'IDR',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (string) $this->headers(['management-aset.mutasi-aset.create'])
            ->withHeader('Idempotency-Key', 'mutasi-'.Str::ulid())
            ->postJson('/api/modules/management-aset/v1/mutasi-aset', [
                'legal_entity_id' => $this->legalEntityId,
                'responsible_org_unit_id' => $this->orgUnitId,
                'tanggal' => '2026-09-17',
                'tujuan_lokasi_id' => $tujuan,
                'tujuan_org_unit_id' => (string) Str::ulid(),
                'diserahkan_oleh_user_id' => 'eva',
                'diterima_oleh_user_id' => 'diva',
                'alasan' => 'Pindah penugasan',
                'details' => [['aset_id' => $asetId]],
            ])
            ->assertCreated()
            ->json('data.id');
    }

    private function selesaikanMutasi(string $mutasiId): void
    {
        $version = (int) DB::table('aset_tr_mutasi_aset')->where('id', $mutasiId)->value('version');
        $this->headers(['management-aset.aset.mutate', 'management-aset.mutasi-aset.read'])
            ->postJson('/api/modules/management-aset/v1/mutasi-aset/'.$mutasiId.'/selesaikan', ['version' => $version])
            ->assertOk();
    }

    /**
     * Penyedia laporan module dengan tenant aktif terikat.
     *
     * Tenantnya diikat di sini karena begitulah Core memanggilnya: mesin laporan berjalan di
     * worker antrean yang tidak pernah melewati middleware konteks module. Memanggil tanpa
     * ikatan akan lulus atau gagal tergantung permintaan HTTP mana yang kebetulan berjalan
     * sebelumnya di test yang sama.
     */
    private function penyedia(): PenyediaLaporanTerikat
    {
        return new PenyediaLaporanTerikat(
            $this->app->make(PenyediaLaporan::class),
            $this->app->make(PelaksanaUntukTenant::class),
            $this->tenantId,
        );
    }

    /**
     * Konteks seperti yang disusun Core dari keanggotaan pengguna.
     *
     * Bentuknya sama persis dengan yang dulu dibawa token konteks, dan itu disengaja: yang
     * berpindah pada F3-12 adalah pengantarnya, bukan isinya.
     *
     * @param  list<string>  $izin
     * @return array<string, mixed>
     */
    private function konteks(array $izin, bool $lingkupLain = false, string $zona = 'UTC'): array
    {
        $kebijakan = $lingkupLain
            ? ['all' => false, 'scope_grants' => [[
                'legal_entity_id' => $this->legalEntityId,
                'operating_unit_ids' => [(string) Str::ulid()],
            ]]]
            : ['all' => true, 'scope_grants' => []];

        return [
            'tenant_id' => $this->tenantId,
            'legal_entity_id' => $this->legalEntityId,
            'org_unit_id' => $this->orgUnitId,
            'user_id' => (string) Str::ulid(),
            'permissions' => $izin,
            'data_policies' => ['management-aset.asset-responsibility' => $kebijakan],
            'timezone' => $zona,
        ];
    }

    /** @param callable(): mixed $aksi */
    private function assertGagalDengan(string $potonganPesan, callable $aksi): void
    {
        try {
            $aksi();
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($potonganPesan, $e->getMessage());

            return;
        }

        $this->fail('Panggilan yang seharusnya ditolak justru berhasil; yang diharapkan pesan berisi: '.$potonganPesan);
    }

    /** Work order draf dengan satu baris pekerjaan, dibuat lewat API seperti pengguna. */
    private function workOrder(): string
    {
        $seed = [
            'tipe' => $this->master('aset_m_tipe_work_order', 'Korektif', 'TPWO-1'),
            'layanan' => $this->master('aset_m_tingkat_layanan', 'Mendesak', 'TGLY-1', ['urutan' => 1]),
            'trade' => $this->master('aset_m_trade', 'Mekanik', 'TRDE-1'),
            'jobType' => $this->master('aset_m_maintenance_job_type', 'Ganti ban', 'JOB-1', ['category_code' => 'corrective']),
            'group' => $this->master('aset_m_group_aset', 'Kendaraan', 'GRPA-1'),
            'jenis' => $this->master('aset_m_jenis_aset', 'Kendaraan roda 4', 'JNSA-1'),
            'tipeLokasi' => $this->master('aset_m_tipe_lokasi_aset', 'Gudang', 'TLKA-1'),
        ];
        $locationId = (string) Str::ulid();
        DB::table('aset_m_lokasi_aset')->insert([
            'id' => $locationId, 'tenant_id' => $this->tenantId, 'creation_key' => 'seed-'.Str::ulid(),
            'kode' => 'LOCA-1', 'nama' => 'Gudang Cakung', 'tipe_lokasi_id' => $seed['tipeLokasi'], 'aktif' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $asetId = (string) Str::ulid();
        DB::table('aset_tr_aset')->insert([
            'id' => $asetId, 'tenant_id' => $this->tenantId, 'creation_key' => 'seed-'.Str::ulid(), 'kode' => 'AST-WO-1',
            'nama' => 'Forklift 1', 'legal_entity_id' => $this->legalEntityId, 'responsible_org_unit_id' => $this->orgUnitId,
            'group_aset_id' => $seed['group'], 'jenis_aset_id' => $seed['jenis'], 'lokasi_aset_id' => $locationId,
            'acquired_on' => '2026-08-01', 'acquisition_value' => 250000000, 'currency_code' => 'IDR',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $this->headers(['management-aset.pemeliharaan-aset.create'])
            ->withHeader('Idempotency-Key', 'wo-'.Str::ulid())
            ->postJson('/api/modules/management-aset/v1/pemeliharaan-aset', [
                'legal_entity_id' => $this->legalEntityId,
                'responsible_org_unit_id' => $this->orgUnitId,
                'tipe_work_order_id' => $seed['tipe'],
                'tingkat_layanan_id' => $seed['layanan'],
                'keterangan' => 'Ban depan kanan bocor',
                'diharapkan_mulai' => '2026-08-15 08:00:00',
                'diharapkan_selesai' => '2026-08-15 12:00:00',
                'details' => [[
                    'aset_id' => $asetId, 'maintenance_job_type_id' => $seed['jobType'], 'trade_id' => $seed['trade'],
                    'ditugaskan_ke_user_id' => 'montir-1', 'estimasi_jam' => 1.5,
                ]],
            ])
            ->assertCreated()
            ->json('data.id');
    }

    /** @param array<string, mixed> $extra */
    private function master(string $table, string $nama, string $kode, array $extra = []): string
    {
        $id = (string) Str::ulid();
        DB::table($table)->insert([
            'id' => $id, 'tenant_id' => $this->tenantId, 'creation_key' => 'seed-'.Str::ulid(),
            'kode' => $kode, 'nama' => $nama, 'aktif' => true, ...$extra,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }
}

/**
 * Penyedia laporan yang setiap panggilannya berjalan dengan tenant aktif terikat.
 *
 * Ini yang dikerjakan `SumberLaporan` milik Core sebelum menyerahkan panggilan ke module.
 * Ditiru di sini supaya test menempuh keadaan yang sama, bukan keadaan yang kebetulan
 * tersisa dari permintaan HTTP sebelumnya.
 */
final class PenyediaLaporanTerikat
{
    public function __construct(
        private readonly PenyediaLaporan $penyedia,
        private readonly PelaksanaUntukTenant $pelaksana,
        private readonly string $tenantId,
    ) {}

    public function punya(string $kode): bool
    {
        return $this->penyedia->punya($kode);
    }

    /**
     * @param  array<string, mixed>  $konteks
     * @return array{fields: list<array{key: string, label: string, table: ?string, type?: string}>, parameters: list<string>}
     */
    public function definisi(string $kode, array $konteks): array
    {
        return $this->pelaksana->jalankanUntuk($this->tenantId, fn (): array => $this->penyedia->definisi($kode, $konteks));
    }

    /** @param array<string, mixed> $konteks */
    public function layoutBawaan(string $kode, string $kunci, array $konteks): string
    {
        return $this->pelaksana->jalankanUntuk($this->tenantId, fn (): string => $this->penyedia->layoutBawaan($kode, $kunci, $konteks));
    }

    /**
     * @param  array<string, mixed>  $konteks
     * @param  array<string, mixed>  $parameter
     * @return array{fields: array<string, mixed>, tables: array<string, mixed>, file_name: string}
     */
    public function dataset(string $kode, array $konteks, array $parameter): array
    {
        return $this->pelaksana->jalankanUntuk($this->tenantId, fn (): array => $this->penyedia->dataset($kode, $konteks, $parameter));
    }
}
