<?php

declare(strict_types=1);

namespace Tests\Feature\ControlPlane;

use App\Jobs\UpgradeEnvironment as UpgradeEnvironmentJob;
use App\Models\Client;
use App\Models\Environment;
use App\Models\EnvironmentOperation;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Armada dibaca dari satu panggilan, dan diperbarui tanpa menahan layarnya.
 *
 * Yang paling penting dijaga di sini dua hal yang tidak dapat dibuktikan salah satunya saja.
 * Pertama, **yang sudah mutakhir tidak diantrekan** — kalau ia diantrekan juga, angka "berapa yang
 * sedang berjalan" berhenti berarti apa pun karena ia selalu penuh. Kedua, **kegagalan satu
 * lingkungan tidak menghentikan yang lain** — itu satu-satunya keputusan di jalur ini yang tidak
 * punya sandaran dokumentasi vendor, jadi ia harus punya sandaran test.
 */
final class FleetUpgradeTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-pusat-admin-uji';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('coreerp.control_plane_token', self::TOKEN);

        $client = Client::create(['legal_name' => 'PT Uji Armada', 'slug' => 'uji-armada', 'status' => 'active']);
        $this->tenant = Tenant::create([
            'client_id' => $client->id,
            'name' => 'PT Uji Armada',
            'slug' => 'ujiarmada',
            'status' => 'active',
        ]);
    }

    // ---------------------------------------------------------------- penjaga

    public function test_the_fleet_is_not_readable_without_the_control_plane_token(): void
    {
        $this->getJson('/api/internal/v1/fleet')->assertUnauthorized();
        $this->postJson('/api/internal/v1/environments/upgrade')->assertUnauthorized();
    }

    /**
     * Pemasangan yang memang tidak punya pusat admin menolak semua orang.
     *
     * Jalur merah yang paling mudah ditulis terbalik: string kosong sama dengan string kosong, jadi
     * penjaga yang hanya membandingkan keduanya membuka daftar armada pada setiap pemasangan on-prem.
     */
    public function test_an_installation_without_a_token_refuses_even_an_empty_one(): void
    {
        config()->set('coreerp.control_plane_token', null);

        $this->withToken('')->getJson('/api/internal/v1/fleet')->assertUnauthorized();
        $this->withToken(self::TOKEN)->getJson('/api/internal/v1/fleet')->assertUnauthorized();
    }

    // ---------------------------------------------------------------- membaca keadaan

    public function test_the_fleet_reports_one_state_per_environment(): void
    {
        // Tinggal di database pusat, dan database pusat di dalam test ini memang sudah termigrasi
        // sepenuhnya — jadi ia mutakhir.
        $current = $this->environment('production', 'produksi');

        // Punya database sendiri dengan sidik yang lebih tua daripada isi folder migration.
        $behind = $this->environment('demo', 'tertinggal');
        $behind->forceFill([
            'database_name' => 'env_uji_tertinggal_0000000000',
            'schema_fingerprint' => '2000_01_01_000000_zaman_batu',
            'schema_migrated_at' => now()->subYear(),
        ])->save();

        $broken = $this->environment('sandbox', 'bermasalah');
        $broken->forceFill(['status' => 'maintenance'])->save();

        $response = $this->withToken(self::TOKEN)->getJson('/api/internal/v1/fleet')->assertOk();

        $states = collect($response->json('environments'))->pluck('state', 'id');

        $this->assertSame('current', $states[$current->id]);
        $this->assertSame('behind', $states[$behind->id]);
        $this->assertSame('failed', $states[$broken->id]);

        $this->assertSame(['current' => 1, 'behind' => 1, 'failed' => 1, 'unknown' => 0], $response->json('counts'));

        // Sidik image dibaca dari folder migration, bukan dari database mana pun. Dibacanya dari
        // database akan membuat perbandingannya selalu sama dan berhenti berarti apa pun.
        $this->assertSame($this->lastMigrationOnDisk(), $response->json('platform_fingerprint'));
    }

    public function test_an_environment_being_worked_on_is_neither_current_nor_behind(): void
    {
        $environment = $this->environment('demo', 'sedangjalan');

        EnvironmentOperation::create([
            'environment_id' => $environment->id,
            'operation' => 'migrate',
            'status' => 'running',
            'step' => 'migration-core',
            'started_at' => now(),
            'lease_until' => now()->addMinutes(30),
        ]);

        $response = $this->withToken(self::TOKEN)->getJson('/api/internal/v1/fleet')->assertOk();

        // Membedakannya penting: tombol "Perbarui" pada baris yang sedang berjalan akan ditolak
        // kunci operasinya, dan penolakan itu terbaca seperti kegagalan.
        $this->assertSame('unknown', $response->json('environments.0.state'));
        $this->assertSame('running', $response->json('environments.0.last_operation.status'));
    }

    /**
     * Migration yang diterapkan tidak berurutan tidak boleh membuat semuanya terbaca tertinggal.
     *
     * Ini cacat yang benar-benar terjadi, dan tidak satu pun test menangkapnya: sidik skema dibaca
     * dengan `orderByDesc('id')` — urutan **penerapan** — lalu dibandingkan dengan sidik image yang
     * diturunkan dari daftar berkas terurut **nama**. Keduanya sepakat selama migration diterapkan
     * berurutan, dan test memang selalu begitu.
     *
     * Di database kerja tidak. Dua orang bekerja paralel, salah satunya memberi timestamp yang
     * lebih awal, dan `..._130000_...` diterapkan sesudah `..._140000_...`. Akibatnya endpoint ini
     * melaporkan **setiap** lingkungan tertinggal, selamanya — operator menekan "Perbarui semua",
     * pekerjaannya berjalan, dan angkanya tidak pernah bergerak.
     *
     * Keadaan itu dipentaskan di sini: satu baris `migrations` disisipkan dengan `id` tertinggi dan
     * nama yang justru lebih kecil.
     */
    public function test_migrations_applied_out_of_order_do_not_make_everything_look_behind(): void
    {
        $environment = $this->environment('production', 'produksi');

        DB::table('migrations')->insert([
            'migration' => '1999_01_01_000000_diterapkan_terakhir_bernama_paling_kecil',
            'batch' => 99,
        ]);

        $response = $this->withToken(self::TOKEN)->getJson('/api/internal/v1/fleet')->assertOk();

        $this->assertSame($this->lastMigrationOnDisk(), $response->json('platform_fingerprint'));
        $this->assertSame(
            'current',
            $response->json('environments.0.state'),
            'Sidiknya harus dibaca menurut nama berkas, bukan menurut urutan penerapannya.',
        );
    }

    // ---------------------------------------------------------------- mengantrekan

    public function test_only_the_environments_that_need_it_are_queued(): void
    {
        Queue::fake();

        $this->environment('production', 'produksi');
        $behind = $this->environment('demo', 'tertinggal');
        $behind->forceFill([
            'database_name' => 'env_uji_tertinggal_0000000000',
            'schema_fingerprint' => '2000_01_01_000000_zaman_batu',
        ])->save();

        $response = $this->withToken(self::TOKEN)
            ->postJson('/api/internal/v1/environments/upgrade')
            ->assertStatus(202);

        $this->assertSame([$behind->id], $response->json('queued'));
        $this->assertSame(1, $response->json('queued_count'));

        Queue::assertPushed(UpgradeEnvironmentJob::class, 1);
        Queue::assertPushed(fn (UpgradeEnvironmentJob $job): bool => $job->environmentId === $behind->id);
    }

    /**
     * Pasangan hijau dari yang di atas, dan ia yang membuat penyaringnya berarti.
     *
     * Tanpa ini, "hanya yang perlu" dapat dipenuhi sebuah penyaring yang kebetulan tidak pernah
     * meloloskan apa pun.
     */
    public function test_force_queues_even_what_is_already_current(): void
    {
        Queue::fake();

        $current = $this->environment('production', 'produksi');

        $response = $this->withToken(self::TOKEN)
            ->postJson('/api/internal/v1/environments/upgrade', ['force' => true])
            ->assertStatus(202);

        $this->assertSame([$current->id], $response->json('queued'));
        Queue::assertPushed(UpgradeEnvironmentJob::class, 1);
    }

    public function test_nothing_to_do_is_said_out_loud_rather_than_left_silent(): void
    {
        Queue::fake();

        $this->environment('production', 'produksi');

        $this->withToken(self::TOKEN)
            ->postJson('/api/internal/v1/environments/upgrade')
            ->assertStatus(202)
            ->assertJsonPath('queued_count', 0);

        Queue::assertNothingPushed();
    }

    public function test_an_environment_that_does_not_exist_answers_404(): void
    {
        Queue::fake();

        $this->withToken(self::TOKEN)
            ->postJson('/api/internal/v1/environments/01jbukanlingkunganapapun00/upgrade')
            ->assertNotFound();

        Queue::assertNothingPushed();
    }

    public function test_an_archived_environment_is_never_woken_by_a_release(): void
    {
        Queue::fake();

        $environment = $this->environment('demo', 'diarsipkan');
        $environment->forceFill([
            'status' => 'soft_deleted',
            'deleted_at' => now(),
            'purge_after' => now()->addMonth(),
        ])->save();

        $this->withToken(self::TOKEN)
            ->postJson('/api/internal/v1/environments/upgrade')
            ->assertStatus(202)
            ->assertJsonPath('queued_count', 0);

        Queue::assertNothingPushed();
    }

    // ---------------------------------------------------------------- perintahnya

    /**
     * Lingkungan tanpa database sendiri tetap melewati langkah module.
     *
     * Migration Corenya sudah dijalankan `php artisan migrate` biasa, jadi melewatinya benar. Tetapi
     * melewati langkah modulenya **tidak** benar: tanpa itu, app yang baru dibeli tidak akan pernah
     * terpasang di produksi — satu-satunya tempat kerja yang benar-benar dipakai pelanggan hari ini.
     */
    public function test_an_environment_on_the_control_plane_database_still_records_its_fingerprint(): void
    {
        $environment = $this->environment('production', 'produksi');

        $this->artisan('environment:upgrade', ['environment' => $environment->id])
            ->assertExitCode(Command::SUCCESS);

        $environment->refresh();

        $this->assertSame('active', $environment->status);
        $this->assertSame($this->lastMigrationOnDisk(), $environment->schema_fingerprint);
        $this->assertNotNull($environment->schema_migrated_at);

        $operation = EnvironmentOperation::query()->where('environment_id', $environment->id)->sole();
        $this->assertSame('migrate', $operation->operation);
        $this->assertSame('succeeded', $operation->status);
    }

    /**
     * Kegagalan satu lingkungan tidak menghentikan yang lain.
     *
     * Ini keputusan yang tidak punya sandaran dokumentasi vendor — Business Central, Elastic Jobs,
     * Power Platform, dan AWS tidak satu pun menyatakannya — jadi ia harus punya sandaran test.
     *
     * Kegagalannya dipentaskan, bukan ditiru: `database_name` menunjuk database yang tidak ada, dan
     * perintahnya menolak memperbaruinya.
     *
     * **Versi pertama test ini lulus karena alasan yang sama sekali berbeda**, dan itu layak dicatat.
     * `php artisan migrate --force` **membuat** database yang hilang tanpa bertanya, lalu menyisakan
     * konfigurasi koneksinya menunjuk `postgres` — jadi yang gagal bukan migrationnya melainkan
     * pembacaan sidik sesudahnya. Testnya hijau, assertionnya benar, dan kalimat di dalamnya bohong.
     * Yang menemukannya sebuah database bernama `env_database_ini_tidak_pernah_ada_0000` yang
     * benar-benar muncul di `pg_database`. Perintahnya sekarang memeriksa keberadaan databasenya
     * lebih dulu, dan test ini menguji penjagaan itu.
     */
    public function test_one_failure_does_not_stop_the_rest(): void
    {
        $broken = $this->environment('demo', 'rusak');
        $broken->forceFill(['database_name' => 'env_database_ini_tidak_pernah_ada_0000'])->save();

        $healthy = $this->environment('production', 'produksi');

        $this->artisan('environment:upgrade')->assertExitCode(Command::FAILURE);

        // Yang gagal berhenti dirutekan, dan `maintenance` bukan `degraded`: ia hidup kemarin, jadi
        // pemiliknya sudah tahu ia ada dan 503 yang benar.
        $this->assertSame('maintenance', $broken->refresh()->status);

        // Dan yang sehat tetap dikerjakan. Ini assertion yang membuat test ini ada.
        $this->assertSame('active', $healthy->refresh()->status);
        $this->assertSame($this->lastMigrationOnDisk(), $healthy->schema_fingerprint);

        $failed = EnvironmentOperation::query()->where('environment_id', $broken->id)->sole();
        $this->assertSame('failed', $failed->status);

        // Langkah dan alasannya diperiksa, bukan cuma "gagal". Tanpa keduanya, test ini akan tetap
        // hijau untuk kegagalan apa pun — termasuk kegagalan yang justru menandakan penjaganya bocor.
        $this->assertSame('periksa-database', $failed->step);
        $this->assertStringContainsString('environment:provision', (string) $failed->failure_message);
    }

    public function test_a_running_operation_is_skipped_rather_than_trampled(): void
    {
        $environment = $this->environment('production', 'produksi');

        EnvironmentOperation::create([
            'environment_id' => $environment->id,
            'operation' => 'copy',
            'status' => 'running',
            'step' => 'salin',
            'started_at' => now(),
            'lease_until' => now()->addMinutes(30),
        ]);

        // Berhasil, bukan gagal: dilewati karena sibuk memang bukan kegagalan — itu penjaganya
        // bekerja. Dan barisnya tidak boleh bergeser sedikit pun.
        $this->artisan('environment:upgrade')->assertExitCode(Command::SUCCESS);

        $this->assertSame(1, EnvironmentOperation::query()->count());
        $this->assertNull($environment->refresh()->schema_fingerprint);
    }

    // ---------------------------------------------------------------- perkakas

    private function environment(string $kind, string $slug): Environment
    {
        return Environment::create([
            'tenant_id' => $this->tenant->id,
            'kind' => $kind,
            'name' => 'Uji '.$slug,
            'slug' => $slug,
            'database_name' => null,
            'status' => 'active',
            'outbound_allowed' => $kind === 'production',
            'expires_at' => $kind === 'demo' ? now()->addMonth() : null,
        ]);
    }

    /** Nama berkas migration terakhir menurut urutan yang dipakai Laravel sendiri. */
    private function lastMigrationOnDisk(): string
    {
        $files = glob(database_path('migrations').'/*.php') ?: [];
        $names = array_map(static fn (string $path): string => basename($path, '.php'), $files);
        sort($names);

        return (string) end($names);
    }
}
