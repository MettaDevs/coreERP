<?php

declare(strict_types=1);

namespace Tests\Feature\Boundary;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Apperp\ManagementAset\Tests\Concerns\BerinteraksiDenganKonteksCore;
use RuntimeException;
use Tests\TestCase;

/**
 * Kriteria keluar fase 3, diperiksa mesin: tidak ada lagi lompatan HTTP dari module ke Core.
 *
 * Dua cara memeriksanya, dan keduanya diperlukan karena masing-masing buta pada hal yang
 * dilihat yang lain.
 *
 * Yang pertama membaca berkas. Ia menemukan jalur yang **ada tetapi tidak pernah dijalankan
 * test mana pun** — dan itu justru jalur yang paling berbahaya, karena tidak ada satu pun
 * kegagalan yang menandainya sampai seorang pengguna menempuhnya di produksi.
 *
 * Yang kedua menjalankan alurnya. Ia menemukan lompatan yang **tidak terlihat pada berkas
 * module**: sesuatu yang dipanggil module lewat kontrak, lalu di dalam Core sendiri masih
 * menembak dirinya sendiri lewat HTTP. Pemindai berkas tidak akan pernah melihat itu, karena
 * berkas yang melompat bukan milik module.
 *
 * Keduanya harus bisa merah. Bagaimana masing-masing dibuat merah dengan sengaja dicatat pada
 * docblock testnya.
 */
class NoInternalHttpTest extends TestCase
{
    use BerinteraksiDenganKonteksCore, RefreshDatabase;

    /**
     * Tidak satu pun module memanggil Core lewat HTTP.
     *
     * **Penjaga ini tidak pernah mengecualikan siapa pun, dan itu keputusan yang disengaja.**
     * Sampai 9 September 2026 empat penjaga pembaca berkas lain mengecualikan modul yang sedang
     * dipindah lewat `ModulSedangDipindah`, karena keadaan yang mereka larang — namespace
     * `App\`, query builder mentah, awalan tabel yang belum dinyatakan — memang ikut mendarat
     * bersama subtree-nya dan tidak mungkin dibereskan pada pull request yang sama.
     *
     * Penjaga ini berbeda jenisnya. Ia bukan pagar yang menunggu module menyusul; ia adalah
     * **kriteria selesai fase yang memindahkan module itu**. Mengecualikan modulnya berarti
     * penjaga ini hijau justru pada satu-satunya module yang ia dimaksudkan untuk menilai —
     * hijau tanpa pernah bisa merah, yang tidak menjaga apa pun.
     *
     * Daftar pemindahan sendiri kosong sejak modul aset selesai pada F3-30, jadi sekarang
     * kelima penjaga memindai seluruh modul tanpa kecuali. Perbedaan di atas tetap ditulis
     * karena ia berlaku lagi pada modul berikutnya yang mendarat.
     *
     * Konsekuensinya juga disengaja: seandainya sebuah module lain mendarat besok dengan klien
     * HTTP-nya masih utuh, penjaga ini merah sejak hari pertama dan pemindahannya tidak bisa
     * digabung sebelum jalurnya diganti. Itu urutan yang benar — jalur HTTP adalah hal yang
     * paling mahal ditinggalkan setengah jadi, karena ia tetap bekerja di lingkungan
     * pengembangan dan baru gagal saat Core dan module tidak lagi saling melihat.
     */
    public function test_tidak_ada_module_yang_memanggil_core_lewat_http(): void
    {
        $pemindai = PemindaiModul::padaRepo();
        $folderModul = $pemindai->folderModul();

        $this->assertNotSame([], $folderModul, 'Tidak satu pun folder module terbaca; pemindaiannya salah alamat dan hasil hijaunya tidak berarti apa-apa.');

        $pelanggaran = [];

        foreach ($folderModul as $folder) {
            $pelanggaran = array_merge($pelanggaran, $pemindai->pelanggaranLompatanHttp($folder));
        }

        sort($pelanggaran);

        $this->assertSame([], $pelanggaran, implode("\n", [
            'Masih ada module yang menghubungi Core lewat HTTP:',
            ...$pelanggaran,
            '',
            'Core berjalan di proses yang sama dengan module ini, jadi permintaan HTTP ke sana tidak',
            'menambah satu pun kemampuan — ia hanya menambah kegagalan yang bisa terjadi: batas waktu,',
            'percobaan ulang, token service yang tidak sinkron, dan 503 yang harus dijelaskan ke',
            'pengguna. Yang paling mahal: permintaan itu berjalan di luar transaksi database module,',
            'sehingga nomor yang sudah terbit tidak ikut batal ketika dokumennya gagal disimpan.',
            'Pakai antarmuka di App\\Support\\Modules\\Contracts.',
        ]));
    }

    /**
     * Penjaga di atas hijau karena tidak ada lompatan, bukan karena ia tidak bisa melihat.
     *
     * Tanpa test ini, "hijau" dan "buta" terlihat persis sama dari luar. Potongan kode di bawah
     * ditulis di sini, bukan disisipkan ke berkas module, supaya pembuktiannya ikut berjalan
     * di CI setiap hari — bukan hanya sekali di tangan orang yang menulisnya.
     */
    public function test_pemindai_merah_pada_kode_yang_benar_benar_melompat(): void
    {
        $lewatFacade = <<<'PHP'
        <?php

        namespace Modules\Apperp\Contoh\Services;

        use Illuminate\Support\Facades\Http;

        class KlienNomor
        {
            public function terbitkan(string $referensi): string
            {
                return Http::withHeaders(['X-CoreERP-Service-Token' => config('services.coreerp.service_token')])
                    ->post(config('services.coreerp.url').'/api/internal/v1/number-sequences/'.$referensi.'/issue')
                    ->json('data.number');
            }
        }
        PHP;

        // Bentuk lengkap tanpa `use`. Pola versi pertama melewatkan bentuk ini, dan itu baru
        // ketahuan setelah pemanggilan sungguhan disisipkan ke berkas module: penjaganya merah,
        // tetapi karena alamat endpointnya — bukan karena kliennya. Sebuah lompatan yang tidak
        // menyebut alamat apa pun akan lolos utuh. Bentuknya dikunci di sini supaya lubang itu
        // tidak bisa kembali tanpa ada yang gagal.
        $lengkapTanpaUse = <<<'PHP'
        <?php

        namespace Modules\Apperp\Contoh\Services;

        class KlienLengkap
        {
            public function jalan(): void
            {
                \Illuminate\Support\Facades\Http::get('http://core/ping');
            }
        }
        PHP;

        $lewatGuzzle = <<<'PHP'
        <?php

        namespace Modules\Apperp\Contoh\Services;

        class KlienDiam
        {
            public function jalan(): void
            {
                $klien = new \GuzzleHttp\Client(['base_uri' => 'http://core']);
                $klien->get('/api/internal/v1/fiscal-periods');
            }
        }
        PHP;

        $this->assertSame(
            ['Http::', 'services.coreerp', 'X-CoreERP-Service-Token', '/api/internal/v1/'],
            PemindaiModul::lompatanHttpYangDipakai($lewatFacade),
            'Klien HTTP yang menembak endpoint internal Core harus terbaca sebagai pelanggaran. Kalau tidak, penjaga di atas hijau karena buta.',
        );

        $this->assertSame(
            ['Http::'],
            PemindaiModul::lompatanHttpYangDipakai($lengkapTanpaUse),
            'Facade Http yang ditulis dengan nama lengkap tidak terbaca. Melarang satu ejaan saja berarti mengajari orang berikutnya ejaan yang lolos.',
        );

        $this->assertSame(
            ['GuzzleHttp', '/api/internal/v1/'],
            PemindaiModul::lompatanHttpYangDipakai($lewatGuzzle),
            'Lompatan lewat Guzzle langsung harus ikut terbaca. Melarang hanya facade Http berarti melarang satu ejaan, bukan satu perbuatan.',
        );
    }

    /**
     * Komentar yang menceritakan jalur lama bukan jalur lama.
     *
     * Ini bukan kehalusan. Berkas module hari ini memang menyimpan kalimat sejarah yang menyebut
     * `Http::fake`, dan penjaga yang mencocokkan teks mentah akan merah karenanya. Orang yang
     * membaca kegagalan itu akan menghapus kalimatnya — yaitu satu-satunya tempat yang
     * menjelaskan kenapa jalur itu ditinggalkan — dan tidak memperbaiki apa pun.
     */
    public function test_komentar_yang_menyebut_http_bukan_lompatan(): void
    {
        $hanyaCerita = <<<'PHP'
        <?php

        namespace Modules\Apperp\Contoh\Services;

        /**
         * Dulu jalur ini memakai Http::post ke /api/internal/v1/number-sequences milik Core,
         * dan testnya memalsukan jawabannya dengan Http::fake. Sekarang lewat kontrak.
         */
        class Penerbit
        {
            // Setelan services.coreerp.url tidak lagi dibaca siapa pun.
            public function terbitkan(): string
            {
                return 'NS-000001';
            }
        }
        PHP;

        $this->assertSame(
            [],
            PemindaiModul::lompatanHttpYangDipakai($hanyaCerita),
            'Komentar dihitung sebagai pelanggaran. Penjaga yang menuntut sejarahnya dihapus akan mendapatkan sejarah yang dihapus, bukan jalur yang diperbaiki.',
        );

        $berkas = dirname(__DIR__, 5).'/modules/apperp/management-aset/src/Services/PersetujuanAset.php';

        $this->assertFileExists($berkas, 'Berkas yang dipakai sebagai contoh nyata sudah pindah; sesuaikan test ini atau buang contohnya.');

        $isi = (string) file_get_contents($berkas);

        $this->assertStringContainsString('Http::fake', $isi, 'Contoh nyata komentar yang menyebut Http:: sudah hilang dari berkas ini, jadi test ini tidak lagi menguji keadaan yang sungguhan ada.');
        $this->assertSame([], PemindaiModul::lompatanHttpYangDipakai($isi), 'Berkas ini hanya menyebut Http:: di dalam komentar, jadi ia tidak boleh dihitung melompat.');
    }

    /**
     * Alur nyata dari pendaftaran aset sampai keputusan workflow, tanpa satu pun permintaan HTTP.
     *
     * Alur cetak laporan sengaja **tidak** diambil di sini; ia dibuktikan tersendiri. Yang ini
     * menempuh tiga dari empat jalur yang dulu HTTP, dalam satu permintaan berantai:
     *
     * 1. **Satuan** dibaca lewat `DaftarSatuanAset`, yang dulu memanggil direktori satuan Core.
     * 2. **Nomor** diterbitkan lewat `PenerbitNomor` — dua kali, sekali untuk aset dan sekali
     *    untuk dokumen dekomisioning.
     * 3. **Kalender fiskal** dibaca lewat `KalenderFiskal`. Ini yang paling mudah lolos diam-diam:
     *    module memang **menelan** kegagalannya dan jatuh kembali ke tahun kalender, tanpa satu
     *    pun kesalahan yang terlihat — hanya angka yang berbeda. Karena itu ia diperiksa lewat
     *    akibatnya. Profil berdasar tahun fiskal dengan konvensi setengah tahun menaruh tanggal
     *    mulai menyusut di tengah **tahun buku** Juli–Juni, yaitu 30 Desember 2026. Bila
     *    kalendernya tidak terbaca, batasnya jatuh ke tahun kalender 2026 dan tanggalnya menjadi
     *    2 Juli 2026. Lima bulan selisih, jadi kegagalan membaca kalender tidak bisa lewat
     *    sebagai "kebetulan sama".
     * 4. **Workflow** diajukan lewat kontrak, tugasnya disetujui pemeriksa lewat layar Core, dan
     *    keputusannya kembali ke dokumen module lewat event di dalam proses.
     *
     * **Bagaimana "tanpa jaringan" dibuat bisa gagal.** Tiga lapis, dan ketiganya diperlukan:
     *
     * - `Http::fake()` dengan penangan yang **melempar** untuk permintaan apa pun. `Http::fake()`
     *   telanjang justru kebalikannya — ia memulangkan 200 kosong dan membuat lompatan yang
     *   tersisa terlihat berhasil.
     * - `Http::preventStrayRequests()`, yang menangkap permintaan yang entah bagaimana lolos dari
     *   pemalsuan di atas.
     * - Daftar permintaan yang dicatat penangan itu sendiri, diperiksa di akhir.
     *
     * Lapis ketiga tampak berlebihan dan tidak. `Http::assertNothingSent()` dipakai lebih dulu di
     * sini dan **tidak bekerja**: pencatatan pasangan permintaan-jawaban baru terjadi setelah
     * penangan pemalsuan memulangkan sesuatu, jadi permintaan yang penanganya melempar tidak
     * pernah tercatat sama sekali. Itu ketahuan dengan menyisipkan lompatan yang menelan
     * kegagalannya sendiri — `try { Http::get(...) } catch (Throwable) { }` — ke jalur penerbitan
     * nomor: dua lapis pertama diam, `assertNothingSent()` diam, dan testnya hijau dengan sebuah
     * permintaan HTTP yang benar-benar keluar. Bentuk penelan seperti itu bukan karangan; jalur
     * ini memang punya beberapa.
     *
     * Karena itu penangannya mencatat lebih dulu, baru melempar. Yang dicatat tidak bisa ditelan
     * siapa pun.
     */
    public function test_alur_aset_sampai_keputusan_workflow_tanpa_satu_pun_permintaan_http(): void
    {
        $permintaanKeluar = [];

        Http::preventStrayRequests();
        Http::fake(function ($permintaan) use (&$permintaanKeluar): never {
            $permintaanKeluar[] = $permintaan->method().' '.$permintaan->url();

            throw new RuntimeException('Ada permintaan HTTP yang keluar dari alur yang seharusnya seluruhnya di dalam proses.');
        });

        $tenantId = $this->buatTenantUji();
        $legalEntityId = (string) Str::ulid();
        $this->pastikanOrganisasiAda($tenantId, $legalEntityId, 'legal_entity');

        // Tahun buku Juli sampai Juni, sengaja bukan tahun kalender: itu yang membuat
        // "kalender fiskal terbaca" dan "kalender fiskal tidak terbaca" menghasilkan angka
        // yang berbeda.
        $this->buatKalenderFiskalUji($tenantId, $legalEntityId, '2026-07-01', '2027-06-30');

        $satuanId = $this->buatSatuanUji($tenantId, 'unit', 'Unit');
        $klasifikasi = $this->klasifikasiDenganBukuTahunFiskal($tenantId);

        // Pemeriksa dibuat lebih dulu karena grafnya harus menyebut keanggotaannya.
        $this->sebagaiPenggunaBernama('pemeriksa', $tenantId, []);
        $idPemeriksa = $this->idKeanggotaan('pemeriksa', $tenantId);
        $this->siapkanWorkflowDekomisioning($tenantId, $legalEntityId, $idPemeriksa);

        $satuan = $this->sebagaiPenggunaBernama('pengaju', $tenantId, ['management-aset.perencanaan-aset.read'])
            ->getJson('/api/modules/management-aset/v1/reference-data/units-of-measure')
            ->assertOk()->json('data');

        $this->assertSame([$satuanId], array_column($satuan, 'id'), 'Satuan milik tenant tidak terbaca lewat kontrak Core. Dulu daftar ini datang dari direktori satuan lewat HTTP.');

        // Aset lahir dari dokumen penerimaan sejak 18 September 2026; `POST /aset` dibuang.
        // Dua nomor karena itu terbit di sini, bukan satu: satu untuk dokumennya, satu untuk
        // asetnya — dan keduanya sama-sama harus datang dari Core.
        $penerimaan = $this->sebagaiPenggunaBernama('pengaju', $tenantId, ['management-aset.penerimaan-aset.read', 'management-aset.penerimaan-aset.create'])
            ->withHeader('Idempotency-Key', 'tanpa-jaringan-penerimaan')
            ->postJson('/api/modules/management-aset/v1/penerimaan-aset', [
                'legal_entity_id' => $legalEntityId,
                'responsible_org_unit_id' => (string) Str::ulid(),
                'tanggal' => '2026-07-28',
                'currency_code' => 'IDR',
                // Pembelian pada mode bawaan `direct_payable` membawa vendor, dan vendornya pun
                // dibaca lewat kontrak Core di dalam proses, bukan lewat HTTP.
                'vendor_id' => $this->pastikanVendorUji($tenantId, $legalEntityId),
                'details' => [[
                    'nama' => 'Aset alur tanpa jaringan',
                    ...$klasifikasi,
                    'jumlah' => 1,
                    'nilai_per_unit' => 12000000,
                ]],
            ])->assertCreated()->json('data');

        $aset = $this->sebagaiPenggunaBernama('pengaju', $tenantId, ['management-aset.penerimaan-aset.read', 'management-aset.aset.create'])
            ->postJson('/api/modules/management-aset/v1/penerimaan-aset/'.$penerimaan['id'].'/selesaikan', ['version' => 1])
            ->assertOk();

        $aset = $this->sebagaiPenggunaBernama('pengaju', $tenantId, ['management-aset.penerimaan-aset.read'])
            ->getJson('/api/modules/management-aset/v1/penerimaan-aset/'.$penerimaan['id'].'/aset')
            ->assertOk()->json('data.0');
        $aset['responsible_org_unit_id'] = $penerimaan['responsible_org_unit_id'];

        $this->assertSame(
            $this->awalanNomor('management-aset.aset').'-000001',
            $aset['kode'],
            'Nomor aset tidak diterbitkan Core lewat kontrak. Nomor yang dipalsukan tidak pernah memajukan penghitung mana pun, jadi kode inilah bukti bahwa penerbitannya sungguhan.',
        );
        $this->assertSame(2, $this->jumlahNomorTerbit(), 'Tidak ada dua baris penerbitan nomor di Core, padahal dokumen dan asetnya sama-sama terbentuk. Salah satu nomornya datang dari tempat lain.');

        $mulaiMenyusut = DB::table('aset_tr_buku_aset')->where('aset_id', $aset['id'])->value('depreciation_start_on');

        $this->assertSame('2026-12-30', (string) $mulaiMenyusut, implode("\n", [
            'Tanggal mulai menyusut tidak memakai batas tahun buku Juli–Juni milik tenant ini.',
            '2026-07-02 berarti kalender fiskal tidak terbaca dan perhitungannya diam-diam jatuh ke',
            'tahun kalender. Module memang menelan kegagalan pembacaan kalender dan meneruskan tanpa',
            'kesalahan apa pun, jadi angka inilah satu-satunya tempat kegagalan itu terlihat — dan',
            'akibatnya bukan kosmetik: seluruh jadwal penyusutan aset ini bergeser lima bulan.',
        ]));

        $dokumen = $this->sebagaiPenggunaBernama('pengaju', $tenantId, ['management-aset.dekomisioning-aset.create'])
            ->withHeader('Idempotency-Key', 'tanpa-jaringan-dekomisioning')
            ->postJson('/api/modules/management-aset/v1/dekomisioning-aset', [
                'legal_entity_id' => $legalEntityId,
                'responsible_org_unit_id' => $aset['responsible_org_unit_id'],
                'tanggal' => '2026-08-03',
                'aset_id' => $aset['id'],
            ])->assertCreated()->json('data');

        $this->assertNotNull($dokumen['workflow_instance_id'], 'Dokumen tersimpan tanpa instance workflow. Sebelum pengajuan berada di dalam transaksinya, keadaan ini berarti dokumen yang menunggu persetujuan yang tidak pernah diajukan siapa pun.');
        $this->assertSame(3, $this->jumlahNomorTerbit(), 'Dokumen dekomisioning tidak menerbitkan nomornya sendiri lewat Core.');

        $tugas = $this->tugasMenunggu($tenantId, $idPemeriksa);
        $this->assertNotNull($tugas, 'Tidak ada tugas persetujuan yang menunggu pemeriksa, jadi pengajuannya tidak pernah sampai ke workflow Core.');

        $this->sebagaiPenggunaBernama('pemeriksa', $tenantId, [])
            ->post('/workflow-inbox/'.$tugas->id.'/decision', ['decision' => 'approve'])
            ->assertRedirect();

        $this->assertDatabaseHas('aset_tr_dokumen_siklus_aset', ['id' => $dokumen['id'], 'status' => 'approved']);
        $this->assertDatabaseHas('aset_tr_aset', ['id' => $aset['id'], 'lifecycle_state' => 'decommissioned']);

        $this->assertSame([], $permintaanKeluar, implode("\n", [
            'Alur ini masih mengirim permintaan HTTP: '.implode(', ', $permintaanKeluar).'.',
            'Seluruh jalurnya — satuan, nomor, kalender fiskal, workflow — berada di proses yang sama,',
            'jadi satu permintaan yang tersisa berarti ada jalur yang belum ikut pindah. Selama ia',
            'dibungkus penangkap kegagalan, ia tidak akan pernah menampakkan diri sebagai kesalahan;',
            'ia hanya membuat alur ini bergantung pada Core yang dapat dihubungi lewat jaringan,',
            'padahal sejak fase ini Core tidak lagi punya alamat yang bisa dituju module.',
        ]));
    }

    /**
     * Klasifikasi aset beserta matriks group x buku yang memaksa kalender fiskal dibaca.
     *
     * `year_basis` fiskal dan konvensi setengah tahun adalah satu-satunya kombinasi yang membuat
     * module menanyakan batas tahun buku ke Core. Kombinasi lain tidak memakai batas itu sama
     * sekali, jadi memakainya di sini akan membuat testnya hijau tanpa pernah menyentuh
     * kalender.
     *
     * @return array{group_aset_id: string, jenis_aset_id: string}
     */
    private function klasifikasiDenganBukuTahunFiskal(string $tenantId): array
    {
        $now = now();
        $group = (string) Str::ulid();
        $jenis = (string) Str::ulid();
        $profil = (string) Str::ulid();
        $buku = (string) Str::ulid();

        DB::table('aset_m_group_aset')->insert([
            'id' => $group, 'tenant_id' => $tenantId, 'creation_key' => 'group-'.Str::ulid(),
            'kode' => 'G'.Str::random(6), 'nama' => 'Group tanpa jaringan', 'aktif' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('aset_m_jenis_aset')->insert([
            'id' => $jenis, 'tenant_id' => $tenantId, 'creation_key' => 'jenis-'.Str::ulid(),
            'kode' => 'J'.Str::random(6), 'nama' => 'Jenis tanpa jaringan', 'aktif' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('aset_m_profil_penyusutan')->insert([
            'id' => $profil, 'tenant_id' => $tenantId, 'creation_key' => 'profil-'.Str::ulid(),
            'kode' => 'P'.Str::random(8), 'nama' => 'Profil tahun fiskal', 'aktif' => true,
            'method' => 'straight_line', 'frequency' => 'monthly', 'year_basis' => 'fiscal',
            'useful_life_periods' => 12, 'convention' => 'half_year',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('aset_m_buku_penyusutan')->insert([
            'id' => $buku, 'tenant_id' => $tenantId, 'creation_key' => 'buku-'.Str::ulid(),
            'kode' => 'B'.Str::random(8), 'nama' => 'Buku tahun fiskal', 'aktif' => true,
            'posting_layer' => 'current',
            'depreciation_profile_id' => $profil, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('aset_m_group_buku_penyusutan')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $tenantId, 'group_aset_id' => $group,
            'buku_id' => $buku, 'depreciate' => true, 'useful_life_periods' => 12,
            'convention' => 'half_year', 'created_at' => $now, 'updated_at' => $now,
        ]);

        return ['group_aset_id' => $group, 'jenis_aset_id' => $jenis];
    }
}
