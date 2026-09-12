<?php

declare(strict_types=1);

namespace Tests\Feature\ControlPlane;

use App\Models\Client;
use App\Models\Environment;
use App\Models\EnvironmentOperation;
use App\Models\Tenant;
use App\Support\Pusat\LingkunganAktif;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `environment:konversi` benar-benar menaikkan demo menjadi produksi, dan benar-benar menolak
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
class KonversiLingkunganTest extends TestCase
{
    use RefreshDatabase;

    /** Awalan slug tenant uji; ia yang muncul di nama database dan yang dipakai membersihkannya. */
    private const AWALAN = 'env_ujikonversi';

    private const PENERIMA = 'https://procurement.test/events';

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

        // Setelan penerbit event, disalin dari `PelucutanSambunganKeluarTest`. Penandanya kunci
        // `module`: sebuah penerima yang kodenya dimuat runtime ini akan dilewati penerbit, dan
        // test yang memakai id module yang ada akan hijau tanpa membuktikan apa pun.
        config()->set('coreerp.app_context_signing_key', 'kunci-uji');
        config()->set('coreerp.event_endpoints', [[
            'type' => 'core.workflow.decision.v2',
            'url' => self::PENERIMA,
            'module' => 'procurement',
        ]]);
    }

    protected function tearDown(): void
    {
        $this->buangDatabaseUji();

        parent::tearDown();
    }

    // ---------------------------------------------------------------- jalur merah

    public function test_environment_yang_tidak_ada_ditolak(): void
    {
        $this->artisan('environment:konversi', ['environment' => (string) Str::ulid()])
            ->assertExitCode(Command::FAILURE);

        $this->assertSame(0, EnvironmentOperation::query()->count());
    }

    public function test_sandbox_ditolak(): void
    {
        $sandbox = $this->buat('sandbox', 'active');

        $this->artisan('environment:konversi', ['environment' => $sandbox->id])
            ->assertExitCode(Command::FAILURE);

        // Akibatnya, bukan pesannya: sandbox yang dipromosikan akan menghasilkan dua tempat berisi
        // data yang sama, keduanya boleh menghubungi pihak luar. Yang dijaga di sini justru bendera
        // keluarnya — ia yang membuat salinan itu berbahaya.
        $sandbox->refresh();
        $this->assertSame('sandbox', $sandbox->kind);
        $this->assertFalse($sandbox->outbound_allowed);
        $this->assertSame(0, EnvironmentOperation::query()->count());
    }

    public function test_lingkungan_yang_belum_aktif_ditolak(): void
    {
        $demo = $this->buat('demo', 'provisioning');

        $this->artisan('environment:konversi', ['environment' => $demo->id])
            ->assertExitCode(Command::FAILURE);

        // Yang belum selesai disiapkan tidak punya apa pun untuk dipromosikan, dan menaikkannya
        // menghasilkan produksi yang databasenya belum tentu ada.
        $demo->refresh();
        $this->assertSame('demo', $demo->kind);
        $this->assertSame('provisioning', $demo->status);
        $this->assertFalse($demo->outbound_allowed);
        $this->assertSame(0, EnvironmentOperation::query()->count());
    }

    public function test_tenant_yang_sudah_punya_produksi_ditolak(): void
    {
        $produksi = $this->buat('production', 'active');
        $demo = $this->buat('demo', 'active');
        $berakhir = $demo->expires_at;

        $this->artisan('environment:konversi', ['environment' => $demo->id])
            ->assertExitCode(Command::FAILURE);

        // Keduanya harus tetap seperti semula. Yang paling mahal kalau penjaganya bocor bukan
        // barisnya melainkan akibatnya: dua tempat sama-sama mengaku produksi, sama-sama boleh
        // menghubungi pihak luar, dengan nomor dokumen yang berjalan sendiri-sendiri.
        $demo->refresh();
        $this->assertSame('demo', $demo->kind);
        $this->assertFalse($demo->outbound_allowed);
        $this->assertNotNull($demo->expires_at);
        $this->assertSame(
            $berakhir?->toIso8601String(),
            $demo->expires_at?->toIso8601String(),
            'Tanggal berakhir demo tidak boleh dilepas oleh konversi yang ditolak.',
        );

        $produksi->refresh();
        $this->assertSame('production', $produksi->kind);
        $this->assertTrue($produksi->outbound_allowed);

        $this->assertSame(0, EnvironmentOperation::query()->count());
    }

    public function test_operasi_yang_sedang_berjalan_menolak_konversi_kedua(): void
    {
        $demo = $this->buat('demo', 'active');

        EnvironmentOperation::create([
            'environment_id' => $demo->id,
            'operation' => 'copy',
            'status' => 'running',
            'step' => 'salin',
            'started_at' => now(),
            'lease_until' => now()->addMinutes(30),
        ]);

        $this->artisan('environment:konversi', ['environment' => $demo->id])
            ->assertExitCode(Command::FAILURE);

        // Penolakannya datang dari partial unique index, bukan dari pemeriksaan di kode. Dan karena
        // perintah ini tidak pernah memiliki operasi itu, ia juga tidak boleh menyentuhnya.
        $this->assertSame('demo', $demo->refresh()->kind);
        $this->assertSame(1, EnvironmentOperation::query()->count());
        $this->assertSame('running', EnvironmentOperation::query()->firstOrFail()->status);
    }

    public function test_konversi_naif_ditolak_database(): void
    {
        $demo = $this->buat('demo', 'active');

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

    public function test_demo_tidak_pernah_dapat_membawa_environment_sumber(): void
    {
        $produksi = $this->buat('production', 'active');

        // Pasangan dari test di atas, dan ia membuktikan hal yang berlawanan arah:
        // `environments_sumber_hanya_sandbox` **tidak** menggigit konversi, karena sebuah demo
        // tidak pernah bisa lahir membawa sumber sejak awal. Kalau suatu hari larangan itu
        // dilonggarkan — misalnya supaya demo dapat mencatat template asalnya — test ini merah,
        // dan konversi harus diperiksa ulang sebelum ia diizinkan.
        try {
            DB::transaction(function () use ($produksi): void {
                Environment::create([
                    'tenant_id' => $this->tenant->id,
                    'kind' => 'demo',
                    'name' => 'Demo bersumber',
                    'slug' => 'demo-bersumber',
                    'status' => 'active',
                    'outbound_allowed' => false,
                    'expires_at' => now()->addDays(30),
                    'source_environment_id' => $produksi->id,
                ]);
            });

            $this->fail('Demo yang membawa environment sumber seharusnya ditolak database.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('environments_sumber_hanya_sandbox', $e->getMessage());
        }

        $this->assertSame(1, Environment::query()->count());
    }

    // ---------------------------------------------------------------- jalur hijau

    public function test_demo_benar_benar_menjadi_produksi(): void
    {
        $demo = $this->buat('demo', 'active');
        $berakhir = $demo->expires_at;

        $this->artisan('environment:konversi', ['environment' => $demo->id])
            ->assertExitCode(Command::SUCCESS);

        $demo->refresh();
        $this->assertSame('production', $demo->kind);
        $this->assertTrue($demo->outbound_allowed, 'Sambungan keluar harus ikut menyala dalam pernyataan yang sama.');
        $this->assertNull($demo->expires_at, 'Produksi tidak boleh mewarisi tanggal berakhir demo.');
        $this->assertSame('active', $demo->status, 'Konversi tidak menyentuh status; ia tetap dapat dirutekan.');
        $this->assertNull($demo->database_name, 'Konversi di tempat: databasenya tidak berpindah.');
        $this->assertSame('ujikonversi-demo', $demo->slug, 'Alamatnya tidak berubah; itu urusan routing, bukan konversi.');

        $operasi = EnvironmentOperation::query()->firstOrFail();
        $this->assertSame('convert', $operasi->operation);
        $this->assertSame('succeeded', $operasi->status);
        $this->assertNotNull($operasi->finished_at);
        $this->assertNull($operasi->failure_message);
        $this->assertNull($operasi->lease_until);

        // Tanggal berakhir yang dilepas ikut tercatat. Tanpa itu, satu-satunya jejak bahwa
        // lingkungan ini pernah punya masa berlaku hilang bersama konversinya.
        $detail = $operasi->detail;
        $this->assertIsArray($detail);
        $this->assertSame('demo', $detail['jenis_sebelumnya'] ?? null);
        $this->assertSame($berakhir?->toIso8601String(), $detail['berakhir_dilepas'] ?? null);
    }

    public function test_dijalankan_ulang_atas_produksi_tidak_mengubah_apa_pun(): void
    {
        $demo = $this->buat('demo', 'active');

        $this->artisan('environment:konversi', ['environment' => $demo->id])
            ->assertExitCode(Command::SUCCESS);

        $sesudah = $demo->refresh()->only(['kind', 'status', 'outbound_allowed', 'expires_at']);

        // Percobaan kedua keluar berhasil, bukan galat: satu-satunya pemulihan yang rancangan ini
        // izinkan adalah menjalankan ulang perintah yang sama, dan galat di sini memaksa orang
        // menebak apakah pekerjaannya sudah selesai.
        $this->artisan('environment:konversi', ['environment' => $demo->id])
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame($sesudah, $demo->refresh()->only(['kind', 'status', 'outbound_allowed', 'expires_at']));

        // Dan ia tidak meninggalkan jejak. Riwayat yang penuh operasi yang tidak mengerjakan
        // apa-apa adalah riwayat yang berhenti dibaca orang.
        $this->assertSame(1, EnvironmentOperation::query()->count());
    }

    public function test_antrean_event_demo_tidak_ikut_terbit_sesudah_konversi(): void
    {
        $demo = $this->buat('demo', 'active');
        $lama = [$this->outbox($this->tenant->id), $this->outbox($this->tenant->id)];

        $this->artisan('environment:konversi', ['environment' => $demo->id])
            ->assertExitCode(Command::SUCCESS);

        foreach ($lama as $id) {
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
        $this->pakai($demo->refresh());
        Artisan::call('workflow-events:publish');

        Http::assertNothingSent();

        $operasi = EnvironmentOperation::query()->firstOrFail();
        $detail = $operasi->detail;
        $this->assertIsArray($detail);
        $this->assertSame(2, $detail['event_dilucuti'] ?? null);
    }

    public function test_event_yang_lahir_sesudah_konversi_benar_benar_terkirim(): void
    {
        // Pasangan hijau dari test di atas, dan ia yang membuatnya berarti. Pelucutan yang
        // kebablasan — misalnya yang menandai terbit apa pun selamanya — akan lulus test
        // sebelumnya juga, dan hasilnya produksi yang tidak pernah mengirim satu event pun.
        $demo = $this->buat('demo', 'active');
        $this->outbox($this->tenant->id);

        $this->artisan('environment:konversi', ['environment' => $demo->id])
            ->assertExitCode(Command::SUCCESS);

        $baru = $this->outbox($this->tenant->id);

        Http::fake([self::PENERIMA => Http::response(['data' => ['accepted' => true]])]);
        $this->pakai($demo->refresh());
        Artisan::call('workflow-events:publish');

        Http::assertSent(fn ($permintaan): bool => $permintaan->url() === self::PENERIMA
            && $permintaan['id'] === $baru);
        Http::assertSentCount(1);
        $this->assertNotNull(DB::table('outbox_events')->where('id', $baru)->value('published_at'));
    }

    public function test_antrean_tenant_lain_tidak_ikut_dilucuti(): void
    {
        // Keadaan pooled: `database_name` kosong berarti lingkungan ini ikut database koneksi
        // bawaan, dan di sana `outbox_events` memuat baris milik tenant yang tidak sedang
        // dikonversi sama sekali. Melucutinya berarti membatalkan pengiriman event pelanggan yang
        // tidak melakukan apa-apa — kebocoran yang tidak berbunyi, hanya event yang tidak pernah
        // sampai.
        $demo = $this->buat('demo', 'active');
        $lain = $this->tenantKedua();

        $milikDemo = $this->outbox($this->tenant->id);
        $milikLain = $this->outbox($lain);

        $this->artisan('environment:konversi', ['environment' => $demo->id])
            ->assertExitCode(Command::SUCCESS);

        $this->assertNotNull(DB::table('outbox_events')->where('id', $milikDemo)->value('published_at'));
        $this->assertNull(
            DB::table('outbox_events')->where('id', $milikLain)->value('published_at'),
            'Konversi hanya boleh menyentuh antrean tenant yang dikonversi.',
        );
    }

    public function test_antrean_di_database_lingkungan_sendiri_ikut_dilucuti(): void
    {
        // Jalur yang sebenarnya akan berjalan di produksi, dan ia tidak dipalsukan: demo yang lahir
        // dari layar operator memperoleh databasenya sendiri lewat `environment:siapkan`, dan
        // antreannya hidup di sana — bukan di database pusat. Pelucutan yang hanya pernah diuji
        // pada keadaan pooled tidak membuktikan apa pun tentang jalur itu.
        $demo = $this->buat('demo', 'provisioning');

        $this->artisan('environment:siapkan', ['environment' => $demo->id])
            ->assertExitCode(Command::SUCCESS);

        $demo->refresh();
        $this->assertNotNull($demo->database_name);

        $sasaran = $this->koneksiKe((string) $demo->database_name);
        $id = (string) Str::ulid();
        $sasaran->table('outbox_events')->insert($this->barisOutbox($id, $this->tenant->id));

        $this->artisan('environment:konversi', ['environment' => $demo->id])
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame('production', $demo->refresh()->kind);
        $this->assertNotNull(
            $this->koneksiKe((string) $demo->database_name)->table('outbox_events')->where('id', $id)->value('published_at'),
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
    private function buat(string $jenis, string $status): Environment
    {
        return Environment::create([
            'tenant_id' => $this->tenant->id,
            'kind' => $jenis,
            'name' => 'Uji '.$jenis,
            'slug' => 'ujikonversi-'.$jenis,
            'database_name' => null,
            'status' => $status,
            'outbound_allowed' => $jenis === 'production',
            'expires_at' => $jenis === 'demo' ? now()->addDays(30) : null,
        ]);
    }

    /** Tenant kedua yang berbagi database yang sama — keadaan pooled, dan ia yang diuji. */
    private function tenantKedua(): string
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

        DB::table('outbox_events')->insert($this->barisOutbox($id, $tenantId));

        return $id;
    }

    /** @return array<string, mixed> */
    private function barisOutbox(string $id, string $tenantId): array
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
     * Instansnya dilupakan lebih dulu karena `LingkunganAktif` memoisasi jawabannya.
     */
    private function pakai(Environment $lingkungan): void
    {
        $this->app->forgetInstance(LingkunganAktif::class);
        $this->app->instance(LingkunganAktif::KUNCI, $lingkungan->id);
    }

    /**
     * Koneksi kedua ke database pusat.
     *
     * `RefreshDatabase` memegang transaksi pada koneksi bawaan, dan `DROP DATABASE` dilarang berada
     * di dalam transaksi. PDO terpisah satu-satunya jalan.
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
     * `WITH (FORCE)` memutus sesi yang masih menempel — milik perintah maupun milik test ini
     * sendiri. Tanpa itu satu sesi yang lupa ditutup cukup untuk meninggalkan database yatim.
     */
    private function buangDatabaseUji(): void
    {
        DB::purge('uji_sasaran');
        DB::purge('lingkungan_disiapkan');
        DB::purge('lingkungan_dikonversi');

        foreach ($this->databaseUji() as $nama) {
            $this->pemelihara()->unprepared(sprintf('DROP DATABASE IF EXISTS "%s" WITH (FORCE)', $nama));
        }

        DB::purge('uji_pemelihara');
    }
}
