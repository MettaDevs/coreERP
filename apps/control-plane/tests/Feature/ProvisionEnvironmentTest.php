<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Feature;

use ControlPlane\Models\Environment;
use ControlPlane\Models\User;
use ControlPlane\Tests\CoreSchema;
use ControlPlane\Tests\TestCase;
use Illuminate\Http\Client\Request as OutboundRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Tombol "Siapkan" pada layar rincian lingkungan.
 *
 * Sampai ia ada, layar itu hanya **menampilkan** perintah `environment:provision` untuk disalin ke
 * terminal — alur yang dimulai dari layar lalu berakhir di SSH. Yang diuji di sini perilaku konsol
 * ketika Core menjawab begini atau begitu, jadi sisi jaringannya dipalsukan; bahwa Core sungguhan
 * menjawab dengan bentuk yang dipalsukan di sini adalah janji kontrak, dan `CoreCommandContractTest`
 * yang menjaganya.
 *
 * `Http::preventStrayRequests()` berdiri di setiap test supaya satu panggilan yang lolos dari
 * palsuan menjadi kegagalan yang berisik, bukan permintaan sungguhan yang keluar dari mesin ini.
 */
class ProvisionEnvironmentTest extends TestCase
{
    use CoreSchema;

    private const BASE_URL = 'http://core.uji:8000';

    protected function setUp(): void
    {
        parent::setUp();

        config(['core.base_url' => self::BASE_URL, 'core.token' => 'kunci-uji']);

        Http::preventStrayRequests();
    }

    public function test_the_operator_provisions_an_environment_through_core(): void
    {
        $environment = $this->environment('provisioning');
        $operator = $this->operator();

        Http::fake(['*' => Http::response([
            'status' => 'active',
            'database' => 'env_contoh_peragaan_abc1234567',
            'modules' => [
                ['id' => 'human-resources', 'version' => '1.0.0', 'status' => 'installed', 'seeded' => true],
            ],
        ])]);

        $response = $this->actingAs($operator)
            ->post('/lingkungan/'.$environment->id.'/siapkan');

        $response->assertRedirect('/lingkungan/'.$environment->id);
        $response->assertSessionHas('message', fn (string $message): bool => str_contains($message, 'env_contoh_peragaan_abc1234567')
            && str_contains($message, 'human-resources'));

        Http::assertSent(fn (OutboundRequest $request): bool => $request->url() === self::BASE_URL.'/api/internal/v1/environments/'.$environment->id.'/provision'
            && $request->method() === 'POST'
            // Bearer, bukan header kustom. Cacat itu pernah nyata: kedua sisi hijau, panggilan
            // sungguhannya 401, dan masing-masing suite memalsukan lawan bicaranya.
            && $request->hasHeader('Authorization', 'Bearer kunci-uji')
            // Operatornya ikut dikirim. Tanpa ini, kolom "Oleh" pada riwayat berbunyi "Sistem"
            // untuk tombol yang baru saja ditekan manusia — riwayat yang berbohong justru pada
            // kolom yang ada untuk menjawabnya.
            && $request['requested_by'] === $operator->id);
    }

    public function test_a_core_rejection_becomes_a_readable_error_not_a_500(): void
    {
        $environment = $this->environment('degraded');

        Http::fake(['*' => Http::response([
            'message' => 'Penyiapan berhenti di langkah "migration": relation "users" already exists.',
        ], 422)]);

        $response = $this->actingAs($this->operator())
            ->post('/lingkungan/'.$environment->id.'/siapkan');

        $response->assertRedirect('/lingkungan/'.$environment->id);

        // Alasannya dipulangkan apa adanya. Yang dijaga bukan kalimatnya melainkan bahwa kalimat
        // Core sampai ke layar — pesan "terjadi kesalahan" memaksa operator membuka log server
        // untuk mengetahui sesuatu yang sudah diketahui Core sedetik sebelumnya.
        $response->assertSessionHasErrors(['provision']);
        $this->assertStringContainsString(
            'relation "users" already exists',
            (string) session('errors')?->first('provision'),
        );
    }

    public function test_a_key_rejected_by_core_is_named_as_a_key_not_as_an_outage(): void
    {
        $environment = $this->environment('provisioning');

        // Jawaban bawaan Laravel untuk 401 berbunyi "Unauthenticated." dan tidak memberi tahu siapa
        // pun bahwa yang harus disunting adalah CONTROL_PLANE_TOKEN di berkas env konsol ini.
        Http::fake(['*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

        $this->actingAs($this->operator())
            ->post('/lingkungan/'.$environment->id.'/siapkan')
            ->assertSessionHasErrors(['provision']);

        $this->assertStringContainsString(
            'CONTROL_PLANE_TOKEN',
            (string) session('errors')?->first('provision'),
        );
    }

    public function test_a_non_operator_cannot_provision_anything(): void
    {
        $environment = $this->environment('provisioning');

        // Tanpa `Http::fake`: kalau penjaganya bocor, panggilannya menjadi permintaan nyasar dan
        // `preventStrayRequests` menggagalkan test dengan berisik. Diamnya jaringan di sini
        // bagian dari assertion-nya.
        $this->actingAs($this->ordinaryUser())
            ->post('/lingkungan/'.$environment->id.'/siapkan')
            ->assertNotFound();
    }

    public function test_the_show_screen_says_whether_an_environment_may_be_provisioned(): void
    {
        $ready = $this->environment('provisioning');
        $live = $this->environment('active', 'production');

        $operator = $this->operator();

        $this->actingAs($operator)->get('/lingkungan/'.$ready->id)
            ->assertInertia(fn ($page) => $page->where('canProvision', true)->where('modules', []));

        // Pasangan merahnya, dan ia yang membuat yang di atas berarti: lingkungan yang sudah hidup
        // tidak boleh menampilkan tombol yang akan ditolak Core dengan 409.
        $this->actingAs($operator)->get('/lingkungan/'.$live->id)
            ->assertInertia(fn ($page) => $page->where('canProvision', false));
    }

    /**
     * Demo yang belum punya database tidak boleh memperlihatkan pemasangan milik produksi.
     *
     * Ditemukan dengan menjalankannya, bukan oleh test yang sudah ada — dan itu sebabnya test ini
     * ditulis sesudahnya. Sebuah demo yang baru dibuat menampilkan "Human Resources — Terpasang"
     * tepat di bawah kalimat yang menyatakan ia belum memuat apa pun, karena keduanya berbagi
     * database bawaan dan penyaringnya hanya `tenant_id`.
     */
    public function test_a_demo_without_its_own_database_does_not_borrow_production_installations(): void
    {
        $tenant = $this->tenant();

        $production = Environment::query()->create([
            'tenant_id' => $tenant,
            'kind' => 'production',
            'name' => 'Production',
            'slug' => 'produksi-'.Str::lower(Str::random(6)),
            'status' => 'active',
            'outbound_allowed' => true,
        ]);

        $demo = Environment::query()->create([
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
        $this->actingAs($operator)->get('/lingkungan/'.$production->id)
            ->assertInertia(fn ($page) => $page->count('modules', 1)->where('modules.0.id', 'hr'));

        $this->actingAs($operator)->get('/lingkungan/'.$demo->id)
            ->assertInertia(fn ($page) => $page->where('modules', []));
    }

    private function environment(string $status, string $kind = 'demo'): Environment
    {
        return Environment::query()->create([
            'tenant_id' => $this->tenant(),
            'kind' => $kind,
            'name' => 'Peragaan',
            'slug' => 'peragaan-'.Str::lower(Str::random(6)),
            'status' => $status,
            'expires_at' => $kind === 'demo' ? now()->addDays(30) : null,
            'outbound_allowed' => $kind === 'production',
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

    private function ordinaryUser(): User
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
