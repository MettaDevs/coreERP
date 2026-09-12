<?php

declare(strict_types=1);

namespace Tests\Feature\ControlPlane;

use App\Console\Commands\SapuLingkunganKedaluwarsa;
use App\Models\Client;
use App\Models\Environment;
use App\Models\EnvironmentOperation;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Ujung daur hidup sebuah lingkungan: kedaluwarsa, dipulihkan, atau benar-benar dibuang.
 *
 * Ketiganya diuji dalam satu berkas karena ketiganya satu rantai — dan rantai itu yang sebenarnya
 * dijaga. Sapuan yang tidak punya pemulihan bukan hapus lunak melainkan hapus keras yang ditunda,
 * dan pembuangan permanen yang tidak pernah dibuktikan menghormati masa tenggang adalah pembuangan
 * yang membuang terlalu cepat tanpa ada yang tahu.
 *
 * Dua aturan dipegang seluruh berkas ini:
 *
 * **Penolakan dibuktikan lewat akibatnya, bukan lewat kode keluarnya.** Sebuah perintah yang
 * memulangkan FAILURE sesudah terlanjur membuang database lulus pemeriksaan kode keluar dan tetap
 * salah. Karena itu tiap jalur merah memeriksa databasenya masih ada, barisnya masih utuh, dan
 * riwayatnya tidak bertambah.
 *
 * **Database sungguhan, bukan nama yang dikarang.** Jalur merah pembuangan memakai database
 * PostgreSQL yang benar-benar dibuat lebih dulu, sehingga "tidak jadi dibuang" adalah fakta yang
 * dibaca dari `pg_database` — bukan dari ketiadaan sesuatu yang memang tidak pernah ada.
 */
class DaurHidupLingkunganTest extends TestCase
{
    use RefreshDatabase;

    /** Awalan nama database uji; ia yang dipakai membuang sisa-sisanya. */
    private const AWALAN = 'env_ujidaur';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $client = Client::create(['legal_name' => 'PT Uji Daur', 'slug' => 'uji-daur', 'status' => 'active']);
        $this->tenant = Tenant::create([
            'client_id' => $client->id,
            'name' => 'PT Uji Daur',
            'slug' => 'ujidaur',
            'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        $this->buangDatabaseUji();

        parent::tearDown();
    }

    // ------------------------------------------------------- sapuan: jalur hijau

    public function test_demo_kedaluwarsa_dihapus_lunak_beserta_jadwal_pembuangannya(): void
    {
        $demo = $this->buat('demo', 'active', 'lewat', now()->subDay());

        $this->artisan('environment:sapu-kedaluwarsa')->assertExitCode(Command::SUCCESS);

        // Ketiganya sekaligus, dan memang tidak dapat sebagian: `environments_hapus_berpasangan`
        // dan `environments_status_hapus_sejalan` menolak setiap kombinasi lainnya.
        $demo->refresh();
        $this->assertSame('soft_deleted', $demo->status);
        $this->assertNotNull($demo->deleted_at);
        $this->assertNotNull($demo->purge_after);
        $this->assertNull($demo->purged_at, 'Hapus lunak tidak boleh menyentuh isinya.');

        // Masa tenggangnya berjalan dari sekarang, bukan dari tanggal kedaluwarsanya. Demo yang
        // sudah lewat setahun tetap memperoleh jendela penuh untuk dipulihkan.
        $this->assertTrue($demo->purge_after->isAfter(now()->addDays(SapuLingkunganKedaluwarsa::TENGGANG_HARI - 1)));
        $this->assertTrue($demo->purge_after->isBefore(now()->addDays(SapuLingkunganKedaluwarsa::TENGGANG_HARI + 1)));

        $operasi = EnvironmentOperation::query()->where('environment_id', $demo->id)->firstOrFail();
        $this->assertSame('expire', $operasi->operation);
        $this->assertSame('succeeded', $operasi->status);
        $this->assertNull($operasi->lease_until);

        // Status sebelumnya tercatat, dan itu bukan hiasan: barisnya sendiri sudah tidak dapat
        // menyimpannya, dan pemulihan membacanya dari sini.
        $detail = $operasi->detail;
        $this->assertIsArray($detail);
        $this->assertSame('active', $detail['status_sebelumnya'] ?? null);
    }

    public function test_sapuan_kedua_tidak_menyapu_ulang_yang_sudah_dihapus_lunak(): void
    {
        $demo = $this->buat('demo', 'active', 'lewat', now()->subDay());

        $this->artisan('environment:sapu-kedaluwarsa')->assertExitCode(Command::SUCCESS);
        $tenggang = $demo->refresh()->purge_after?->toIso8601String();

        // Kalau `deleted_at` tidak ikut disaring, sapuan besok akan memundurkan masa tenggang yang
        // sudah berjalan — dan lingkungan yang menunggu dipulihkan tidak pernah sampai ke antrean
        // pembuangan.
        $this->artisan('environment:sapu-kedaluwarsa')->assertExitCode(Command::SUCCESS);

        $this->assertSame($tenggang, $demo->refresh()->purge_after?->toIso8601String());
        $this->assertSame(1, EnvironmentOperation::query()->where('environment_id', $demo->id)->count());
    }

    public function test_masa_tenggang_dapat_disebut_operator_dan_yang_tidak_masuk_akal_ditolak(): void
    {
        $demo = $this->buat('demo', 'active', 'tenggang-pendek', now()->subDay());

        // Nol ditolak di depan, sebelum satu baris pun bergerak. Masa tenggang nol hari berarti
        // sapuan dan pembuangan permanen dapat berjalan pada malam yang sama — yaitu menghapus
        // jendela yang menjadi satu-satunya alasan hapus lunak ada.
        $this->artisan('environment:sapu-kedaluwarsa', ['--tenggang' => '0'])->assertExitCode(Command::FAILURE);
        $this->assertNull($demo->refresh()->deleted_at);
        $this->assertSame(0, EnvironmentOperation::query()->count());

        $this->artisan('environment:sapu-kedaluwarsa', ['--tenggang' => '3'])->assertExitCode(Command::SUCCESS);

        $demo->refresh();
        $this->assertSame('soft_deleted', $demo->status);
        $this->assertTrue($demo->purge_after?->isBefore(now()->addDays(4)) ?? false);
        $this->assertTrue($demo->purge_after?->isAfter(now()->addDays(2)) ?? false);
    }

    // ------------------------------------------------------- sapuan: jalur merah

    public function test_lingkungan_aktif_tidak_ikut_tersapu(): void
    {
        $demo = $this->buat('demo', 'active', 'masih-berlaku', now()->addDays(20));
        $produksi = $this->buat('production', 'active', 'produksi');
        $sandbox = $this->buat('sandbox', 'active', 'sandbox');

        $this->artisan('environment:sapu-kedaluwarsa')->assertExitCode(Command::SUCCESS);

        // Ketiganya, dan masing-masing menutup kesalahan yang berbeda: demo yang disapu terlalu
        // cepat, produksi yang ikut tersapu karena penyaring jenisnya lupa, dan sandbox yang
        // tersapu karena ia kebetulan bukan produksi.
        foreach ([$demo, $produksi, $sandbox] as $lingkungan) {
            $lingkungan->refresh();
            $this->assertSame('active', $lingkungan->status);
            $this->assertNull($lingkungan->deleted_at);
            $this->assertNull($lingkungan->purge_after);
        }

        $this->assertSame(0, EnvironmentOperation::query()->count(), 'Sapuan yang tidak menyapu apa pun tidak meninggalkan riwayat.');
    }

    public function test_sapuan_melanjutkan_sesudah_satu_lingkungan_gagal(): void
    {
        $pertama = $this->buat('demo', 'active', 'satu', now()->subDays(3));
        $gagal = $this->buat('demo', 'active', 'dua', now()->subDays(2));
        $terakhir = $this->buat('demo', 'active', 'tiga', now()->subDay());

        // Kegagalan yang sungguhan, ditanam di PostgreSQL, bukan di PHP. Yang diuji justru
        // perilakunya terhadap pernyataan yang ditolak database: tanpa SAVEPOINT, penolakan pada
        // lingkungan kedua membatalkan seluruh blok transaksi dan setiap pernyataan sesudahnya
        // gagal dengan `25P02` — "melanjutkan" berubah menjadi "melanjutkan lalu gagal semuanya".
        $this->tanamKegagalan($gagal);

        $this->artisan('environment:sapu-kedaluwarsa')->assertExitCode(Command::FAILURE);

        $this->assertSame('soft_deleted', $pertama->refresh()->status);
        $this->assertSame(
            'soft_deleted',
            $terakhir->refresh()->status,
            'Lingkungan sesudah yang gagal wajib tetap tersapu; itu seluruh alasan loop ini ada.',
        );

        $gagal->refresh();
        $this->assertSame('active', $gagal->status, 'Yang gagal tidak boleh setengah terhapus.');
        $this->assertNull($gagal->deleted_at);
        $this->assertNull($gagal->purge_after);

        // Dan sebabnya dapat dibaca besok pagi. Penjadwal membuang keluaran konsolnya, jadi baris
        // ini satu-satunya yang tersisa.
        $riwayat = EnvironmentOperation::query()->where('environment_id', $gagal->id)->firstOrFail();
        $this->assertSame('failed', $riwayat->status);
        $this->assertSame('hapus-lunak', $riwayat->step);
        $this->assertNotNull($riwayat->failure_message);
        $this->assertNull($riwayat->lease_until, 'Operasi yang sudah selesai tidak boleh terlihat masih memegang kunci.');
    }

    public function test_lingkungan_yang_sedang_dioperasikan_dilewati_tanpa_menghentikan_sapuan(): void
    {
        $sibuk = $this->buat('demo', 'active', 'sibuk', now()->subDays(2));
        $bebas = $this->buat('demo', 'active', 'bebas', now()->subDay());

        EnvironmentOperation::create([
            'environment_id' => $sibuk->id,
            'operation' => 'copy',
            'status' => 'running',
            'step' => 'salin',
            'started_at' => now(),
            'lease_until' => now()->addMinutes(30),
        ]);

        $this->artisan('environment:sapu-kedaluwarsa')->assertExitCode(Command::FAILURE);

        // Penolakannya datang dari partial unique index, dan perintah ini tidak pernah memiliki
        // operasi itu — jadi ia juga tidak boleh menyentuhnya.
        $this->assertNull($sibuk->refresh()->deleted_at);
        $this->assertSame(1, EnvironmentOperation::query()->where('environment_id', $sibuk->id)->count());
        $this->assertSame('running', EnvironmentOperation::query()->where('environment_id', $sibuk->id)->firstOrFail()->status);

        $this->assertSame('soft_deleted', $bebas->refresh()->status);
    }

    // ------------------------------------------------------- pemulihan: jalur hijau

    public function test_demo_kedaluwarsa_dapat_dipulihkan_sebelum_masa_tenggangnya_habis(): void
    {
        $demo = $this->buat('demo', 'active', 'pulih', now()->subDay());

        $this->artisan('environment:sapu-kedaluwarsa')->assertExitCode(Command::SUCCESS);
        $this->assertSame('soft_deleted', $demo->refresh()->status);

        $this->artisan('environment:pulihkan', ['environment' => $demo->id])->assertExitCode(Command::SUCCESS);

        $demo->refresh();
        $this->assertNull($demo->deleted_at);
        $this->assertNull($demo->purge_after);
        $this->assertSame('active', $demo->status, 'Keadaan sebelum disapu yang dikembalikan, dibaca dari riwayatnya.');

        // Dan ini yang membuat pemulihannya berarti. Tanpa masa berlaku baru, baris yang kembali
        // hidup dengan tanggal berakhir di masa lalu ditemukan lagi oleh sapuan berikutnya dan
        // dihapus lunak lagi — pemulihan yang dibatalkan penjadwal dalam hitungan jam, tanpa ada
        // satu pun orang yang melakukan kesalahan.
        $this->assertTrue($demo->expires_at?->isFuture() ?? false);

        $this->artisan('environment:sapu-kedaluwarsa')->assertExitCode(Command::SUCCESS);
        $this->assertNull($demo->refresh()->deleted_at, 'Yang baru dipulihkan tidak boleh langsung tersapu lagi.');

        $riwayat = EnvironmentOperation::query()
            ->where('environment_id', $demo->id)
            ->where('operation', 'restore')
            ->firstOrFail();
        $this->assertSame('succeeded', $riwayat->status);

        $detail = $riwayat->detail;
        $this->assertIsArray($detail);
        $this->assertSame('active', $detail['status_dipulihkan'] ?? null);
        $this->assertFalse($detail['status_ditebak'] ?? true);
    }

    public function test_pemulihan_mengembalikan_keadaan_yang_sebenarnya_bukan_aktif_begitu_saja(): void
    {
        // Sapuan tidak menyaring status, jadi demo yang tidak pernah selesai disiapkan pun ikut
        // tersapu. Memulihkan semuanya menjadi `active` berarti mengangkat lingkungan tanpa
        // database menjadi tempat yang boleh dirutekan, dan kegagalannya muncul di depan
        // penggunanya — bukan di sini.
        $demo = $this->buat('demo', 'degraded', 'rusak', now()->subDay());

        $this->artisan('environment:sapu-kedaluwarsa')->assertExitCode(Command::SUCCESS);
        $this->artisan('environment:pulihkan', ['environment' => $demo->id])->assertExitCode(Command::SUCCESS);

        $this->assertSame('degraded', $demo->refresh()->status);
    }

    public function test_tanpa_riwayat_pemulihan_jatuh_ke_degraded(): void
    {
        // Lingkungan yang dihapus lunak lewat jalur yang tidak mencatat apa pun — termasuk baris
        // yang sudah ada sebelum perintah-perintah ini lahir. `degraded` jawaban yang paling jujur
        // untuk "isinya ada, tetapi tidak ada yang tahu ia sehat", dan `environment:siapkan`
        // menerimanya apa adanya sehingga jalan keluarnya satu perintah.
        $demo = $this->buat('demo', 'active', 'tanpa-jejak', now()->subDay());
        $this->hapusLunakLangsung($demo, now()->addDays(10));

        $this->artisan('environment:pulihkan', ['environment' => $demo->id])->assertExitCode(Command::SUCCESS);

        $this->assertSame('degraded', $demo->refresh()->status);
        $this->assertNull($demo->deleted_at);
        $this->assertNull($demo->purge_after);
    }

    public function test_pemulihan_atas_lingkungan_yang_tidak_dihapus_tidak_mengubah_apa_pun(): void
    {
        $demo = $this->buat('demo', 'active', 'utuh', now()->addDays(10));

        $this->artisan('environment:pulihkan', ['environment' => $demo->id])->assertExitCode(Command::SUCCESS);

        $this->assertSame('active', $demo->refresh()->status);
        $this->assertSame(0, EnvironmentOperation::query()->count(), 'Yang tidak mengerjakan apa-apa tidak meninggalkan riwayat.');
    }

    // ------------------------------------------------------- pemulihan: jalur merah

    public function test_masa_tenggang_yang_sudah_habis_menolak_dipulihkan(): void
    {
        $demo = $this->buat('demo', 'active', 'telat', now()->subDays(60));
        $this->hapusLunakLangsung($demo, now()->subDay());

        $this->artisan('environment:pulihkan', ['environment' => $demo->id])->assertExitCode(Command::FAILURE);

        // Datanya mungkin masih ada — pembuangannya berjalan terjadwal — tetapi ia sudah masuk
        // antrean, dan pemulihan di jendela itu adalah balapan dengan sebuah DROP DATABASE.
        $demo->refresh();
        $this->assertSame('soft_deleted', $demo->status);
        $this->assertNotNull($demo->deleted_at);
        $this->assertSame(0, EnvironmentOperation::query()->count());
    }

    public function test_yang_sudah_dibuang_permanen_menolak_dipulihkan(): void
    {
        $demo = $this->buat('demo', 'active', 'sudah-hilang', now()->subDays(60));
        $this->hapusLunakLangsung($demo, now()->subDay());

        $this->artisan('environment:hapus-permanen')->assertExitCode(Command::SUCCESS);
        $this->assertNotNull($demo->refresh()->purged_at);

        // Memulihkannya menghasilkan baris yang tampil di daftar, terlihat sehat, dan tidak punya
        // satu byte pun data.
        $this->artisan('environment:pulihkan', ['environment' => $demo->id])->assertExitCode(Command::FAILURE);

        $demo->refresh();
        $this->assertSame('soft_deleted', $demo->status);
        $this->assertNotNull($demo->purged_at);
        $this->assertSame(0, EnvironmentOperation::query()->where('operation', 'restore')->count());
    }

    // ------------------------------------------------------- pembuangan: jalur merah

    public function test_produksi_menolak_dibuang_permanen(): void
    {
        $nama = $this->databasePalsu('produksi');
        $produksi = $this->buat('production', 'active', 'produksi', null, $nama);
        $this->hapusLunakLangsung($produksi, now()->subDay());

        // Disebut namanya, bukan sekadar tidak terpilih sapuan. Penolakan yang hanya berbentuk
        // "tidak pernah masuk daftar" adalah penolakan yang hilang pada hari seseorang memanggilnya
        // langsung dari layar operator.
        $this->artisan('environment:hapus-permanen', ['environment' => $produksi->id])
            ->assertExitCode(Command::FAILURE);
        $this->artisan('environment:hapus-permanen')->assertExitCode(Command::SUCCESS);

        $this->assertTrue($this->databaseAda($nama), 'Database produksi tidak boleh disentuh, apa pun statusnya.');
        $this->assertNull($produksi->refresh()->purged_at);
        $this->assertSame(0, EnvironmentOperation::query()->count());
    }

    public function test_produksi_ditolak_database_bukan_hanya_ditolak_perintahnya(): void
    {
        $produksi = $this->buat('production', 'active', 'produksi');
        $this->hapusLunakLangsung($produksi, now()->subDay());

        // Penjagaan yang hanya hidup di kode adalah penjagaan yang hilang pada jalur berikutnya
        // yang lupa memanggilnya. Dibungkus transaksi karena PostgreSQL membatalkan seluruh blok
        // begitu satu pernyataan ditolak — tanpa savepoint, penolakan ini menjatuhkan transaksi
        // milik `RefreshDatabase` dan seluruh sisa test ikut mati.
        try {
            DB::transaction(function () use ($produksi): void {
                Environment::query()->whereKey($produksi->id)->update(['purged_at' => now()]);
            });

            $this->fail('Menandai produksi sebagai dibuang permanen seharusnya ditolak database.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('environments_produksi_tidak_dibuang', $e->getMessage());
        }

        $this->assertNull($produksi->refresh()->purged_at, 'Transaksi test harus selamat lewat savepoint.');
    }

    public function test_membuang_tanpa_pernah_menghapus_lunak_ditolak_database(): void
    {
        $demo = $this->buat('demo', 'active', 'langsung', now()->subDay());

        // Melewati hapus lunak berarti melewati masa tenggang, yaitu melewati satu-satunya jendela
        // tempat sebuah kesalahan masih dapat dibatalkan.
        try {
            DB::transaction(function () use ($demo): void {
                Environment::query()->whereKey($demo->id)->update(['purged_at' => now()]);
            });

            $this->fail('Membuang lingkungan yang belum dihapus lunak seharusnya ditolak database.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('environments_buang_sesudah_hapus_lunak', $e->getMessage());
        }

        $this->assertNull($demo->refresh()->purged_at);
    }

    public function test_masa_tenggang_yang_belum_habis_menolak_dibersihkan(): void
    {
        $nama = $this->databasePalsu('tenggang');
        $demo = $this->buat('demo', 'active', 'tenggang', now()->subDay(), $nama);
        $this->hapusLunakLangsung($demo, now()->addDays(7));

        $this->artisan('environment:hapus-permanen', ['environment' => $demo->id])
            ->assertExitCode(Command::FAILURE);
        $this->artisan('environment:hapus-permanen')->assertExitCode(Command::SUCCESS);

        $this->assertTrue($this->databaseAda($nama), 'Isinya masih harus ada selama ia masih dapat dipulihkan.');
        $this->assertNull($demo->refresh()->purged_at);
        $this->assertSame(0, EnvironmentOperation::query()->count());

        // Dan pasangan hijaunya, di test yang sama: penjaga yang menolak apa saja akan lulus
        // pemeriksaan di atas juga, dan hasilnya disk yang tidak pernah kosong.
        $this->hapusLunakLangsung($demo, now()->subSecond());

        $this->artisan('environment:hapus-permanen')->assertExitCode(Command::SUCCESS);

        $this->assertFalse($this->databaseAda($nama));
        $this->assertNotNull($demo->refresh()->purged_at);
    }

    public function test_yang_belum_dihapus_lunak_tidak_pernah_dibuang(): void
    {
        $nama = $this->databasePalsu('hidup');
        $demo = $this->buat('demo', 'active', 'hidup', now()->subDay(), $nama);

        $this->artisan('environment:hapus-permanen', ['environment' => $demo->id])
            ->assertExitCode(Command::FAILURE);

        $this->assertTrue($this->databaseAda($nama));
        $this->assertNull($demo->refresh()->purged_at);
    }

    // ------------------------------------------------------- pembuangan: jalur hijau

    public function test_database_lingkungan_benar_benar_dibuang_dan_riwayatnya_tinggal(): void
    {
        // Jalur yang sebenarnya berjalan di produksi, dan ia tidak dipalsukan: lingkungannya
        // disiapkan sungguhan lewat `environment:siapkan`, sehingga yang dibuang adalah database
        // berisi seluruh skema Core — bukan database kosong yang dibuat test ini sendiri.
        $demo = $this->buat('demo', 'provisioning', 'siap', now()->subDay());

        $this->artisan('environment:siapkan', ['environment' => $demo->id])->assertExitCode(Command::SUCCESS);

        $nama = (string) $demo->refresh()->database_name;
        $this->assertNotSame('', $nama);
        $this->assertTrue($this->databaseAda($nama));

        $this->artisan('environment:sapu-kedaluwarsa')->assertExitCode(Command::SUCCESS);
        $this->hapusLunakLangsung($demo, now()->subSecond());

        $this->artisan('environment:hapus-permanen')->assertExitCode(Command::SUCCESS);

        $this->assertFalse($this->databaseAda($nama), 'Isinya harus benar-benar hilang; ini satu-satunya tempat yang menghapus.');

        $demo->refresh();
        $this->assertNotNull($demo->purged_at);
        $this->assertSame('soft_deleted', $demo->status, 'Nisannya tetap dihapus lunak; tidak ada status baru yang dikarang.');
        $this->assertSame(
            $nama,
            $demo->database_name,
            'Nama databasenya tinggal. Kosong sudah berarti "ikut koneksi bawaan" di tabel ini, dan itu kebalikan dari yang sebenarnya.',
        );

        // Riwayatnya utuh, dan itu yang dituntut `restrictOnDelete` pada `environment_operations`.
        // Justru riwayat inilah yang dibutuhkan untuk memahami kenapa sebuah lingkungan dibuang,
        // jadi membuang barisnya lebih dulu berarti membuang persis yang paling dibutuhkan.
        $riwayat = EnvironmentOperation::query()
            ->where('environment_id', $demo->id)
            ->pluck('operation')
            ->all();

        $this->assertContains('provision', $riwayat);
        $this->assertContains('expire', $riwayat);
        $this->assertContains('purge', $riwayat);

        $this->assertTrue(
            Environment::query()->whereKey($demo->id)->exists(),
            'Barisnya tidak pernah dihapus fisik; yang dibuang databasenya.',
        );

        // Dan aman diulang. Percobaan yang mati sesudah drop tetapi sebelum pencatatannya harus
        // dapat diselesaikan dengan menjalankan perintah yang sama.
        $sebelum = EnvironmentOperation::query()->where('environment_id', $demo->id)->count();
        $this->artisan('environment:hapus-permanen')->assertExitCode(Command::SUCCESS);
        $this->assertSame($sebelum, EnvironmentOperation::query()->where('environment_id', $demo->id)->count());
    }

    // ------------------------------------------------------- penjadwalan

    public function test_penjadwal_menjalankan_sapuan_dan_menahan_pembuangan_sampai_ada_yang_perlu_dibuang(): void
    {
        $peristiwa = [];

        foreach (app(Schedule::class)->events() as $satu) {
            foreach (['environment:sapu-kedaluwarsa', 'environment:hapus-permanen'] as $perintah) {
                if (str_contains((string) $satu->command, $perintah)) {
                    $peristiwa[$perintah] = $satu;
                }
            }
        }

        $this->assertArrayHasKey('environment:sapu-kedaluwarsa', $peristiwa, 'Sapuan kedaluwarsa harus terdaftar di penjadwal.');
        $this->assertArrayHasKey('environment:hapus-permanen', $peristiwa, 'Pembuangan permanen harus terdaftar di penjadwal.');
        $this->assertSame('0 3 * * *', $peristiwa['environment:sapu-kedaluwarsa']->expression);
        $this->assertSame('30 3 * * *', $peristiwa['environment:hapus-permanen']->expression);

        // Penjaganya membaca registry, bukan `APP_ENV`. `Schedule::environments()` tidak dapat
        // membedakan on-prem dari SaaS sama sekali — keduanya berjalan dengan `APP_ENV=production`
        // — sementara yang menentukan di sini justru isi database.
        $this->buat('demo', 'active', 'terjadwal', now()->addDays(10));
        $this->assertFalse(
            $peristiwa['environment:hapus-permanen']->filtersPass($this->app),
            'Selama tidak ada yang pernah dihapus lunak, perintah yang paling merusak tidak perlu bangun.',
        );

        $this->assertTrue(
            $peristiwa['environment:sapu-kedaluwarsa']->filtersPass($this->app),
            'Sapuan tidak diberi penjaga; ia memang tidak berbahaya ketika tidak ada yang perlu disapu.',
        );

        $demo = $this->buat('demo', 'active', 'menunggu', now()->subDay());
        $this->hapusLunakLangsung($demo, now()->addDays(30));

        $this->assertTrue(
            $peristiwa['environment:hapus-permanen']->filtersPass($this->app),
            'Begitu ada yang menunggu dibuang, penjadwalnya harus jalan.',
        );
    }

    // ------------------------------------------------------- perkakas

    /**
     * Membuat baris environment yang sah menurut seluruh constraint registry.
     *
     * `outbound_allowed` diturunkan dari jenisnya, bukan diterima sebagai parameter:
     * `environments_keluar_ikut_jenis` mengikat keduanya, jadi kombinasi lain memang tidak dapat
     * lahir. Menurunkannya membuat fixture yang salah gagal saat disusun, bukan saat dijalankan.
     */
    private function buat(string $jenis, string $status, string $slug, ?CarbonInterface $berakhir = null, ?string $database = null): Environment
    {
        return Environment::create([
            'tenant_id' => $this->tenant->id,
            'kind' => $jenis,
            'name' => 'Uji '.$slug,
            'slug' => 'ujidaur-'.$slug,
            'database_name' => $database,
            'status' => $status,
            'outbound_allowed' => $jenis === 'production',
            'expires_at' => $jenis === 'demo' ? ($berakhir ?? now()->addDays(30)) : $berakhir,
        ]);
    }

    /**
     * Menghapus lunak sebuah baris tanpa lewat perintahnya.
     *
     * Dipakai untuk menyusun keadaan yang perintahnya sendiri tidak akan pernah hasilkan — masa
     * tenggang yang sudah habis, produksi yang dihapus lunak, baris tanpa riwayat sama sekali.
     * Ketiganya tetap keadaan yang sah menurut database, dan justru itu sebabnya ia perlu diuji.
     */
    private function hapusLunakLangsung(Environment $lingkungan, CarbonInterface $bolehDibuang): void
    {
        Environment::query()->whereKey($lingkungan->id)->update([
            'status' => 'soft_deleted',
            'deleted_at' => $lingkungan->deleted_at ?? now(),
            'purge_after' => $bolehDibuang,
        ]);

        $lingkungan->refresh();
    }

    /**
     * Menanam kegagalan sungguhan pada satu baris, lewat PostgreSQL.
     *
     * Bukan mock. Yang diuji perilaku perintahnya terhadap **pernyataan yang ditolak database**,
     * dan itu punya sifat yang tidak dimiliki pengecualian PHP biasa: ia membatalkan seluruh blok
     * transaksi, sehingga setiap pernyataan sesudahnya ikut gagal sampai ada yang mundur ke sebuah
     * savepoint. Kegagalan yang dipalsukan di lapisan PHP tidak akan pernah membuktikan itu.
     */
    private function tanamKegagalan(Environment $lingkungan): void
    {
        DB::unprepared(
            <<<'SQL'
                CREATE OR REPLACE FUNCTION uji_jatuhkan_sapuan() RETURNS trigger AS $fungsi$
                BEGIN
                    RAISE EXCEPTION 'disk penuh saat menulis baris ini';
                END;
                $fungsi$ LANGUAGE plpgsql;
                SQL
        );

        DB::unprepared(sprintf(
            <<<'SQL'
                CREATE TRIGGER uji_jatuhkan_sapuan_satu_baris
                BEFORE UPDATE ON environments
                FOR EACH ROW WHEN (OLD.id = '%s')
                EXECUTE FUNCTION uji_jatuhkan_sapuan();
                SQL,
            $lingkungan->id,
        ));
    }

    /** Database kosong yang benar-benar ada, supaya "tidak jadi dibuang" dapat dibuktikan. */
    private function databasePalsu(string $akhiran): string
    {
        $nama = self::AWALAN.'_'.$akhiran.'_'.Str::lower(Str::random(6));

        $this->pemelihara()->unprepared(sprintf('CREATE DATABASE "%s"', $nama));

        return $nama;
    }

    private function databaseAda(string $nama): bool
    {
        return $this->pemelihara()->selectOne('select 1 from pg_database where datname = ?', [$nama]) !== null;
    }

    /**
     * Koneksi kedua ke database pusat.
     *
     * `RefreshDatabase` memegang transaksi pada koneksi bawaan, dan `CREATE DATABASE` maupun
     * `DROP DATABASE` dilarang berada di dalam transaksi. PDO terpisah satu-satunya jalan.
     */
    private function pemelihara(): Connection
    {
        $konfigurasi = config('database.connections.'.config('database.default'));
        config(['database.connections.uji_pemelihara' => $konfigurasi]);

        return DB::connection('uji_pemelihara');
    }

    /**
     * Membuang seluruh database yang sempat dibuat test ini.
     *
     * `WITH (FORCE)` memutus sesi yang masih menempel — milik perintah maupun milik test ini
     * sendiri. Tanpa itu satu sesi yang lupa ditutup cukup untuk meninggalkan database yatim.
     */
    private function buangDatabaseUji(): void
    {
        DB::purge('lingkungan_disiapkan');
        DB::purge('lingkungan_pembuang');

        $baris = $this->pemelihara()->select(
            'select datname from pg_database where datname like ? order by datname',
            [self::AWALAN.'%'],
        );

        foreach ($baris as $database) {
            $this->pemelihara()->unprepared(sprintf('DROP DATABASE IF EXISTS "%s" WITH (FORCE)', (string) $database->datname));
        }

        DB::purge('uji_pemelihara');
    }
}
