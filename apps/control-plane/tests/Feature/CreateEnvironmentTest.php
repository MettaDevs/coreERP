<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Feature;

use ControlPlane\Models\Environment;
use ControlPlane\Models\User;
use ControlPlane\Tests\CoreSchema;
use ControlPlane\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Konsol operator benar-benar dapat membuat lingkungan, dan benar-benar menolak yang tidak boleh.
 *
 * Setiap penolakan di bawah punya pasangan hijaunya di test yang sama atau di sebelahnya. Penjaga
 * yang hijau karena butalah yang sudah dua kali membakar repo ini: ia tampak bekerja selama
 * bertahun-tahun justru karena tidak ada satu pun kasus yang benar-benar melewatinya.
 */
class CreateEnvironmentTest extends TestCase
{
    use CoreSchema;

    private function operator(): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'Operator Uji',
            'email' => 'operator@contoh.test',
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

    private function tenant(string $name = 'PT Contoh'): string
    {
        $clientId = (string) Str::ulid();
        DB::table('clients')->insert([
            'id' => $clientId,
            'legal_name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tenantId = (string) Str::ulid();
        DB::table('tenants')->insert([
            'id' => $tenantId,
            'client_id' => $clientId,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tenantId;
    }

    public function test_operator_can_create_a_demo_environment(): void
    {
        $tenant = $this->tenant();

        $response = $this->actingAs($this->operator())->post('/lingkungan', [
            'tenant_id' => $tenant,
            'kind' => 'demo',
            'name' => 'Peragaan Penjualan',
            'expires_at' => Carbon::now()->addDays(30)->toDateString(),
        ]);

        $environment = Environment::query()->where('tenant_id', $tenant)->where('kind', 'demo')->firstOrFail();

        $response->assertRedirect('/lingkungan/'.$environment->id);
        $this->assertSame('peragaan-penjualan', $environment->slug);

        // Tercatat, tetapi belum dapat dirutekan. Hanya `active` yang boleh dimasuki, sehingga
        // lingkungan yang penyiapannya belum selesai adalah lingkungan yang tidak bisa dibuka —
        // bukan lingkungan yang bisa dibuka lalu ternyata kosong.
        $this->assertSame('provisioning', $environment->status);
        $this->assertFalse($environment->outbound_allowed);
        $this->assertNull($environment->database_name);

        $operation = $environment->operations()->sole();
        $this->assertSame('provision', $operation->operation);
        $this->assertSame('succeeded', $operation->status);
        $this->assertNotNull($operation->finished_at);
    }

    public function test_production_may_contact_the_outside_world(): void
    {
        // Pasangan hijau dari test di bawahnya. Tanpa ini, "sandbox tidak boleh mengirim" dapat
        // dipenuhi oleh kode yang tidak pernah mengizinkan siapa pun mengirim.
        $tenant = $this->tenant('PT Produksi');

        $this->actingAs($this->operator())->post('/lingkungan', [
            'tenant_id' => $tenant,
            'kind' => 'production',
            'name' => 'Produksi',
        ])->assertRedirect();

        $this->assertTrue(
            Environment::query()->where('tenant_id', $tenant)->sole()->outbound_allowed,
        );
    }

    public function test_sandbox_and_demo_may_not_contact_the_outside_world(): void
    {
        $tenant = $this->tenant('PT Salinan');

        $this->actingAs($this->operator())->post('/lingkungan', [
            'tenant_id' => $tenant,
            'kind' => 'sandbox',
            'name' => 'Uji Coba',
        ])->assertRedirect();

        $this->assertFalse(
            Environment::query()->where('tenant_id', $tenant)->sole()->outbound_allowed,
        );
    }

    public function test_a_demo_without_an_expiry_date_is_rejected(): void
    {
        $this->actingAs($this->operator())
            ->post('/lingkungan', [
                'tenant_id' => $this->tenant('PT Lupa Tanggal'),
                'kind' => 'demo',
                'name' => 'Demo Abadi',
            ])
            ->assertSessionHasErrors('expires_at');

        $this->assertSame(0, Environment::query()->where('kind', 'demo')->count());
    }

    public function test_a_second_production_for_the_same_tenant_is_rejected(): void
    {
        $tenant = $this->tenant('PT Dua Produksi');
        $operator = $this->operator();

        $this->actingAs($operator)->post('/lingkungan', [
            'tenant_id' => $tenant,
            'kind' => 'production',
            'name' => 'Produksi',
        ])->assertRedirect();

        // Ditolak oleh partial unique index, bukan oleh pemeriksaan di kode. Bedanya mengikat:
        // dua operator yang menekan tombolnya pada detik yang sama sama-sama lolos pemeriksaan,
        // dan hanya PostgreSQL yang dapat memutuskan siapa yang duluan.
        $this->actingAs($operator)
            ->post('/lingkungan', [
                'tenant_id' => $tenant,
                'kind' => 'production',
                'name' => 'Produksi Lagi',
            ])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, Environment::query()->where('tenant_id', $tenant)->count());
    }

    /**
     * Alamatnya `<tenant>.<jenis>.<domain>`, jadi demo kedua akan menunjuk alamat yang sama.
     * Ditolak `environments_satu_per_jenis` di database, dan operator membaca sebabnya.
     */
    public function test_a_second_demo_or_sandbox_for_the_same_tenant_is_rejected(): void
    {
        $tenant = $this->tenant('PT Dua Demo');
        $operator = $this->operator();

        foreach (['demo', 'sandbox'] as $kind) {
            $fields = ['tenant_id' => $tenant, 'kind' => $kind, 'expires_at' => now()->addMonth()->toDateString()];

            $this->actingAs($operator)->post('/lingkungan', $fields + ['name' => 'Pertama'])->assertRedirect();

            $this->actingAs($operator)
                ->post('/lingkungan', $fields + ['name' => 'Kedua'])
                ->assertSessionHasErrors(['name' => 'Tenant PT Dua Demo sudah punya lingkungan '.$kind.'. Satu tenant hanya boleh punya satu demo dan satu sandbox, karena alamatnya hanya memuat tenant dan jenis.']);
        }

        $this->assertSame(2, Environment::query()->where('tenant_id', $tenant)->count());
    }

    public function test_the_same_name_in_one_tenant_gets_a_different_slug(): void
    {
        $tenant = $this->tenant('PT Nama Kembar');
        $operator = $this->operator();

        // Dua jenis berbeda: satu tenant hanya boleh punya satu sandbox, jadi nama kembar yang
        // realistis datang dari jenis yang lain.
        foreach (['sandbox', 'production'] as $kind) {
            $this->actingAs($operator)->post('/lingkungan', [
                'tenant_id' => $tenant,
                'kind' => $kind,
                'name' => 'Uji Coba',
            ])->assertRedirect();
        }

        $slug = Environment::query()->where('tenant_id', $tenant)->pluck('slug')->all();
        sort($slug);

        $this->assertSame(['uji-coba', 'uji-coba-2'], $slug);
    }

    public function test_a_non_operator_sees_nothing(): void
    {
        // 404, bukan 403. Pengguna biasa tidak perlu tahu alamat ini ada.
        $this->actingAs($this->ordinaryUser())->get('/lingkungan')->assertNotFound();

        $this->actingAs($this->ordinaryUser())
            ->post('/lingkungan', [
                'tenant_id' => $this->tenant('PT Tanpa Izin'),
                'kind' => 'sandbox',
                'name' => 'Menyelinap',
            ])
            ->assertNotFound();

        $this->assertSame(0, Environment::query()->count());
    }

    public function test_a_guest_is_redirected_to_the_login_page(): void
    {
        $this->get('/lingkungan')->assertRedirect('/login');
    }

    public function test_the_index_and_show_screens_display_the_created_environment(): void
    {
        $tenant = $this->tenant('PT Terlihat');
        $operator = $this->operator();

        $this->actingAs($operator)->post('/lingkungan', [
            'tenant_id' => $tenant,
            'kind' => 'demo',
            'name' => 'Peragaan',
            'expires_at' => Carbon::now()->addDays(7)->toDateString(),
        ])->assertRedirect();

        $environment = Environment::query()->where('tenant_id', $tenant)->sole();

        $this->actingAs($operator)->get('/lingkungan')
            ->assertOk()
            ->assertSee('Peragaan');

        $this->actingAs($operator)->get('/lingkungan/'.$environment->id)
            ->assertOk()
            ->assertSee($environment->id);
    }
}
