<?php

namespace Tests\Feature\ControlPlane;

use App\Console\Commands\SiapkanLingkungan;
use App\Models\Client;
use App\Models\Environment;
use App\Models\EnvironmentOperation;
use App\Models\Tenant;
use App\Support\Pusat\KoneksiLingkungan;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `environment:siapkan` benar-benar membuat database, dan benar-benar menolak yang harus ditolak.
 *
 * Test ini menyentuh PostgreSQL di luar transaksi milik `RefreshDatabase` — ia membuat database
 * sungguhan, menjalankan seluruh migration Core ke dalamnya, lalu membuangnya lagi. Itu disengaja:
 * jalur ini tidak punya arti apa pun kalau `CREATE DATABASE` dan migration-nya dipalsukan, dan
 * repo ini sudah pernah membayar harga sebuah jalur yang hanya pernah diuji lewat tiruan.
 *
 * Seluruh database yang dibuat di sini bernama `env_ujisiapkan_...`. Awalan itu ada supaya sisa
 * yang lolos dari pembersihan langsung terbaca sebagai sampah test, bukan milik seseorang.
 */
class SiapkanLingkunganTest extends TestCase
{
    use RefreshDatabase;

    /** Awalan slug tenant uji; ia yang muncul di nama database dan yang dipakai membersihkannya. */
    private const AWALAN = 'env_ujisiapkan';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $client = Client::create(['legal_name' => 'PT Uji Siapkan', 'slug' => 'uji-siapkan', 'status' => 'active']);
        $this->tenant = Tenant::create([
            'client_id' => $client->id,
            'name' => 'PT Uji Siapkan',
            'slug' => 'ujisiapkan',
            'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        $this->buangDatabaseUji();

        parent::tearDown();
    }

    // ---------------------------------------------------------------- jalur merah

    public function test_environment_yang_tidak_ada_ditolak(): void
    {
        $this->artisan('environment:siapkan', ['environment' => (string) Str::ulid()])
            ->assertExitCode(Command::FAILURE);

        $this->assertSame(0, EnvironmentOperation::query()->count());
    }

    public function test_environment_aktif_menolak_disiapkan_ulang(): void
    {
        $lingkungan = $this->buatLingkungan('active');

        $this->artisan('environment:siapkan', ['environment' => $lingkungan->id])
            ->assertExitCode(Command::FAILURE);

        // Yang dijaga bukan pesannya melainkan akibatnya: tidak ada operasi yang dibuka, tidak ada
        // database yang dibuat, dan barisnya tidak bergeser sedikit pun.
        $this->assertSame(0, EnvironmentOperation::query()->count());
        $this->assertSame([], $this->databaseUji());
        $this->assertSame('active', $lingkungan->refresh()->status);
    }

    public function test_operasi_yang_sedang_berjalan_menolak_penyiapan_kedua(): void
    {
        $lingkungan = $this->buatLingkungan('provisioning');

        EnvironmentOperation::create([
            'environment_id' => $lingkungan->id,
            'operation' => 'copy',
            'status' => 'running',
            'step' => 'salin',
            'started_at' => now(),
            'lease_until' => now()->addMinutes(30),
        ]);

        $this->artisan('environment:siapkan', ['environment' => $lingkungan->id])
            ->assertExitCode(Command::FAILURE);

        // Penolakannya datang dari partial unique index, bukan dari pemeriksaan di kode — dan
        // karena perintah ini tidak pernah memiliki operasi itu, ia juga tidak boleh menurunkan
        // statusnya. Menurunkannya akan membuat operasi lain yang masih sehat terlihat gagal.
        $this->assertSame(1, EnvironmentOperation::query()->count());
        $this->assertSame('provisioning', $lingkungan->refresh()->status);
        $this->assertSame([], $this->databaseUji());
    }

    public function test_kegagalan_meninggalkan_degraded_beserta_alasannya(): void
    {
        $lingkungan = $this->buatLingkungan('provisioning');
        $nama = SiapkanLingkungan::namaDatabase($lingkungan);

        // Kegagalan yang dipentaskan, bukan yang ditiru: database sasaran sudah ada dan sudah
        // berisi tabel `users` dengan bentuk lain, jadi migration pertama Core benar-benar
        // ditolak PostgreSQL.
        $skema = (string) config('database.connections.'.config('database.default').'.search_path');
        $this->pemelihara()->unprepared(sprintf('CREATE DATABASE "%s"', $nama));
        $sasaran = $this->koneksiKe($nama);
        $sasaran->statement(sprintf('CREATE SCHEMA IF NOT EXISTS "%s"', $skema));
        $sasaran->statement(sprintf('CREATE TABLE "%s"."users" (sengaja_salah integer)', $skema));
        DB::purge('uji_sasaran');

        $this->artisan('environment:siapkan', ['environment' => $lingkungan->id])
            ->assertExitCode(Command::FAILURE);

        $lingkungan->refresh();
        $this->assertSame('degraded', $lingkungan->status);
        $this->assertNull($lingkungan->database_name, 'Lingkungan yang gagal tidak boleh mengaku punya database.');
        $this->assertNull($lingkungan->schema_migrated_at);

        $operasi = EnvironmentOperation::query()->firstOrFail();
        $this->assertSame('failed', $operasi->status);
        $this->assertSame('migration', $operasi->step);
        $this->assertNotNull($operasi->failure_message);
        $this->assertNotSame('', trim((string) $operasi->failure_message));
        $this->assertNotNull($operasi->finished_at);
    }

    // ---------------------------------------------------------------- jalur hijau

    public function test_penyiapan_membuat_database_yang_benar_benar_bermigrasi(): void
    {
        $lingkungan = $this->buatLingkungan('provisioning');
        $nama = SiapkanLingkungan::namaDatabase($lingkungan);

        $this->artisan('environment:siapkan', ['environment' => $lingkungan->id])
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame([$nama], $this->databaseUji(), 'Databasenya harus benar-benar ada di pg_database.');
        $this->assertLessThanOrEqual(63, strlen($nama), 'Identifier PostgreSQL berhenti di 63 karakter.');

        // Tabelnya ada di sana, bukan hanya databasenya. Sebuah database kosong yang statusnya
        // aktif adalah persis kegagalan yang perintah ini seharusnya cegah.
        $sasaran = $this->koneksiKe($nama);
        $this->assertTrue($sasaran->getSchemaBuilder()->hasTable('users'));
        $this->assertTrue($sasaran->getSchemaBuilder()->hasTable('tenants'));
        $this->assertTrue($sasaran->getSchemaBuilder()->hasTable('environments'));

        $lingkungan->refresh();
        $this->assertSame('active', $lingkungan->status);
        $this->assertSame($nama, $lingkungan->database_name);
        $this->assertNotNull($lingkungan->schema_migrated_at);
        $this->assertSame($this->migrationTerakhir(), $lingkungan->schema_fingerprint);

        $operasi = EnvironmentOperation::query()->firstOrFail();
        $this->assertSame('succeeded', $operasi->status);
        $this->assertSame('provision', $operasi->operation);
        $this->assertNotNull($operasi->finished_at);
        $this->assertNull($operasi->failure_message);
    }

    public function test_dijalankan_ulang_atas_degraded_melanjutkan_tanpa_menggandakan(): void
    {
        $lingkungan = $this->buatLingkungan('provisioning');
        $nama = SiapkanLingkungan::namaDatabase($lingkungan);

        $this->artisan('environment:siapkan', ['environment' => $lingkungan->id])
            ->assertExitCode(Command::SUCCESS);

        // Sesuatu di luar perintah ini menjatuhkannya kembali — persis keadaan yang ditinggalkan
        // sebuah penyiapan yang mati di tengah.
        $lingkungan->update(['status' => 'degraded']);

        $this->artisan('environment:siapkan', ['environment' => $lingkungan->id])
            ->assertExitCode(Command::SUCCESS);

        // Melanjutkan, bukan menggandakan: satu database yang sama, nama yang sama, dan tidak ada
        // migration yang dijalankan dua kali karena riwayatnya hidup di dalam database itu.
        $this->assertSame([$nama], $this->databaseUji());

        $lingkungan->refresh();
        $this->assertSame('active', $lingkungan->status);
        $this->assertSame($nama, $lingkungan->database_name);
        $this->assertSame($this->migrationTerakhir(), $lingkungan->schema_fingerprint);

        $this->assertSame(2, EnvironmentOperation::query()->count());
        $this->assertSame(2, EnvironmentOperation::query()->where('status', 'succeeded')->count());
    }

    // ---------------------------------------------------------------- perkakas

    private function buatLingkungan(string $status): Environment
    {
        return Environment::create([
            'tenant_id' => $this->tenant->id,
            'kind' => 'production',
            'name' => 'Production',
            'slug' => 'ujisiapkan',
            'database_name' => null,
            'status' => $status,
            'outbound_allowed' => true,
        ]);
    }

    /** Nama berkas migration terakhir menurut urutan yang dipakai Laravel sendiri. */
    private function migrationTerakhir(): string
    {
        $berkas = glob(database_path('migrations').'/*.php') ?: [];
        $nama = array_map(static fn (string $jalur): string => basename($jalur, '.php'), $berkas);
        sort($nama);

        return (string) end($nama);
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

    private function koneksiKe(string $database): Connection
    {
        $konfigurasi = config('database.connections.'.config('database.default'));
        $konfigurasi['database'] = $database;
        $konfigurasi['url'] = null;
        config(['database.connections.uji_sasaran' => $konfigurasi]);
        DB::purge('uji_sasaran');

        return DB::connection('uji_sasaran');
    }

    /** @return list<string> */
    private function databaseUji(): array
    {
        $baris = $this->pemelihara()->select(
            'select datname from pg_database where datname like ? order by datname',
            [self::AWALAN.'%'],
        );

        return array_map(static fn (object $d): string => (string) $d->datname, $baris);
    }

    /**
     * Membuang seluruh database yang dibuat test ini.
     *
     * `WITH (FORCE)` memutus sesi yang masih menempel — koneksi milik perintah maupun milik test
     * ini sendiri. Tanpa itu satu sesi yang lupa ditutup cukup untuk meninggalkan database yatim,
     * dan yang berikutnya menumpuk di atasnya.
     */
    private function buangDatabaseUji(): void
    {
        DB::purge('uji_sasaran');
        DB::purge('lingkungan_disiapkan');

        foreach ($this->databaseUji() as $nama) {
            $this->pemelihara()->unprepared(sprintf('DROP DATABASE IF EXISTS "%s" WITH (FORCE)', $nama));
        }

        DB::purge('uji_pemelihara');
    }

    public function test_operasi_yang_tenggatnya_habis_diambil_alih(): void
    {
        $lingkungan = $this->buatLingkungan('provisioning');

        // Bentuk sebuah proses yang mati keras: barisnya dibuka, lalu prosesnya berhenti tanpa
        // pernah menutupnya. Tanpa tenggat, baris ini memegang kuncinya selamanya dan percobaan
        // ulang — satu-satunya pemulihan yang desain ini izinkan — tidak pernah bisa masuk.
        $mati = EnvironmentOperation::create([
            'environment_id' => $lingkungan->id,
            'operation' => 'provision',
            'status' => 'running',
            'step' => 'migration',
            'started_at' => now()->subHours(3),
            'lease_until' => now()->subHours(2),
        ]);

        $this->artisan('environment:siapkan', ['environment' => $lingkungan->id])
            ->assertExitCode(Command::SUCCESS);

        $mati->refresh();

        $this->assertSame('failed', $mati->status);
        $this->assertNotNull($mati->finished_at);
        $this->assertNull($mati->lease_until);
        // Alasannya menyebut langkah terakhir yang sempat tercapai. Baris yang hanya berbunyi
        // "diambil alih" menghapus satu-satunya petunjuk kenapa environment-nya tertinggal.
        $this->assertStringContainsString('migration', (string) $mati->failure_message);

        $this->assertSame('active', $lingkungan->refresh()->status);
        $this->assertSame(2, EnvironmentOperation::query()->count());
    }

    public function test_operasi_yang_tenggatnya_masih_hidup_tidak_direbut(): void
    {
        // Pasangan hijau dari test di atas, dan ia yang membedakan pengambilalihan dari sekadar
        // menabrak kunci orang: penjaga yang merebut apa saja akan lulus test sebelumnya juga.
        $lingkungan = $this->buatLingkungan('provisioning');

        $hidup = EnvironmentOperation::create([
            'environment_id' => $lingkungan->id,
            'operation' => 'provision',
            'status' => 'running',
            'step' => 'migration',
            'started_at' => now()->subMinutes(5),
            'lease_until' => now()->addMinutes(25),
        ]);

        $this->artisan('environment:siapkan', ['environment' => $lingkungan->id])
            ->assertExitCode(Command::FAILURE);

        $this->assertSame('running', $hidup->refresh()->status);
        $this->assertSame(1, EnvironmentOperation::query()->count());
        $this->assertSame('provisioning', $lingkungan->refresh()->status);
        $this->assertSame([], $this->databaseUji());
    }

    public function test_operasi_berjalan_wajib_membawa_tenggatnya(): void
    {
        // Ditegakkan database, bukan kode. Jalur yang lupa mengisi tenggat persis jalur yang akan
        // melahirkan kembali kebuntuan yang kolom ini ada untuk menutupnya.
        $lingkungan = $this->buatLingkungan('provisioning');

        $this->expectException(QueryException::class);

        EnvironmentOperation::create([
            'environment_id' => $lingkungan->id,
            'operation' => 'provision',
            'status' => 'running',
            'step' => 'mulai',
            'started_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------- module ikut terpasang

    /**
     * Yang dijaga di sini adalah cacat yang tidak ditemukan satu pun test sebelumnya.
     *
     * Sampai langkah `pasang-module` ada, `environment:siapkan` hanya menjalankan migration Core.
     * Sebuah demo karena itu lahir dengan skema Core lengkap dan **nol tabel module** — dan seluruh
     * test pemasangan module tetap hijau, karena semuanya berjalan di database bawaan, satu-satunya
     * tempat yang memang sudah terisi.
     *
     * Dua assertion terakhir yang membuat test ini berarti, dan keduanya tentang tempat: tabel
     * modulenya ada di database lingkungan, dan catatan pemasangannya **tidak** ada di database
     * pusat. Tanpa yang kedua, pemasangan yang salah alamat tetap lolos.
     */
    public function test_penyiapan_memasang_module_yang_dibeli_ke_database_lingkungannya(): void
    {
        $this->daftarkanApp('contoh-a');
        $this->beri('contoh-a');

        $lingkungan = $this->buatLingkungan('provisioning');
        $nama = SiapkanLingkungan::namaDatabase($lingkungan);

        $this->artisan('environment:siapkan', ['environment' => $lingkungan->id])
            ->assertExitCode(Command::SUCCESS);

        $sasaran = $this->koneksiKe($nama);

        $this->assertTrue(
            $sasaran->getSchemaBuilder()->hasTable('contoh_a_m_barang'),
            'Migration module harus berjalan ke database lingkungan, bukan hanya migration Core.',
        );

        $pemasangan = $sasaran->table('core_module_installations')
            ->where('tenant_id', $this->tenant->id)
            ->where('module_id', 'contoh-a')
            ->first();

        $this->assertNotNull($pemasangan, 'Catatan pemasangan hidup di database lingkungan itu sendiri.');
        $this->assertSame('installed', $pemasangan->status);
        $this->assertNotNull($pemasangan->seeded_at, 'Data awal module harus benar-benar diisi, bukan dilewati.');

        $this->assertGreaterThan(
            0,
            $sasaran->table('contoh_a_m_barang')->where('tenant_id', $this->tenant->id)->count(),
            'Seeder module menulis ke database lingkungan.',
        );

        // Sisi pusat tidak boleh ikut ketularan. Kalau salah satu dari keduanya muncul di sini,
        // pemasangannya berjalan ke database yang salah — dan itu persis keadaan sebelum perbaikan.
        $this->assertFalse(
            DB::connection()->getSchemaBuilder()->hasTable('contoh_a_m_barang'),
            'Tabel module tidak boleh lahir di database pusat.',
        );
        $this->assertDatabaseMissing('core_module_installations', [
            'tenant_id' => $this->tenant->id,
            'module_id' => 'contoh-a',
        ]);
    }

    /**
     * Entitlement yang menentukan, bukan katalog dan bukan isi folder `modules/`.
     *
     * Tanpa syarat ini, tiap pelanggan memperoleh setiap module yang pernah ditulis siapa pun —
     * termasuk yang tidak ia bayar, di lingkungan yang justru paling sering diperlihatkan kepada
     * orang luar.
     */
    public function test_module_yang_tidak_dibeli_tidak_ikut_terpasang(): void
    {
        $this->daftarkanApp('contoh-a');
        $this->daftarkanApp('contoh-b');
        $this->beri('contoh-a');

        $lingkungan = $this->buatLingkungan('provisioning');

        $this->artisan('environment:siapkan', ['environment' => $lingkungan->id])
            ->assertExitCode(Command::SUCCESS);

        $sasaran = $this->koneksiKe(SiapkanLingkungan::namaDatabase($lingkungan));

        $this->assertTrue($sasaran->getSchemaBuilder()->hasTable('contoh_a_m_barang'));
        $this->assertFalse(
            $sasaran->getSchemaBuilder()->hasTable('contoh_b_m_rak'),
            'Module yang entitlement-nya tidak ada tidak boleh ikut terpasang.',
        );
    }

    /**
     * Menggeser koneksi bawaan tidak boleh ikut menyeret sisi pusat.
     *
     * Ini bagian yang paling mudah luput dan paling sulit dibaca ketika ia salah. Seeder module
     * yang membaca `Tenant` di tengah pemasangan akan mencarinya di database sandbox, tidak
     * menemukannya, lalu gagal dengan pesan yang tidak menyebut sebabnya sama sekali.
     *
     * Yang menahannya `coreerp.control_connection`, dipasang selama blok berjalan. Assertion
     * ketiga yang membuat dua sebelumnya berarti: tabel `tenants` di database lingkungan memang
     * kosong, jadi baris yang terbaca tadi pasti datang dari pusat.
     */
    public function test_sisi_pusat_tetap_di_pusat_selagi_koneksi_digeser(): void
    {
        $lingkungan = $this->buatLingkungan('provisioning');

        $this->artisan('environment:siapkan', ['environment' => $lingkungan->id])
            ->assertExitCode(Command::SUCCESS);

        $lingkungan->refresh();
        $pusat = (string) config('database.default');

        app(KoneksiLingkungan::class)->jalankanDi($lingkungan, function () use ($pusat): void {
            $digeser = (string) config('database.default');

            $this->assertNotSame($pusat, $digeser, 'Koneksi bawaan harus benar-benar bergeser.');
            $this->assertNotNull(
                Tenant::query()->find($this->tenant->id),
                'Model bertanda MilikPusat harus tetap terbaca dari database pusat.',
            );
            $this->assertSame(
                0,
                DB::connection($digeser)->table('tenants')->count(),
                'Tabel tenants di database lingkungan memang kosong — itu yang membuat assertion di atas berarti.',
            );
        });

        $this->assertSame(
            $pusat,
            (string) config('database.default'),
            'Koneksi bawaan harus kembali sesudah blok selesai.',
        );
    }

    /** Baris katalog `apps` untuk sebuah module yang memang ada di folder `modules/`. */
    private function daftarkanApp(string $id): void
    {
        DB::table('apps')->updateOrInsert(
            ['id' => $id],
            [
                'name' => 'Module '.$id,
                'version' => '1.0.0',
                'status' => 'available',
                'database_name' => str_replace('-', '_', $id),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    /** Entitlement yang berlaku sekarang untuk tenant uji. */
    private function beri(string $appId): void
    {
        DB::table('tenant_app_entitlements')->insert([
            'tenant_id' => $this->tenant->id,
            'app_id' => $appId,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
