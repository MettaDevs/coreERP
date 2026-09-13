<?php

namespace Tests\Feature\ControlPlane;

use App\Console\Commands\ProvisionEnvironment;
use App\Models\Client;
use App\Models\Environment;
use App\Models\EnvironmentOperation;
use App\Models\Tenant;
use App\Support\ControlPlane\EnvironmentConnection;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\DropsTestDatabases;
use Tests\TestCase;

/**
 * `environment:provision` benar-benar membuat database, dan benar-benar menolak yang harus ditolak.
 *
 * Test ini menyentuh PostgreSQL di luar transaksi milik `RefreshDatabase` — ia membuat database
 * sungguhan, menjalankan seluruh migration Core ke dalamnya, lalu membuangnya lagi. Itu disengaja:
 * jalur ini tidak punya arti apa pun kalau `CREATE DATABASE` dan migration-nya dipalsukan, dan
 * repo ini sudah pernah membayar harga sebuah jalur yang hanya pernah diuji lewat tiruan.
 *
 * Seluruh database yang dibuat di sini bernama `env_ujisiapkan_...`. Awalan itu ada supaya sisa
 * yang lolos dari pembersihan langsung terbaca sebagai sampah test, bukan milik seseorang.
 */
#[Group('serial')]
class ProvisionEnvironmentTest extends TestCase
{
    use DropsTestDatabases;
    use RefreshDatabase;

    /** Awalan slug tenant uji; ia yang muncul di nama database dan yang dipakai membersihkannya. */
    private const PREFIX = 'env_ujisiapkan';

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
        // `finally`, dan bukan kerapian: pembuangan database yang gagal di sini pernah melewati
        // `parent::tearDown()`, meninggalkan transaksi `RefreshDatabase` terbuka, dan membuat test
        // berikutnya menunggu kuncinya selamanya. Lihat `DropsTestDatabases`.
        try {
            $this->dropTestDatabases();
        } finally {
            parent::tearDown();
        }
    }

    // ---------------------------------------------------------------- jalur merah

    public function test_an_environment_that_does_not_exist_is_rejected(): void
    {
        $this->artisan('environment:provision', ['environment' => (string) Str::ulid()])
            ->assertExitCode(Command::FAILURE);

        $this->assertSame(0, EnvironmentOperation::query()->count());
    }

    public function test_an_active_environment_refuses_to_be_provisioned_again(): void
    {
        $environment = $this->makeEnvironment('active');

        $this->artisan('environment:provision', ['environment' => $environment->id])
            ->assertExitCode(Command::FAILURE);

        // Yang dijaga bukan pesannya melainkan akibatnya: tidak ada operasi yang dibuka, tidak ada
        // database yang dibuat, dan barisnya tidak bergeser sedikit pun.
        $this->assertSame(0, EnvironmentOperation::query()->count());
        $this->assertSame([], $this->createdTestDatabases());
        $this->assertSame('active', $environment->refresh()->status);
    }

    public function test_a_running_operation_refuses_a_second_provisioning(): void
    {
        $environment = $this->makeEnvironment('provisioning');

        EnvironmentOperation::create([
            'environment_id' => $environment->id,
            'operation' => 'copy',
            'status' => 'running',
            'step' => 'salin',
            'started_at' => now(),
            'lease_until' => now()->addMinutes(30),
        ]);

        $this->artisan('environment:provision', ['environment' => $environment->id])
            ->assertExitCode(Command::FAILURE);

        // Penolakannya datang dari partial unique index, bukan dari pemeriksaan di kode — dan
        // karena perintah ini tidak pernah memiliki operasi itu, ia juga tidak boleh menurunkan
        // statusnya. Menurunkannya akan membuat operasi lain yang masih sehat terlihat gagal.
        $this->assertSame(1, EnvironmentOperation::query()->count());
        $this->assertSame('provisioning', $environment->refresh()->status);
        $this->assertSame([], $this->createdTestDatabases());
    }

    public function test_a_failure_leaves_degraded_together_with_its_reason(): void
    {
        $environment = $this->makeEnvironment('provisioning');
        $name = ProvisionEnvironment::databaseName($environment);

        // Kegagalan yang dipentaskan, bukan yang ditiru: database sasaran sudah ada dan sudah
        // berisi tabel `users` dengan bentuk lain, jadi migration pertama Core benar-benar
        // ditolak PostgreSQL.
        $schema = (string) config('database.connections.'.config('database.default').'.search_path');
        $this->maintenance()->unprepared(sprintf('CREATE DATABASE "%s"', $name));
        $target = $this->connectionTo($name);
        $target->statement(sprintf('CREATE SCHEMA IF NOT EXISTS "%s"', $schema));
        $target->statement(sprintf('CREATE TABLE "%s"."users" (sengaja_salah integer)', $schema));
        DB::purge('test_target');

        $this->artisan('environment:provision', ['environment' => $environment->id])
            ->assertExitCode(Command::FAILURE);

        $environment->refresh();
        $this->assertSame('degraded', $environment->status);
        $this->assertNull($environment->database_name, 'Lingkungan yang gagal tidak boleh mengaku punya database.');
        $this->assertNull($environment->schema_migrated_at);

        $operation = EnvironmentOperation::query()->firstOrFail();
        $this->assertSame('failed', $operation->status);
        $this->assertSame('migration', $operation->step);
        $this->assertNotNull($operation->failure_message);
        $this->assertNotSame('', trim((string) $operation->failure_message));
        $this->assertNotNull($operation->finished_at);
    }

    // ---------------------------------------------------------------- jalur hijau

    public function test_provisioning_creates_a_database_that_really_migrated(): void
    {
        $environment = $this->makeEnvironment('provisioning');
        $name = ProvisionEnvironment::databaseName($environment);

        $this->artisan('environment:provision', ['environment' => $environment->id])
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame([$name], $this->createdTestDatabases(), 'Databasenya harus benar-benar ada di pg_database.');
        $this->assertLessThanOrEqual(63, strlen($name), 'Identifier PostgreSQL berhenti di 63 karakter.');

        // Tabelnya ada di sana, bukan hanya databasenya. Sebuah database kosong yang statusnya
        // aktif adalah persis kegagalan yang perintah ini seharusnya cegah.
        $target = $this->connectionTo($name);
        $this->assertTrue($target->getSchemaBuilder()->hasTable('users'));
        $this->assertTrue($target->getSchemaBuilder()->hasTable('tenants'));
        $this->assertTrue($target->getSchemaBuilder()->hasTable('environments'));

        $environment->refresh();
        $this->assertSame('active', $environment->status);
        $this->assertSame($name, $environment->database_name);
        $this->assertNotNull($environment->schema_migrated_at);
        $this->assertSame($this->lastMigration(), $environment->schema_fingerprint);

        $operation = EnvironmentOperation::query()->firstOrFail();
        $this->assertSame('succeeded', $operation->status);
        $this->assertSame('provision', $operation->operation);
        $this->assertNotNull($operation->finished_at);
        $this->assertNull($operation->failure_message);
    }

    public function test_run_again_over_degraded_continues_without_duplicating(): void
    {
        $environment = $this->makeEnvironment('provisioning');
        $name = ProvisionEnvironment::databaseName($environment);

        $this->artisan('environment:provision', ['environment' => $environment->id])
            ->assertExitCode(Command::SUCCESS);

        // Sesuatu di luar perintah ini menjatuhkannya kembali — persis keadaan yang ditinggalkan
        // sebuah penyiapan yang mati di tengah.
        $environment->update(['status' => 'degraded']);

        $this->artisan('environment:provision', ['environment' => $environment->id])
            ->assertExitCode(Command::SUCCESS);

        // Melanjutkan, bukan menggandakan: satu database yang sama, nama yang sama, dan tidak ada
        // migration yang dijalankan dua kali karena riwayatnya hidup di dalam database itu.
        $this->assertSame([$name], $this->createdTestDatabases());

        $environment->refresh();
        $this->assertSame('active', $environment->status);
        $this->assertSame($name, $environment->database_name);
        $this->assertSame($this->lastMigration(), $environment->schema_fingerprint);

        $this->assertSame(2, EnvironmentOperation::query()->count());
        $this->assertSame(2, EnvironmentOperation::query()->where('status', 'succeeded')->count());
    }

    // ---------------------------------------------------------------- perkakas

    private function makeEnvironment(string $status): Environment
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
    private function lastMigration(): string
    {
        $file = glob(database_path('migrations').'/*.php') ?: [];
        $name = array_map(static fn (string $jalur): string => basename($jalur, '.php'), $file);
        sort($name);

        return (string) end($name);
    }

    /**
     * Koneksi kedua ke database pusat.
     *
     * `RefreshDatabase` memegang transaksi pada koneksi bawaan, dan `CREATE DATABASE` maupun
     * `DROP DATABASE` dilarang berada di dalam transaksi. PDO terpisah satu-satunya jalan.
     */
    private function maintenance(): Connection
    {
        $konfigurasi = config('database.connections.'.config('database.default'));
        config(['database.connections.test_maintenance' => $konfigurasi]);

        return DB::connection('test_maintenance');
    }

    private function connectionTo(string $database): Connection
    {
        $konfigurasi = config('database.connections.'.config('database.default'));
        $konfigurasi['database'] = $database;
        $konfigurasi['url'] = null;
        config(['database.connections.test_target' => $konfigurasi]);
        DB::purge('test_target');

        return DB::connection('test_target');
    }

    /** @return list<string> */
    private function createdTestDatabases(): array
    {
        $rows = $this->maintenance()->select(
            'select datname from pg_database where datname like ? order by datname',
            [self::PREFIX.'%'],
        );

        return array_map(static fn (object $d): string => (string) $d->datname, $rows);
    }

    /**
     * Membuang seluruh database yang dibuat test ini.
     *
     * `WITH (FORCE)` memutus sesi yang masih menempel — koneksi milik perintah maupun milik test
     * ini sendiri. Tanpa itu satu sesi yang lupa ditutup cukup untuk meninggalkan database yatim,
     * dan yang berikutnya menumpuk di atasnya.
     */
    private function dropTestDatabases(): void
    {
        DB::purge('test_target');
        DB::purge('environment_provisioning');

        foreach ($this->createdTestDatabases() as $name) {
            $this->dropTestDatabase($this->maintenance(), $name);
        }

        DB::purge('test_maintenance');
    }

    public function test_an_operation_whose_lease_expired_is_taken_over(): void
    {
        $environment = $this->makeEnvironment('provisioning');

        // Bentuk sebuah proses yang mati keras: barisnya dibuka, lalu prosesnya berhenti tanpa
        // pernah menutupnya. Tanpa tenggat, baris ini memegang kuncinya selamanya dan percobaan
        // ulang — satu-satunya pemulihan yang desain ini izinkan — tidak pernah bisa masuk.
        $dead = EnvironmentOperation::create([
            'environment_id' => $environment->id,
            'operation' => 'provision',
            'status' => 'running',
            'step' => 'migration',
            'started_at' => now()->subHours(3),
            'lease_until' => now()->subHours(2),
        ]);

        $this->artisan('environment:provision', ['environment' => $environment->id])
            ->assertExitCode(Command::SUCCESS);

        $dead->refresh();

        $this->assertSame('failed', $dead->status);
        $this->assertNotNull($dead->finished_at);
        $this->assertNull($dead->lease_until);
        // Alasannya menyebut langkah terakhir yang sempat tercapai. Baris yang hanya berbunyi
        // "diambil alih" menghapus satu-satunya petunjuk kenapa environment-nya tertinggal.
        $this->assertStringContainsString('migration', (string) $dead->failure_message);

        $this->assertSame('active', $environment->refresh()->status);
        $this->assertSame(2, EnvironmentOperation::query()->count());
    }

    public function test_an_operation_whose_lease_is_still_alive_is_not_seized(): void
    {
        // Pasangan hijau dari test di atas, dan ia yang membedakan pengambilalihan dari sekadar
        // menabrak kunci orang: penjaga yang merebut apa saja akan lulus test sebelumnya juga.
        $environment = $this->makeEnvironment('provisioning');

        $alive = EnvironmentOperation::create([
            'environment_id' => $environment->id,
            'operation' => 'provision',
            'status' => 'running',
            'step' => 'migration',
            'started_at' => now()->subMinutes(5),
            'lease_until' => now()->addMinutes(25),
        ]);

        $this->artisan('environment:provision', ['environment' => $environment->id])
            ->assertExitCode(Command::FAILURE);

        $this->assertSame('running', $alive->refresh()->status);
        $this->assertSame(1, EnvironmentOperation::query()->count());
        $this->assertSame('provisioning', $environment->refresh()->status);
        $this->assertSame([], $this->createdTestDatabases());
    }

    public function test_a_running_operation_must_carry_its_lease(): void
    {
        // Ditegakkan database, bukan kode. Jalur yang lupa mengisi tenggat persis jalur yang akan
        // melahirkan kembali kebuntuan yang kolom ini ada untuk menutupnya.
        $environment = $this->makeEnvironment('provisioning');

        $this->expectException(QueryException::class);

        EnvironmentOperation::create([
            'environment_id' => $environment->id,
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
     * Sampai langkah `pasang-module` ada, `environment:provision` hanya menjalankan migration Core.
     * Sebuah demo karena itu lahir dengan skema Core lengkap dan **nol tabel module** — dan seluruh
     * test pemasangan module tetap hijau, karena semuanya berjalan di database bawaan, satu-satunya
     * tempat yang memang sudah terisi.
     *
     * Dua assertion terakhir yang membuat test ini berarti, dan keduanya tentang tempat: tabel
     * modulenya ada di database lingkungan, dan catatan pemasangannya **tidak** ada di database
     * pusat. Tanpa yang kedua, pemasangan yang salah alamat tetap lolos.
     */
    public function test_provisioning_installs_the_purchased_modules_into_its_environments_database(): void
    {
        $this->registerApp('contoh-a');
        $this->grant('contoh-a');

        $environment = $this->makeEnvironment('provisioning');
        $name = ProvisionEnvironment::databaseName($environment);

        $this->artisan('environment:provision', ['environment' => $environment->id])
            ->assertExitCode(Command::SUCCESS);

        $target = $this->connectionTo($name);

        $this->assertTrue(
            $target->getSchemaBuilder()->hasTable('contoh_a_m_barang'),
            'Migration module harus berjalan ke database lingkungan, bukan hanya migration Core.',
        );

        $installation = $target->table('core_module_installations')
            ->where('tenant_id', $this->tenant->id)
            ->where('module_id', 'contoh-a')
            ->first();

        $this->assertNotNull($installation, 'Catatan pemasangan hidup di database lingkungan itu sendiri.');
        $this->assertSame('installed', $installation->status);
        $this->assertNotNull($installation->seeded_at, 'Data awal module harus benar-benar diisi, bukan dilewati.');

        $this->assertGreaterThan(
            0,
            $target->table('contoh_a_m_barang')->where('tenant_id', $this->tenant->id)->count(),
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
    public function test_a_module_that_was_not_purchased_is_not_installed(): void
    {
        $this->registerApp('contoh-a');
        $this->registerApp('contoh-b');
        $this->grant('contoh-a');

        $environment = $this->makeEnvironment('provisioning');

        $this->artisan('environment:provision', ['environment' => $environment->id])
            ->assertExitCode(Command::SUCCESS);

        $target = $this->connectionTo(ProvisionEnvironment::databaseName($environment));

        $this->assertTrue($target->getSchemaBuilder()->hasTable('contoh_a_m_barang'));
        $this->assertFalse(
            $target->getSchemaBuilder()->hasTable('contoh_b_m_rak'),
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
    public function test_the_control_plane_side_stays_on_the_control_plane_while_the_connection_is_shifted(): void
    {
        $environment = $this->makeEnvironment('provisioning');

        $this->artisan('environment:provision', ['environment' => $environment->id])
            ->assertExitCode(Command::SUCCESS);

        $environment->refresh();
        $controlPlane = (string) config('database.default');

        app(EnvironmentConnection::class)->runWithin($environment, function () use ($controlPlane): void {
            $shifted = (string) config('database.default');

            $this->assertNotSame($controlPlane, $shifted, 'Koneksi bawaan harus benar-benar bergeser.');
            $this->assertNotNull(
                Tenant::query()->find($this->tenant->id),
                'Model bertanda OwnedByControlPlane harus tetap terbaca dari database pusat.',
            );
            $this->assertSame(
                0,
                DB::connection($shifted)->table('tenants')->count(),
                'Tabel tenants di database lingkungan memang kosong — itu yang membuat assertion di atas berarti.',
            );
        });

        $this->assertSame(
            $controlPlane,
            (string) config('database.default'),
            'Koneksi bawaan harus kembali sesudah blok selesai.',
        );
    }

    /** Baris katalog `apps` untuk sebuah module yang memang ada di folder `modules/`. */
    private function registerApp(string $id): void
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
    private function grant(string $appId): void
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
