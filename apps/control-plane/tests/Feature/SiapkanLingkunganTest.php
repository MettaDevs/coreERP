<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Feature;

use ControlPlane\Models\Lingkungan;
use ControlPlane\Models\User;
use ControlPlane\Tests\SkemaCore;
use ControlPlane\Tests\TestCase;
use Illuminate\Http\Client\Request as PermintaanKeluar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Tombol "Siapkan" pada layar rincian lingkungan.
 *
 * Sampai ia ada, layar itu hanya **menampilkan** perintah `environment:siapkan` untuk disalin ke
 * terminal — alur yang dimulai dari layar lalu berakhir di SSH. Yang diuji di sini perilaku konsol
 * ketika Core menjawab begini atau begitu, jadi sisi jaringannya dipalsukan; bahwa Core sungguhan
 * menjawab dengan bentuk yang dipalsukan di sini adalah janji kontrak, dan `KontrakPerintahKeCoreTest`
 * yang menjaganya.
 *
 * `Http::preventStrayRequests()` berdiri di setiap test supaya satu panggilan yang lolos dari
 * palsuan menjadi kegagalan yang berisik, bukan permintaan sungguhan yang keluar dari mesin ini.
 */
class SiapkanLingkunganTest extends TestCase
{
    use SkemaCore;

    private const ALAMAT = 'http://core.uji:8000';

    protected function setUp(): void
    {
        parent::setUp();

        config(['core.alamat' => self::ALAMAT, 'core.token' => 'kunci-uji']);

        Http::preventStrayRequests();
    }

    public function test_operator_menyiapkan_lingkungan_lewat_core(): void
    {
        $lingkungan = $this->lingkungan('provisioning');
        $operator = $this->operator();

        Http::fake(['*' => Http::response([
            'status' => 'active',
            'database' => 'env_contoh_peragaan_abc1234567',
            'modul' => [
                ['id' => 'human-resources', 'versi' => '1.0.0', 'status' => 'installed', 'disemai' => true],
            ],
        ])]);

        $respons = $this->actingAs($operator)
            ->post('/lingkungan/'.$lingkungan->id.'/siapkan');

        $respons->assertRedirect('/lingkungan/'.$lingkungan->id);
        $respons->assertSessionHas('pesan', fn (string $pesan): bool => str_contains($pesan, 'env_contoh_peragaan_abc1234567')
            && str_contains($pesan, 'human-resources'));

        Http::assertSent(fn (PermintaanKeluar $permintaan): bool => $permintaan->url() === self::ALAMAT.'/api/internal/v1/environments/'.$lingkungan->id.'/siapkan'
            && $permintaan->method() === 'POST'
            // Bearer, bukan header kustom. Cacat itu pernah nyata: kedua sisi hijau, panggilan
            // sungguhannya 401, dan masing-masing suite memalsukan lawan bicaranya.
            && $permintaan->hasHeader('Authorization', 'Bearer kunci-uji')
            // Operatornya ikut dikirim. Tanpa ini, kolom "Oleh" pada riwayat berbunyi "Sistem"
            // untuk tombol yang baru saja ditekan manusia — riwayat yang berbohong justru pada
            // kolom yang ada untuk menjawabnya.
            && $permintaan['diminta_oleh'] === $operator->id);
    }

    public function test_penolakan_core_menjadi_galat_yang_terbaca_bukan_500(): void
    {
        $lingkungan = $this->lingkungan('degraded');

        Http::fake(['*' => Http::response([
            'message' => 'Penyiapan berhenti di langkah "migration": relation "users" already exists.',
        ], 422)]);

        $respons = $this->actingAs($this->operator())
            ->post('/lingkungan/'.$lingkungan->id.'/siapkan');

        $respons->assertRedirect('/lingkungan/'.$lingkungan->id);

        // Alasannya dipulangkan apa adanya. Yang dijaga bukan kalimatnya melainkan bahwa kalimat
        // Core sampai ke layar — pesan "terjadi kesalahan" memaksa operator membuka log server
        // untuk mengetahui sesuatu yang sudah diketahui Core sedetik sebelumnya.
        $respons->assertSessionHasErrors(['siapkan']);
        $this->assertStringContainsString(
            'relation "users" already exists',
            (string) session('errors')?->first('siapkan'),
        );
    }

    public function test_kunci_yang_ditolak_core_disebut_sebagai_kunci_bukan_sebagai_gangguan(): void
    {
        $lingkungan = $this->lingkungan('provisioning');

        // Jawaban bawaan Laravel untuk 401 berbunyi "Unauthenticated." dan tidak memberi tahu siapa
        // pun bahwa yang harus disunting adalah CONTROL_PLANE_TOKEN di berkas env konsol ini.
        Http::fake(['*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

        $this->actingAs($this->operator())
            ->post('/lingkungan/'.$lingkungan->id.'/siapkan')
            ->assertSessionHasErrors(['siapkan']);

        $this->assertStringContainsString(
            'CONTROL_PLANE_TOKEN',
            (string) session('errors')?->first('siapkan'),
        );
    }

    public function test_bukan_operator_tidak_dapat_menyiapkan_apa_pun(): void
    {
        $lingkungan = $this->lingkungan('provisioning');

        // Tanpa `Http::fake`: kalau penjaganya bocor, panggilannya menjadi permintaan nyasar dan
        // `preventStrayRequests` menggagalkan test dengan berisik. Diamnya jaringan di sini
        // bagian dari assertion-nya.
        $this->actingAs($this->pelangganBiasa())
            ->post('/lingkungan/'.$lingkungan->id.'/siapkan')
            ->assertNotFound();
    }

    public function test_layar_rincian_menyebut_apakah_lingkungan_boleh_disiapkan(): void
    {
        $siap = $this->lingkungan('provisioning');
        $hidup = $this->lingkungan('active', 'production');

        $operator = $this->operator();

        $this->actingAs($operator)->get('/lingkungan/'.$siap->id)
            ->assertInertia(fn ($halaman) => $halaman->where('bisaDisiapkan', true)->where('modul', []));

        // Pasangan merahnya, dan ia yang membuat yang di atas berarti: lingkungan yang sudah hidup
        // tidak boleh menampilkan tombol yang akan ditolak Core dengan 409.
        $this->actingAs($operator)->get('/lingkungan/'.$hidup->id)
            ->assertInertia(fn ($halaman) => $halaman->where('bisaDisiapkan', false));
    }

    /**
     * Demo yang belum punya database tidak boleh memperlihatkan pemasangan milik produksi.
     *
     * Ditemukan dengan menjalankannya, bukan oleh test yang sudah ada — dan itu sebabnya test ini
     * ditulis sesudahnya. Sebuah demo yang baru dibuat menampilkan "Human Resources — Terpasang"
     * tepat di bawah kalimat yang menyatakan ia belum memuat apa pun, karena keduanya berbagi
     * database bawaan dan penyaringnya hanya `tenant_id`.
     */
    public function test_demo_tanpa_database_sendiri_tidak_meminjam_pemasangan_produksi(): void
    {
        $tenant = $this->tenant();

        $produksi = Lingkungan::query()->create([
            'tenant_id' => $tenant,
            'kind' => 'production',
            'name' => 'Production',
            'slug' => 'produksi-'.Str::lower(Str::random(6)),
            'status' => 'active',
            'outbound_allowed' => true,
        ]);

        $demo = Lingkungan::query()->create([
            'tenant_id' => $tenant,
            'kind' => 'demo',
            'name' => 'Peragaan',
            'slug' => 'peragaan-'.Str::lower(Str::random(6)),
            'status' => 'provisioning',
            'expires_at' => now()->addDays(30),
            'outbound_allowed' => false,
        ]);

        DB::table('apps')->updateOrInsert(['id' => 'hr'], [
            'name' => 'Human Resources',
            'version' => '1.0.0',
            'status' => 'available',
            'database_name' => 'hr',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('core_module_installations')->insert([
            'tenant_id' => $tenant,
            'module_id' => 'hr',
            'version' => '1.0.0',
            'status' => 'installed',
            'seeded_at' => now(),
            'installed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $operator = $this->operator();

        // Produksi memang tinggal di database bawaan, jadi barisnya miliknya — dan pasangan hijau
        // ini yang membuat penolakan di bawahnya berarti, bukan sekadar daftar yang selalu kosong.
        $this->actingAs($operator)->get('/lingkungan/'.$produksi->id)
            ->assertInertia(fn ($halaman) => $halaman->count('modul', 1)->where('modul.0.id', 'hr'));

        $this->actingAs($operator)->get('/lingkungan/'.$demo->id)
            ->assertInertia(fn ($halaman) => $halaman->where('modul', []));
    }

    private function lingkungan(string $status, string $jenis = 'demo'): Lingkungan
    {
        return Lingkungan::query()->create([
            'tenant_id' => $this->tenant(),
            'kind' => $jenis,
            'name' => 'Peragaan',
            'slug' => 'peragaan-'.Str::lower(Str::random(6)),
            'status' => $status,
            'expires_at' => $jenis === 'demo' ? now()->addDays(30) : null,
            'outbound_allowed' => $jenis === 'production',
        ]);
    }

    private function tenant(): string
    {
        $clientId = (string) Str::ulid();
        DB::table('clients')->insert([
            'id' => $clientId,
            'legal_name' => 'PT Contoh',
            'slug' => 'pt-contoh-'.Str::lower(Str::random(5)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tenantId = (string) Str::ulid();
        DB::table('tenants')->insert([
            'id' => $tenantId,
            'client_id' => $clientId,
            'name' => 'PT Contoh',
            'slug' => 'pt-contoh-'.Str::lower(Str::random(5)),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tenantId;
    }

    private function operator(): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'Operator Uji',
            'email' => Str::lower(Str::random(8)).'@contoh.test',
            'password' => bcrypt('rahasia'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('provider_access')->insert([
            'user_id' => $id,
            'role' => 'provider_admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::query()->findOrFail($id);
    }

    private function pelangganBiasa(): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'Bukan Operator',
            'email' => Str::lower(Str::random(8)).'@contoh.test',
            'password' => bcrypt('rahasia'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::query()->findOrFail($id);
    }
}
