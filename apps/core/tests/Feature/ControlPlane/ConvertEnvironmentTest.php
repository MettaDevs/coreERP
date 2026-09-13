<?php

declare(strict_types=1);

namespace Tests\Feature\ControlPlane;

use App\Models\Client;
use App\Models\Environment;
use App\Models\EnvironmentOperation;
use App\Models\Tenant;
use App\Support\ControlPlane\ActiveEnvironment;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\DropsTestDatabases;
use Tests\TestCase;

/**
 * `environment:convert` benar-benar menaikkan demo menjadi produksi, dan benar-benar menolak
 * yang harus ditolak.
 *
 * Tiap penolakan di sini dibuktikan lewat **akibatnya**, bukan lewat kode keluarnya. Sebuah
 * perintah yang mengembalikan FAILURE setelah terlanjur mengubah barisnya lulus pemeriksaan kode
 * keluar dan tetap salah, dan bentuk kegagalan itu tidak terlihat sama sekali dari luar.
 *
 * Dua test menguji constraint registry secara langsung, bukan lewat perintahnya. Keduanya ada
 * karena seluruh bentuk perintah ini ditentukan oleh apa yang database tolak: yang pertama
 * membuktikan konversi naif memang ditolak — sehingga jenis dan bendera keluar wajib berubah dalam
 * satu pernyataan — dan yang kedua membuktikan `environments_sumber_hanya_sandbox` tidak pernah
 * dapat menggigit jalur ini. Tanpa keduanya, komentar panjang di perintahnya hanya klaim.
 */
#[Group('serial')]
class ConvertEnvironmentTest extends TestCase
{
    use DropsTestDatabases;
    use RefreshDatabase;

    /** Awalan slug tenant uji; ia yang muncul di nama database dan yang dipakai membersihkannya. */
    private const PREFIX = 'env_ujikonversi';

    private const RECIPIENT = 'https://procurement.test/events';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $client = Client::create(['legal_name' => 'PT Uji Konversi', 'slug' => 'uji-konversi', 'status' => 'active']);
        $this->tenant = Tenant::create([
            'client_id' => $client->id,
            'name' => 'PT Uji Konversi',
            'slug' => 'ujikonversi',
            'status' => 'active',
        ]);

        // Setelan penerbit event, disalin dari `OutboundDisarmTest`. Penandanya kunci
        // `module`: sebuah penerima yang kodenya dimuat runtime ini akan dilewati penerbit, dan
        // test yang memakai id module yang ada akan hijau tanpa membuktikan apa pun.
        config()->set('coreerp.app_context_signing_key', 'kunci-uji');
        config()->set('coreerp.event_endpoints', [[
            'type' => 'core.workflow.decision.v2',
            'url' => self::RECIPIENT,
            'module' => 'procurement',
        ]]);
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
        $this->artisan('environment:convert', ['environment' => (string) Str::ulid()])
            ->assertExitCode(Command::FAILURE);

        $this->assertSame(0, EnvironmentOperation::query()->count());
    }

    public function test_a_sandbox_is_rejected(): void
    {
        $sandbox = $this->make('sandbox', 'active');

        $this->artisan('environment:convert', ['environment' => $sandbox->id])
            ->assertExitCode(Command::FAILURE);

        // Akibatnya, bukan pesannya: sandbox yang dipromosikan akan menghasilkan dua tempat berisi
        // data yang sama, keduanya boleh menghubungi pihak luar. Yang dijaga di sini justru bendera
        // keluarnya — ia yang membuat salinan itu berbahaya.
        $sandbox->refresh();
        $this->assertSame('sandbox', $sandbox->kind);
        $this->assertFalse($sandbox->outbound_allowed);
        $this->assertSame(0, EnvironmentOperation::query()->count());
    }

    public function test_an_environment_that_is_not_active_yet_is_rejected(): void
    {
        $demo = $this->make('demo', 'provisioning');

        $this->artisan('environment:convert', ['environment' => $demo->id])
            ->assertExitCode(Command::FAILURE);

        // Yang belum selesai disiapkan tidak punya apa pun untuk dipromosikan, dan menaikkannya
        // menghasilkan produksi yang databasenya belum tentu ada.
        $demo->refresh();
        $this->assertSame('demo', $demo->kind);
        $this->assertSame('provisioning', $demo->status);
        $this->assertFalse($demo->outbound_allowed);
        $this->assertSame(0, EnvironmentOperation::query()->count());
    }

    public function test_a_tenant_that_already_has_production_is_rejected(): void
    {
        $production = $this->make('production', 'active');
        $demo = $this->make('demo', 'active');
        $expiresAt = $demo->expires_at;

        $this->artisan('environment:convert', ['environment' => $demo->id])
            ->assertExitCode(Command::FAILURE);

        // Keduanya harus tetap seperti semula. Yang paling mahal kalau penjaganya bocor bukan
        // barisnya melainkan akibatnya: dua tempat sama-sama mengaku produksi, sama-sama boleh
        // menghubungi pihak luar, dengan nomor dokumen yang berjalan sendiri-sendiri.
        $demo->refresh();
        $this->assertSame('demo', $demo->kind);
        $this->assertFalse($demo->outbound_allowed);
        $this->assertNotNull($demo->expires_at);
        $this->assertSame(
            $expiresAt?->toIso8601String(),
            $demo->expires_at?->toIso8601String(),
            'Tanggal berakhir demo tidak boleh dilepas oleh konversi yang ditolak.',
        );

        $production->refresh();
        $this->assertSame('production', $production->kind);
        $this->assertTrue($production->outbound_allowed);

        $this->assertSame(0, EnvironmentOperation::query()->count());
    }

    public function test_a_running_operation_refuses_a_second_conversion(): void
    {
        $demo = $this->make('demo', 'active');

        EnvironmentOperation::create([
            'environment_id' => $demo->id,
            'operation' => 'copy',
            'status' => 'running',
            'step' => 'salin',
            'started_at' => now(),
            'lease_until' => now()->addMinutes(30),
        ]);

        $this->artisan('environment:convert', ['environment' => $demo->id])
            ->assertExitCode(Command::FAILURE);

        // Penolakannya datang dari partial unique index, bukan dari pemeriksaan di kode. Dan karena
        // perintah ini tidak pernah memiliki operasi itu, ia juga tidak boleh menyentuhnya.
        $this->assertSame('demo', $demo->refresh()->kind);
        $this->assertSame(1, EnvironmentOperation::query()->count());
        $this->assertSame('running', EnvironmentOperation::query()->firstOrFail()->status);
    }

    public function test_a_naive_conversion_is_rejected_by_the_database(): void
    {
        $demo = $this->make('demo', 'active');

        // Ini yang menentukan bentuk perintahnya: `environments_keluar_ikut_jenis` mengikat
        // `outbound_allowed` pada jenisnya, jadi menaikkan jenis saja sudah ditolak pada pernyataan
        // pertama. Dibungkus transaksi karena PostgreSQL membatalkan seluruh blok begitu satu
        // pernyataan ditolak — tanpa savepoint, penolakan ini menjatuhkan transaksi milik
        // `RefreshDatabase` dan seluruh sisa test ikut mati.
        try {
            DB::transaction(function () use ($demo): void {
                $demo->update(['kind' => 'production']);
            });

            $this->fail('Menaikkan jenis tanpa menyalakan bendera keluar seharusnya ditolak database.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('environments_keluar_ikut_jenis', $e->getMessage());
        }

        $this->assertSame('demo', $demo->refresh()->kind, 'Transaksi test harus selamat lewat savepoint.');
    }

    public function test_a_demo_can_never_carry_a_source_environment(): void
    {
        $production = $this->make('production', 'active');

        // Pasangan dari test di atas, dan ia membuktikan hal yang berlawanan arah:
        // `environments_sumber_hanya_sandbox` **tidak** menggigit konversi, karena sebuah demo
        // tidak pernah bisa lahir membawa sumber sejak awal. Kalau suatu hari larangan itu
        // dilonggarkan — misalnya supaya demo dapat mencatat template asalnya — test ini merah,
        // dan konversi harus diperiksa ulang sebelum ia diizinkan.
        try {
            DB::transaction(function () use ($production): void {
                Environment::create([
                    'tenant_id' => $this->tenant->id,
                    'kind' => 'demo',
                    'name' => 'Demo bersumber',
                    'slug' => 'demo-bersumber',
                    'status' => 'active',
                    'outbound_allowed' => false,
                    'expires_at' => now()->addDays(30),
                    'source_environment_id' => $production->id,
                ]);
            });

            $this->fail('Demo yang membawa environment sumber seharusnya ditolak database.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('environments_sumber_hanya_sandbox', $e->getMessage());
        }

        $this->assertSame(1, Environment::query()->count());
    }

    // ---------------------------------------------------------------- jalur hijau

    public function test_a_demo_really_becomes_production(): void
    {
        $demo = $this->make('demo', 'active');
        $expiresAt = $demo->expires_at;

        $this->artisan('environment:convert', ['environment' => $demo->id])
            ->assertExitCode(Command::SUCCESS);

        $demo->refresh();
        $this->assertSame('production', $demo->kind);
        $this->assertTrue($demo->outbound_allowed, 'Sambungan keluar harus ikut menyala dalam pernyataan yang sama.');
        $this->assertNull($demo->expires_at, 'Produksi tidak boleh mewarisi tanggal berakhir demo.');
        $this->assertSame('active', $demo->status, 'Konversi tidak menyentuh status; ia tetap dapat dirutekan.');
        $this->assertNull($demo->database_name, 'Konversi di tempat: databasenya tidak berpindah.');
        $this->assertSame('ujikonversi-demo', $demo->slug, 'Alamatnya tidak berubah; itu urusan routing, bukan konversi.');

        $operation = EnvironmentOperation::query()->firstOrFail();
        $this->assertSame('convert', $operation->operation);
        $this->assertSame('succeeded', $operation->status);
        $this->assertNotNull($operation->finished_at);
        $this->assertNull($operation->failure_message);
        $this->assertNull($operation->lease_until);

        // Tanggal berakhir yang dilepas ikut tercatat. Tanpa itu, satu-satunya jejak bahwa
        // lingkungan ini pernah punya masa berlaku hilang bersama konversinya.
        $detail = $operation->detail;
        $this->assertIsArray($detail);
        $this->assertSame('demo', $detail['jenis_sebelumnya'] ?? null);
        $this->assertSame($expiresAt?->toIso8601String(), $detail['berakhir_dilepas'] ?? null);
    }

    public function test_run_again_over_production_changes_nothing(): void
    {
        $demo = $this->make('demo', 'active');

        $this->artisan('environment:convert', ['environment' => $demo->id])
            ->assertExitCode(Command::SUCCESS);

        $after = $demo->refresh()->only(['kind', 'status', 'outbound_allowed', 'expires_at']);

        // Percobaan kedua keluar berhasil, bukan galat: satu-satunya pemulihan yang rancangan ini
        // izinkan adalah menjalankan ulang perintah yang sama, dan galat di sini memaksa orang
        // menebak apakah pekerjaannya sudah selesai.
        $this->artisan('environment:convert', ['environment' => $demo->id])
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame($after, $demo->refresh()->only(['kind', 'status', 'outbound_allowed', 'expires_at']));

        // Dan ia tidak meninggalkan jejak. Riwayat yang penuh operasi yang tidak mengerjakan
        // apa-apa adalah riwayat yang berhenti dibaca orang.
        $this->assertSame(1, EnvironmentOperation::query()->count());
    }

    public function test_the_demo_event_queue_is_not_published_after_the_conversion(): void
    {
        $demo = $this->make('demo', 'active');
        $old = [$this->outbox($this->tenant->id), $this->outbox($this->tenant->id)];

        $this->artisan('environment:convert', ['environment' => $demo->id])
            ->assertExitCode(Command::SUCCESS);

        foreach ($old as $id) {
            $this->assertNotNull(
                DB::table('outbox_events')->where('id', $id)->value('published_at'),
                'Antrean yang ditulis selagi menghubungi luar dilarang harus ditandai terbit saat konversi.',
            );
        }

        // Akibatnya, dan inilah yang sebenarnya dijaga: penerbit berjalan setiap menit dan memilih
        // barisnya tanpa batas umur sama sekali. Kalau antreannya tidak dilucuti, berbulan-bulan
        // keputusan yang dibuat prospek selagi mencoba-coba berangkat ke sistem sungguhan beberapa
        // menit sesudah konversi, tanpa ada yang menekan tombol apa pun.
        Http::fake();
        $this->activate($demo->refresh());
        Artisan::call('workflow-events:publish');

        Http::assertNothingSent();

        $operation = EnvironmentOperation::query()->firstOrFail();
        $detail = $operation->detail;
        $this->assertIsArray($detail);
        $this->assertSame(2, $detail['event_dilucuti'] ?? null);
    }

    public function test_an_event_born_after_the_conversion_is_really_sent(): void
    {
        // Pasangan hijau dari test di atas, dan ia yang membuatnya berarti. Pelucutan yang
        // kebablasan — misalnya yang menandai terbit apa pun selamanya — akan lulus test
        // sebelumnya juga, dan hasilnya produksi yang tidak pernah mengirim satu event pun.
        $demo = $this->make('demo', 'active');
        $this->outbox($this->tenant->id);

        $this->artisan('environment:convert', ['environment' => $demo->id])
            ->assertExitCode(Command::SUCCESS);

        $created = $this->outbox($this->tenant->id);

        Http::fake([self::RECIPIENT => Http::response(['data' => ['accepted' => true]])]);
        $this->activate($demo->refresh());
        Artisan::call('workflow-events:publish');

        Http::assertSent(fn ($request): bool => $request->url() === self::RECIPIENT
            && $request['id'] === $created);
        Http::assertSentCount(1);
        $this->assertNotNull(DB::table('outbox_events')->where('id', $created)->value('published_at'));
    }

    public function test_another_tenants_queue_is_not_disarmed(): void
    {
        // Keadaan pooled: `database_name` kosong berarti lingkungan ini ikut database koneksi
        // bawaan, dan di sana `outbox_events` memuat baris milik tenant yang tidak sedang
        // dikonversi sama sekali. Melucutinya berarti membatalkan pengiriman event pelanggan yang
        // tidak melakukan apa-apa — kebocoran yang tidak berbunyi, hanya event yang tidak pernah
        // sampai.
        $demo = $this->make('demo', 'active');
        $other = $this->secondTenant();

        $demoOwned = $this->outbox($this->tenant->id);
        $otherOwned = $this->outbox($other);

        $this->artisan('environment:convert', ['environment' => $demo->id])
            ->assertExitCode(Command::SUCCESS);

        $this->assertNotNull(DB::table('outbox_events')->where('id', $demoOwned)->value('published_at'));
        $this->assertNull(
            DB::table('outbox_events')->where('id', $otherOwned)->value('published_at'),
            'Konversi hanya boleh menyentuh antrean tenant yang dikonversi.',
        );
    }

    public function test_the_queue_in_the_environments_own_database_is_disarmed(): void
    {
        // Jalur yang sebenarnya akan berjalan di produksi, dan ia tidak dipalsukan: demo yang lahir
        // dari layar operator memperoleh databasenya sendiri lewat `environment:provision`, dan
        // antreannya hidup di sana — bukan di database pusat. Pelucutan yang hanya pernah diuji
        // pada keadaan pooled tidak membuktikan apa pun tentang jalur itu.
        $demo = $this->make('demo', 'provisioning');

        $this->artisan('environment:provision', ['environment' => $demo->id])
            ->assertExitCode(Command::SUCCESS);

        $demo->refresh();
        $this->assertNotNull($demo->database_name);

        $target = $this->connectionTo((string) $demo->database_name);
        $id = (string) Str::ulid();
        $target->table('outbox_events')->insert($this->outboxRows($id, $this->tenant->id));

        $this->artisan('environment:convert', ['environment' => $demo->id])
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame('production', $demo->refresh()->kind);
        $this->assertNotNull(
            $this->connectionTo((string) $demo->database_name)->table('outbox_events')->where('id', $id)->value('published_at'),
            'Antrean di database milik lingkungan itu sendiri yang harus dilucuti.',
        );
    }

    // ---------------------------------------------------------------- perkakas

    /**
     * Membuat baris environment yang sah menurut seluruh constraint registry.
     *
     * `outbound_allowed` dan `expires_at` tidak diterima sebagai parameter bebas: keduanya terikat
     * pada jenisnya oleh `environments_keluar_ikut_jenis` dan `environments_demo_berakhir`, jadi
     * kombinasi lain memang tidak dapat lahir. Menurunkannya dari jenisnya membuat test yang salah
     * gagal saat disusun, bukan saat dijalankan.
     */
    private function make(string $kind, string $status): Environment
    {
        return Environment::create([
            'tenant_id' => $this->tenant->id,
            'kind' => $kind,
            'name' => 'Uji '.$kind,
            'slug' => 'ujikonversi-'.$kind,
            'database_name' => null,
            'status' => $status,
            'outbound_allowed' => $kind === 'production',
            'expires_at' => $kind === 'demo' ? now()->addDays(30) : null,
        ]);
    }

    /** Tenant kedua yang berbagi database yang sama — keadaan pooled, dan ia yang diuji. */
    private function secondTenant(): string
    {
        $client = Client::create(['legal_name' => 'PT Tetangga', 'slug' => 'tetangga', 'status' => 'active']);

        return Tenant::create([
            'client_id' => $client->id,
            'name' => 'PT Tetangga',
            'slug' => 'tetangga',
            'status' => 'active',
        ])->id;
    }

    private function outbox(string $tenantId): string
    {
        $id = (string) Str::ulid();

        DB::table('outbox_events')->insert($this->outboxRows($id, $tenantId));

        return $id;
    }

    /** @return array<string, mixed> */
    private function outboxRows(string $id, string $tenantId): array
    {
        return [
            'id' => $id,
            'tenant_id' => $tenantId,
            'correlation_id' => (string) Str::ulid(),
            'type' => 'core.workflow.decision.v2',
            'payload' => json_encode(['decision' => 'approved'], JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * Mengikat lingkungan yang sedang dikerjakan, seperti yang kelak dilakukan penjadwal.
     *
     * Instansnya dilupakan lebih dulu karena `ActiveEnvironment` memoisasi jawabannya.
     */
    private function activate(Environment $environment): void
    {
        $this->app->forgetInstance(ActiveEnvironment::class);
        $this->app->instance(ActiveEnvironment::KEY, $environment->id);
    }

    /**
     * Koneksi kedua ke database pusat.
     *
     * `RefreshDatabase` memegang transaksi pada koneksi bawaan, dan `DROP DATABASE` dilarang berada
     * di dalam transaksi. PDO terpisah satu-satunya jalan.
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
     * `WITH (FORCE)` memutus sesi yang masih menempel — milik perintah maupun milik test ini
     * sendiri. Tanpa itu satu sesi yang lupa ditutup cukup untuk meninggalkan database yatim.
     */
    private function dropTestDatabases(): void
    {
        DB::purge('test_target');
        DB::purge('environment_provisioning');
        DB::purge('environment_convert');

        foreach ($this->createdTestDatabases() as $name) {
            $this->dropTestDatabase($this->maintenance(), $name);
        }

        DB::purge('test_maintenance');
    }
}
