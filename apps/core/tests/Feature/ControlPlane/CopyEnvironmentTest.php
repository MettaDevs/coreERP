<?php

declare(strict_types=1);

namespace Tests\Feature\ControlPlane;

use App\Console\Commands\CopyEnvironment;
use App\Console\Commands\ProvisionEnvironment;
use App\Models\Client;
use App\Models\Environment;
use App\Models\EnvironmentOperation;
use App\Models\Tenant;
use App\Support\ControlPlane\ActiveEnvironment;
use App\Support\ControlPlane\OutboundRefused;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * `environment:copy` benar-benar menyalin, dan salinannya benar-benar dilucuti.
 *
 * Test ini menyentuh PostgreSQL di luar transaksi milik `RefreshDatabase`: ia membuat dua database
 * sungguhan, menjalankan `pg_dump` dan `pg_restore` sungguhan di antara keduanya, lalu membuangnya
 * lagi. Itu disengaja. Jalur ini tidak punya arti apa pun kalau penyalinannya ditiru — sebuah
 * pelucutan yang hijau di atas tabel yang tidak pernah benar-benar tersalin tidak membuktikan
 * apa-apa, dan repo ini sudah dua kali membayar harga penjaga yang hijau karena buta.
 *
 * ## Tiga pembuktian yang menopang seluruhnya, dan yang ketiga yang membuat dua lainnya berarti
 *
 * 1. Sandbox hasil salinan **tidak dapat** menghubungi luar — panggilan HTTP melempar, dan
 *    penerbit event yang berjalan di penjadwal tidak mengirim satu pun.
 * 2. Event yang belum terbit di dalam salinannya **terbukti dilucuti**, beserta ekspor yang antre,
 *    reservasi yang menggantung, antrean job, kredensial layanan, dan registry yang ikut tersalin.
 * 3. Produksi yang baru saja disalin **terbukti masih mengirim**, dan antreannya **terbukti masih
 *    utuh**. Sebuah pelucutan yang diam-diam mengenai sumbernya juga akan lulus pembuktian 1 dan 2.
 *
 * Seluruh database yang dibuat di sini bernama `env_ujisalin_...`. Awalan itu ada supaya sisa yang
 * lolos dari pembersihan langsung terbaca sebagai sampah test, bukan milik seseorang.
 */
#[Group('serial')]
class CopyEnvironmentTest extends TestCase
{
    use RefreshDatabase;

    /** Awalan slug tenant uji; ia yang muncul di nama database dan yang dipakai membersihkannya. */
    private const PREFIX = 'env_ujisalin';

    private const WEBHOOK = 'https://procurement.test/events';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $client = Client::create(['legal_name' => 'PT Uji Salin', 'slug' => 'uji-salin', 'status' => 'active']);
        $this->tenant = Tenant::create([
            'client_id' => $client->id,
            'name' => 'PT Uji Salin',
            'slug' => 'ujisalin',
            'status' => 'active',
        ]);

        config()->set('coreerp.app_context_signing_key', 'kunci-uji');
        config()->set('coreerp.event_endpoints', [[
            'type' => 'core.workflow.decision.v2',
            'url' => self::WEBHOOK,
            'module' => 'procurement',
        ]]);
    }

    protected function tearDown(): void
    {
        $this->dropTestDatabases();

        parent::tearDown();
    }

    // ------------------------------------------------------------------ jalur merah: penolakan

    public function test_a_source_that_does_not_exist_is_rejected(): void
    {
        $this->artisan('environment:copy', ['source' => (string) Str::ulid()])
            ->assertExitCode(Command::FAILURE);

        $this->assertSame(0, EnvironmentOperation::query()->count());
        $this->assertSame([], $this->testDatabase());
    }

    public function test_a_sandbox_may_not_be_copied(): void
    {
        // Salinan dari salinan: tidak ada seorang pun yang dapat mengatakan data di dalamnya
        // berasal dari kapan, dan setiap pelucutan yang sudah berjalan di sumbernya ikut tersalin
        // sebagai keadaan "normal".
        $sandbox = $this->environment('sandbox', 'kotak-pasir', 'env_ujisalin_palsu_1');

        $this->artisan('environment:copy', ['source' => $sandbox->id])
            ->assertExitCode(Command::FAILURE);

        $this->assertSame(0, EnvironmentOperation::query()->count());
        $this->assertSame(1, Environment::query()->count(), 'Sasaran tidak boleh lahir dari penolakan.');
    }

    public function test_a_source_without_its_own_database_is_rejected(): void
    {
        // `database_name` yang kosong berarti lingkungan itu ikut koneksi bawaan — keadaan pooled
        // dan on-prem, tempat satu database memuat data banyak tenant. Menyalinnya adalah
        // menyerahkan data pelanggan lain ke dalam sandbox milik satu pelanggan, dan itu kebocoran
        // yang tidak boleh diperlakukan sebagai kasus tepi.
        $production = $this->environment('production', 'ujisalin', null);

        $this->artisan('environment:copy', ['source' => $production->id])
            ->assertExitCode(Command::FAILURE);

        $this->assertSame(0, EnvironmentOperation::query()->count());
    }

    public function test_a_name_that_points_at_the_production_environment_is_rejected(): void
    {
        // Ini "tolak bila sasaran produksi", dan bentuknya bukan hipotetis: sasaran ditentukan slug,
        // dan slug diturunkan dari nama yang diketik operator. Satu kata yang kebetulan sama sudah
        // cukup untuk menunjuk database yang sedang dipakai bekerja.
        $production = $this->environment('production', 'ujisalin', 'env_ujisalin_palsu_2');

        $this->artisan('environment:copy', ['source' => $production->id, '--name' => 'Ujisalin'])
            ->assertExitCode(Command::FAILURE);

        $this->assertSame(0, EnvironmentOperation::query()->count());
        $this->assertSame(1, Environment::query()->count());
        $this->assertSame([], $this->testDatabase(), 'Tidak satu pun database boleh lahir dari penolakan.');
    }

    public function test_another_copy_still_alive_refuses_a_second_copy(): void
    {
        $production = $this->environment('production', 'ujisalin', 'env_ujisalin_palsu_3');
        $otherSandbox = $this->environment('sandbox', 'kotak-satu', null);

        EnvironmentOperation::create([
            'environment_id' => $otherSandbox->id,
            'operation' => 'copy',
            'source_environment_id' => $production->id,
            'status' => 'running',
            'step' => 'salin',
            'started_at' => now(),
            'lease_until' => now()->addMinutes(20),
        ]);

        $this->artisan('environment:copy', ['source' => $production->id, '--name' => 'Kotak Dua'])
            ->assertExitCode(Command::FAILURE);

        // Penolakannya tidak boleh menyentuh operasi yang sehat, dan tidak boleh meninggalkan
        // sasaran baru yang setengah lahir.
        $this->assertSame(1, EnvironmentOperation::query()->count());
        $this->assertSame('running', EnvironmentOperation::query()->firstOrFail()->status);
        $this->assertSame(2, Environment::query()->count());
    }

    public function test_the_index_refuses_two_running_copies_from_the_same_source(): void
    {
        // Yang menegakkan "satu salinan pada satu waktu" adalah indeks, bukan pemeriksaan di dalam
        // perintahnya. Tanpa test ini, pemeriksaan itu dapat dihapus seseorang dan seluruh suite
        // tetap hijau — sementara dua `pg_dump` membaca database yang sama sekaligus.
        $production = $this->environment('production', 'ujisalin', 'env_ujisalin_palsu_4');

        foreach (['kotak-satu', 'kotak-dua'] as $i => $slug) {
            $sandbox = $this->environment('sandbox', $slug, null);

            if ($i === 1) {
                $this->expectException(QueryException::class);
            }

            EnvironmentOperation::create([
                'environment_id' => $sandbox->id,
                'operation' => 'copy',
                'source_environment_id' => $production->id,
                'status' => 'running',
                'step' => 'salin',
                'started_at' => now(),
                'lease_until' => now()->addMinutes(20),
            ]);
        }
    }

    // ------------------------------------------------------------------ jalur hijau: salinan nyata

    public function test_copying_produces_a_sandbox_that_really_has_contents(): void
    {
        $source = $this->realProduction();
        $seed = $this->seedSource($source);

        $this->artisan('environment:copy', ['source' => $source->id, '--name' => 'Kotak Uji'])
            ->assertExitCode(Command::SUCCESS);

        $sandbox = $this->lastSandbox();
        $databaseName = ProvisionEnvironment::databaseName($sandbox);

        $this->assertSame('sandbox', $sandbox->kind);
        $this->assertSame('active', $sandbox->status);
        $this->assertFalse($sandbox->outbound_allowed, 'Sandbox tidak pernah lahir dengan sambungan keluar menyala.');
        $this->assertSame($source->id, $sandbox->source_environment_id);
        $this->assertSame($databaseName, $sandbox->database_name);
        $this->assertNotNull($sandbox->copied_at);
        $this->assertNotNull($sandbox->schema_fingerprint);
        $this->assertContains($databaseName, $this->testDatabase(), 'Databasenya harus benar-benar ada di pg_database.');

        // Datanya benar-benar menyeberang, bukan hanya skemanya. Sebuah database yang bermigrasi
        // tanpa satu baris pun terlihat persis seperti penyalinan yang berhasil.
        $copy = $this->connectionTo($databaseName, 'test_copy');
        $this->assertSame(1, $copy->table('tenants')->where('id', $this->tenant->id)->count());
        $this->assertSame(
            $seed['modul'],
            $copy->table('core_module_installations')->count(),
            'Catatan pemasangan module tidak pernah disentuh: sandbox tanpa module terpasang bukan salinan.',
        );

        $operation = EnvironmentOperation::query()->where('operation', 'copy')->firstOrFail();
        $this->assertSame('succeeded', $operation->status);
        $this->assertSame($source->id, $operation->source_environment_id);
        $this->assertSame($sandbox->id, $operation->environment_id);
        $this->assertNull($operation->lease_until, 'Operasi selesai yang masih membawa tenggat terbaca seolah masih memegang sesuatu.');
    }

    /**
     * Pembuktian kedua: apa yang ikut tersalin benar-benar dilucuti di dalam salinannya.
     *
     * Tiap baris di sini punya bahayanya sendiri, dan bahaya itu bukan teori: penerbit event
     * berjalan di penjadwal setiap menit, worker ekspor mengambil antreannya sendiri, dan
     * `number-sequences:recover` mengaduk reservasi yang menggantung. Ketiganya akan menyala di
     * sandbox beberapa menit sesudah salinannya selesai, tanpa ada yang menekan tombol apa pun.
     */
    public function test_the_copy_is_disarmed_entirely(): void
    {
        $source = $this->realProduction();
        $seed = $this->seedSource($source);

        $this->artisan('environment:copy', ['source' => $source->id, '--name' => 'Kotak Uji'])
            ->assertExitCode(Command::SUCCESS);

        $copy = $this->connectionTo((string) $this->lastSandbox()->database_name, 'test_copy');

        // 1 — antrean event: bahaya paling konkret yang benar-benar ada di repo hari ini.
        $this->assertSame(0, $copy->table('outbox_events')->whereNull('published_at')->count());
        $this->assertNotNull($copy->table('outbox_events')->where('id', $seed['event'])->value('published_at'));

        // 2 — ekspor yang antre, beserta alasan berbahasa Indonesia yang dibaca orang yang menunggunya.
        $export = $copy->table('report_exports')->where('id', $seed['ekspor'])->first();
        $this->assertIsObject($export);
        $this->assertSame('failed', $export->status);
        $this->assertStringContainsString('salinan', (string) $export->failure_message);

        // 3 — reservasi nomor, beserta nomor yang dipegangnya di kolam. Reservasi yang dibatalkan
        // tanpa melepas nomornya adalah nomor yang hilang selamanya, dan pada urutan berkelanjutan
        // satu nomor yang hilang menghentikan seluruhnya.
        $this->assertSame('cancelled', $copy->table('number_sequence_reservations')->where('id', $seed['reservasi'])->value('status'));
        $this->assertSame('available', $copy->table('number_sequence_continuous_pool')->value('status'));
        $this->assertNull($copy->table('number_sequence_continuous_pool')->value('reservation_id'));

        // 4 dan 5 — rencananya menyebut keduanya gratis karena mereka "di sisi pusat". Pembagian
        // itu belum berdiri, jadi keduanya ikut tersalin utuh dan harus dibuang di sini.
        $this->assertSame(0, $copy->table('jobs')->count(), 'Job produksi yang belum dikerjakan akan dikerjakan ulang oleh worker sandbox.');
        $this->assertSame(0, $copy->table('app_service_credentials')->count(), 'Sandbox tidak boleh lahir memegang token layanan produksi.');

        // 6 — registry yang ikut tersalin ke dalam salinannya sendiri, dan ini yang paling mudah
        // terlewat: di dalamnya tertulis `production` dengan `outbound_allowed = true`, dan itulah
        // yang dibaca `ActiveEnvironment` begitu ada jalur yang menjadikan database ini koneksi
        // bawaan. Satu tabel yang tidak ada yang mengira ikut tersalin membatalkan lima pelucutan
        // di atasnya sekaligus.
        $this->assertSame(0, $copy->table('environments')->where('outbound_allowed', true)->count());
        $this->assertSame(0, $copy->table('environments')->where('kind', 'production')->count());
    }

    /**
     * Pembuktian ketiga, dan ia yang membuat dua lainnya berarti.
     *
     * Sebuah pelucutan yang keliru mengenai sumbernya — koneksi yang salah, `url` yang menang di
     * atas `database`, PDO kedua yang membaca database pusat — akan menghasilkan sandbox yang
     * bersih dan produksi yang lumpuh, dan kedua test di atas tetap hijau. Yang membedakannya hanya
     * pemeriksaan ini.
     */
    public function test_the_production_just_copied_is_still_intact_and_still_sends(): void
    {
        $source = $this->realProduction();
        $seed = $this->seedSource($source);

        $this->artisan('environment:copy', ['source' => $source->id, '--name' => 'Kotak Uji'])
            ->assertExitCode(Command::SUCCESS);

        // 3a — antrean produksi masih utuh. Event yang belum terbit di sana memang belum terbit.
        $production = $this->connectionTo((string) $source->database_name, 'test_source');
        $this->assertNull($production->table('outbox_events')->where('id', $seed['event'])->value('published_at'));
        $this->assertSame('queued', $production->table('report_exports')->where('id', $seed['ekspor'])->value('status'));
        $this->assertSame('reserved', $production->table('number_sequence_reservations')->where('id', $seed['reservasi'])->value('status'));
        $this->assertSame(1, $production->table('jobs')->count());
        $this->assertSame(1, $production->table('app_service_credentials')->count());
        $this->assertTrue((bool) $production->table('environments')->where('kind', 'production')->value('outbound_allowed'));

        // 3b — dan jalur yang sama benar-benar mengirim dari produksi. Penjaga yang menolak
        // segalanya lulus pembuktian pertama juga; hanya baris ini yang membedakannya.
        $this->activate($source);
        $id = $this->controlPlaneOutbox();
        Http::fake([self::WEBHOOK => Http::response(['data' => ['accepted' => true]])]);

        Artisan::call('workflow-events:publish');

        Http::assertSent(fn ($request): bool => $request->url() === self::WEBHOOK && $request['id'] === $id);
        $this->assertNotNull(DB::table('outbox_events')->where('id', $id)->value('published_at'));
    }

    /**
     * Pembuktian pertama: sandbox yang lahir dari penyalinan tidak dapat menghubungi luar.
     *
     * Dua titik, dan keduanya perlu. Penerbit event adalah jalur yang benar-benar berjalan sendiri
     * di penjadwal; jaring global adalah lapis terakhir untuk panggilan yang belum ditulis siapa
     * pun. Yang dibuktikan di sini bukan bahwa penjaganya ada — itu sudah dijaga test lain —
     * melainkan bahwa **baris yang lahir dari perintah ini** memang jatuh di sisi yang menolak.
     */
    public function test_the_sandbox_born_from_the_copy_cannot_contact_the_outside(): void
    {
        $source = $this->realProduction();
        $this->seedSource($source);

        $this->artisan('environment:copy', ['source' => $source->id, '--name' => 'Kotak Uji'])
            ->assertExitCode(Command::SUCCESS);

        $this->activate($this->lastSandbox());
        $id = $this->controlPlaneOutbox();
        Http::fake();

        Artisan::call('workflow-events:publish');

        Http::assertNothingSent();
        $this->assertNotNull(
            DB::table('outbox_events')->where('id', $id)->value('published_at'),
            'Barisnya dibiarkan menggantung; perintah ini berjalan di penjadwal, jadi ia akan '
            .'diambil ulang selamanya dan menutupi baris yang benar-benar gagal terkirim.',
        );

        $this->expectException(OutboundRefused::class);
        Http::get('https://sistem-pelanggan.test/webhook');
    }

    public function test_a_copy_that_stopped_halfway_is_continued_by_the_same_command(): void
    {
        $source = $this->realProduction();
        $this->seedSource($source);

        $this->artisan('environment:copy', ['source' => $source->id, '--name' => 'Kotak Uji'])
            ->assertExitCode(Command::SUCCESS);

        $sandbox = $this->lastSandbox();
        $databaseName = (string) $sandbox->database_name;

        // Bentuk sebuah proses yang mati sesudah restore tetapi sebelum sempat mengaktifkan
        // apa pun: barisnya turun ke degraded, dan operasinya menggantung dengan tenggat yang
        // sudah lewat. Tanpa keduanya, satu-satunya pemulihan yang desain ini izinkan —
        // menjalankan ulang perintahnya — tidak pernah bisa masuk.
        $sandbox->update(['status' => 'degraded']);
        EnvironmentOperation::query()->where('operation', 'copy')->update([
            'status' => 'running',
            'finished_at' => null,
            'lease_until' => now()->subHour(),
        ]);

        $this->artisan('environment:copy', ['source' => $source->id, '--name' => 'Kotak Uji'])
            ->assertExitCode(Command::SUCCESS);

        // Melanjutkan, bukan menggandakan: sandbox yang sama, database yang sama, dan tidak ada
        // database ketiga yang lahir.
        $sandbox->refresh();
        $this->assertSame('active', $sandbox->status);
        $this->assertSame($databaseName, $sandbox->database_name);
        $this->assertSame(2, Environment::query()->count());
        $this->assertCount(2, $this->testDatabase());

        $operation = EnvironmentOperation::query()->where('operation', 'copy')->get();
        $this->assertCount(2, $operation);
        $this->assertSame(1, $operation->where('status', 'succeeded')->count());

        $dead = $operation->firstWhere('status', 'failed');
        $this->assertInstanceOf(EnvironmentOperation::class, $dead);
        $this->assertNull($dead->lease_until);
        // Alasannya menyebut langkah terakhir yang sempat tercapai. Baris yang hanya berbunyi
        // "diambil alih" menghapus satu-satunya petunjuk kenapa penyalinannya tertinggal.
        $this->assertStringContainsString('Tenggatnya habis', (string) $dead->failure_message);
    }

    // ------------------------------------------------------------------ perkakas

    /** Baris registry tanpa database sungguhan; cukup untuk penolakan yang berhenti sebelum bekerja. */
    private function environment(string $kind, string $slug, ?string $database): Environment
    {
        return Environment::create([
            'tenant_id' => $this->tenant->id,
            'kind' => $kind,
            'name' => Str::headline($slug),
            'slug' => $slug,
            'database_name' => $database,
            'status' => 'active',
            'outbound_allowed' => $kind === 'production',
        ]);
    }

    /** Produksi dengan database sungguhan, dibuat lewat jalur yang sebenarnya dipakai operator. */
    private function realProduction(): Environment
    {
        if (! CopyEnvironment::clientsAvailable()) {
            $this->markTestSkipped(
                'pg_dump atau pg_restore tidak ada di PATH mesin ini, jadi penyalinan tidak dapat '
                .'dibuktikan. Pasang klien PostgreSQL (versi klien harus >= versi server), lalu '
                .'jalankan lagi. Test ini sengaja dilewati alih-alih dipalsukan: penyalinan yang '
                .'ditiru tidak membuktikan apa pun.'
            );
        }

        $production = $this->environment('production', 'ujisalin', null);
        $production->update(['status' => 'provisioning']);

        $this->artisan('environment:provision', ['environment' => $production->id])
            ->assertExitCode(Command::SUCCESS);

        return $production->refresh();
    }

    /**
     * Mengisi database produksi dengan persis hal-hal yang berbahaya kalau ikut tersalin.
     *
     * @return array{event:string,ekspor:string,reservasi:string,modul:int}
     */
    private function seedSource(Environment $source): array
    {
        $k = $this->connectionTo((string) $source->database_name, 'test_source');
        $now = now();
        $tenantId = $this->tenant->id;

        $clientId = (string) Str::ulid();
        $k->table('clients')->insert([
            'id' => $clientId, 'legal_name' => 'PT Uji Salin', 'slug' => 'uji-salin',
            'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);

        $k->table('tenants')->insert([
            'id' => $tenantId, 'client_id' => $clientId, 'name' => 'PT Uji Salin',
            'slug' => 'ujisalin', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);

        // Registry yang ikut tersalin: di dalamnya produksi, dengan sambungan keluar menyala.
        $k->table('environments')->insert([
            'id' => $source->id, 'tenant_id' => $tenantId, 'kind' => 'production',
            'name' => 'Production', 'slug' => 'ujisalin', 'database_name' => null, 'status' => 'active',
            'outbound_allowed' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $k->table('apps')->insert([
            'id' => 'uji-app', 'name' => 'App Uji', 'version' => '1.0.0', 'status' => 'available',
            'database_name' => 'uji_app', 'created_at' => $now, 'updated_at' => $now,
        ]);

        $event = (string) Str::ulid();
        $k->table('outbox_events')->insert([
            'id' => $event, 'tenant_id' => $tenantId, 'correlation_id' => (string) Str::ulid(),
            'type' => 'core.workflow.decision.v2',
            'payload' => json_encode(['decision' => 'approved'], JSON_THROW_ON_ERROR),
            'occurred_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $export = (string) Str::ulid();
        $k->table('report_exports')->insert([
            'id' => $export, 'tenant_id' => $tenantId, 'membership_id' => (string) Str::ulid(),
            'user_id' => 1, 'app_id' => 'uji-app', 'report_code' => 'uji.laporan',
            'report_name' => 'Laporan Uji', 'layout_ref' => 'bawaan:utama', 'layout_name' => 'Utama',
            'format' => 'xlsx', 'parameters' => json_encode([], JSON_THROW_ON_ERROR),
            'status' => 'queued', 'progress' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $reference = (string) Str::ulid();
        $k->table('app_number_sequence_references')->insert([
            'id' => $reference, 'app_id' => 'uji-app', 'code' => 'uji-app.faktur', 'name' => 'Faktur',
            'allowed_scopes' => json_encode(['tenant'], JSON_THROW_ON_ERROR),
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $sequence = (string) Str::ulid();
        $k->table('tenant_number_sequences')->insert([
            'id' => $sequence, 'tenant_id' => $tenantId, 'reference_id' => $reference,
            'profile_code' => 'continuous-strict', 'scope_type' => 'tenant', 'status' => 'active',
            'is_continuous' => true, 'allow_manual' => false, 'reset_period' => 'never',
            'preallocation_enabled' => true, 'preallocation_quantity' => 5,
            'minimum_number' => 1, 'maximum_number' => null,
            'segments' => json_encode([['type' => 'number', 'length' => 6]], JSON_THROW_ON_ERROR),
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $reservation = (string) Str::ulid();
        $k->table('number_sequence_reservations')->insert([
            'id' => $reservation, 'sequence_id' => $sequence, 'app_id' => 'uji-app', 'scope_key' => 'tenant',
            'period_key' => 'all', 'numeric_value' => 7, 'formatted_value' => '000007',
            'idempotency_key' => 'uji-1', 'status' => 'reserved', 'expires_at' => $now->copy()->addHour(),
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $k->table('number_sequence_continuous_pool')->insert([
            'sequence_id' => $sequence, 'scope_key' => 'tenant', 'period_key' => 'all',
            'numeric_value' => 7, 'status' => 'reserved', 'reservation_id' => $reservation,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $k->table('jobs')->insert([
            'queue' => 'default', 'payload' => '{"uuid":"uji"}', 'attempts' => 0,
            'available_at' => $now->timestamp, 'created_at' => $now->timestamp,
        ]);

        $k->table('app_service_credentials')->insert([
            'id' => (string) Str::ulid(), 'app_id' => 'uji-app', 'tenant_id' => $tenantId,
            'name' => 'token produksi', 'status' => 'active', 'token_digest' => str_repeat('a', 64),
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $k->table('core_module_installations')->insert([
            'tenant_id' => $tenantId, 'module_id' => 'uji-modul', 'version' => '1.0.0',
            'status' => 'installed', 'installed_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        return ['event' => $event, 'ekspor' => $export, 'reservasi' => $reservation, 'modul' => 1];
    }

    private function lastSandbox(): Environment
    {
        return Environment::query()->where('kind', 'sandbox')->orderByDesc('created_at')->firstOrFail();
    }

    /**
     * Mengikat lingkungan yang sedang dikerjakan, seperti yang dilakukan perintah artisan.
     *
     * Instansnya dilupakan lebih dulu karena `ActiveEnvironment` memoisasi jawabannya.
     */
    private function activate(Environment $environment): void
    {
        $this->app->forgetInstance(ActiveEnvironment::class);
        $this->app->instance(ActiveEnvironment::KEY, $environment->id);
    }

    /** Satu event yang belum terbit di database pusat — yang dibaca penjadwal hari ini. */
    private function controlPlaneOutbox(): string
    {
        $id = (string) Str::ulid();

        DB::table('outbox_events')->insert([
            'id' => $id,
            'tenant_id' => $this->tenant->id,
            'correlation_id' => (string) Str::ulid(),
            'type' => 'core.workflow.decision.v2',
            'payload' => json_encode(['decision' => 'approved'], JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
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

    private function connectionTo(string $database, string $koneksi): Connection
    {
        $konfigurasi = config('database.connections.'.config('database.default'));
        $konfigurasi['database'] = $database;
        $konfigurasi['url'] = null;
        config(['database.connections.'.$koneksi => $konfigurasi]);
        DB::purge($koneksi);

        return DB::connection($koneksi);
    }

    /** @return list<string> */
    private function test_database(): array
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
     * sendiri. Tanpa itu satu sesi yang lupa ditutup cukup untuk meninggalkan database yatim, dan
     * yang berikutnya menumpuk di atasnya.
     */
    private function dropTestDatabases(): void
    {
        foreach (['test_source', 'test_copy', 'environment_provisioning', 'environment_copy', 'environment_copy_source'] as $koneksi) {
            DB::purge($koneksi);
        }

        foreach ($this->testDatabase() as $name) {
            $this->maintenance()->unprepared(sprintf('DROP DATABASE IF EXISTS "%s" WITH (FORCE)', $name));
        }

        DB::purge('test_maintenance');
    }
}
