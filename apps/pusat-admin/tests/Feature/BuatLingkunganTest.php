<?php

declare(strict_types=1);

namespace PusatAdmin\Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PusatAdmin\Models\Lingkungan;
use PusatAdmin\Models\User;
use PusatAdmin\Tests\SkemaCore;
use PusatAdmin\Tests\TestCase;

/**
 * Konsol operator benar-benar dapat membuat lingkungan, dan benar-benar menolak yang tidak boleh.
 *
 * Setiap penolakan di bawah punya pasangan hijaunya di test yang sama atau di sebelahnya. Penjaga
 * yang hijau karena butalah yang sudah dua kali membakar repo ini: ia tampak bekerja selama
 * bertahun-tahun justru karena tidak ada satu pun kasus yang benar-benar melewatinya.
 */
class BuatLingkunganTest extends TestCase
{
    use SkemaCore;

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

    private function tenant(string $nama = 'PT Contoh'): string
    {
        $clientId = (string) Str::ulid();
        DB::table('clients')->insert([
            'id' => $clientId,
            'legal_name' => $nama,
            'slug' => Str::slug($nama).'-'.Str::lower(Str::random(5)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tenantId = (string) Str::ulid();
        DB::table('tenants')->insert([
            'id' => $tenantId,
            'client_id' => $clientId,
            'name' => $nama,
            'slug' => Str::slug($nama).'-'.Str::lower(Str::random(5)),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tenantId;
    }

    public function test_operator_dapat_membuat_lingkungan_demo(): void
    {
        $tenant = $this->tenant();

        $respons = $this->actingAs($this->operator())->post('/lingkungan', [
            'tenant_id' => $tenant,
            'jenis' => 'demo',
            'nama' => 'Peragaan Penjualan',
            'berakhir' => Carbon::now()->addDays(30)->toDateString(),
        ]);

        $lingkungan = Lingkungan::query()->where('tenant_id', $tenant)->where('kind', 'demo')->firstOrFail();

        $respons->assertRedirect('/lingkungan/'.$lingkungan->id);
        $this->assertSame('peragaan-penjualan', $lingkungan->slug);

        // Tercatat, tetapi belum dapat dirutekan. Hanya `active` yang boleh dimasuki, sehingga
        // lingkungan yang penyiapannya belum selesai adalah lingkungan yang tidak bisa dibuka —
        // bukan lingkungan yang bisa dibuka lalu ternyata kosong.
        $this->assertSame('provisioning', $lingkungan->status);
        $this->assertFalse($lingkungan->outbound_allowed);
        $this->assertNull($lingkungan->database_name);

        $operasi = $lingkungan->operasi()->sole();
        $this->assertSame('provision', $operasi->operation);
        $this->assertSame('succeeded', $operasi->status);
        $this->assertNotNull($operasi->finished_at);
    }

    public function test_produksi_boleh_menghubungi_dunia_luar(): void
    {
        // Pasangan hijau dari test di bawahnya. Tanpa ini, "sandbox tidak boleh mengirim" dapat
        // dipenuhi oleh kode yang tidak pernah mengizinkan siapa pun mengirim.
        $tenant = $this->tenant('PT Produksi');

        $this->actingAs($this->operator())->post('/lingkungan', [
            'tenant_id' => $tenant,
            'jenis' => 'production',
            'nama' => 'Produksi',
        ])->assertRedirect();

        $this->assertTrue(
            Lingkungan::query()->where('tenant_id', $tenant)->sole()->outbound_allowed,
        );
    }

    public function test_sandbox_dan_demo_tidak_boleh_menghubungi_dunia_luar(): void
    {
        $tenant = $this->tenant('PT Salinan');

        $this->actingAs($this->operator())->post('/lingkungan', [
            'tenant_id' => $tenant,
            'jenis' => 'sandbox',
            'nama' => 'Uji Coba',
        ])->assertRedirect();

        $this->assertFalse(
            Lingkungan::query()->where('tenant_id', $tenant)->sole()->outbound_allowed,
        );
    }

    public function test_demo_tanpa_tanggal_berakhir_ditolak(): void
    {
        $this->actingAs($this->operator())
            ->post('/lingkungan', [
                'tenant_id' => $this->tenant('PT Lupa Tanggal'),
                'jenis' => 'demo',
                'nama' => 'Demo Abadi',
            ])
            ->assertSessionHasErrors('berakhir');

        $this->assertSame(0, Lingkungan::query()->where('kind', 'demo')->count());
    }

    public function test_produksi_kedua_untuk_tenant_yang_sama_ditolak(): void
    {
        $tenant = $this->tenant('PT Dua Produksi');
        $operator = $this->operator();

        $this->actingAs($operator)->post('/lingkungan', [
            'tenant_id' => $tenant,
            'jenis' => 'production',
            'nama' => 'Produksi',
        ])->assertRedirect();

        // Ditolak oleh partial unique index, bukan oleh pemeriksaan di kode. Bedanya mengikat:
        // dua operator yang menekan tombolnya pada detik yang sama sama-sama lolos pemeriksaan,
        // dan hanya PostgreSQL yang dapat memutuskan siapa yang duluan.
        $this->actingAs($operator)
            ->post('/lingkungan', [
                'tenant_id' => $tenant,
                'jenis' => 'production',
                'nama' => 'Produksi Lagi',
            ])
            ->assertSessionHasErrors('nama');

        $this->assertSame(1, Lingkungan::query()->where('tenant_id', $tenant)->count());
    }

    public function test_nama_yang_sama_di_satu_tenant_memperoleh_slug_yang_berbeda(): void
    {
        $tenant = $this->tenant('PT Nama Kembar');
        $operator = $this->operator();

        foreach ([1, 2] as $_) {
            $this->actingAs($operator)->post('/lingkungan', [
                'tenant_id' => $tenant,
                'jenis' => 'sandbox',
                'nama' => 'Uji Coba',
            ])->assertRedirect();
        }

        $slug = Lingkungan::query()->where('tenant_id', $tenant)->pluck('slug')->all();
        sort($slug);

        $this->assertSame(['uji-coba', 'uji-coba-2'], $slug);
    }

    public function test_bukan_operator_tidak_melihat_apa_pun(): void
    {
        // 404, bukan 403. Pengguna biasa tidak perlu tahu alamat ini ada.
        $this->actingAs($this->pelangganBiasa())->get('/lingkungan')->assertNotFound();

        $this->actingAs($this->pelangganBiasa())
            ->post('/lingkungan', [
                'tenant_id' => $this->tenant('PT Tanpa Izin'),
                'jenis' => 'sandbox',
                'nama' => 'Menyelinap',
            ])
            ->assertNotFound();

        $this->assertSame(0, Lingkungan::query()->count());
    }

    public function test_tamu_diarahkan_ke_halaman_masuk(): void
    {
        $this->get('/lingkungan')->assertRedirect('/login');
    }

    public function test_daftar_dan_rincian_menampilkan_lingkungan_yang_dibuat(): void
    {
        $tenant = $this->tenant('PT Terlihat');
        $operator = $this->operator();

        $this->actingAs($operator)->post('/lingkungan', [
            'tenant_id' => $tenant,
            'jenis' => 'demo',
            'nama' => 'Peragaan',
            'berakhir' => Carbon::now()->addDays(7)->toDateString(),
        ])->assertRedirect();

        $lingkungan = Lingkungan::query()->where('tenant_id', $tenant)->sole();

        $this->actingAs($operator)->get('/lingkungan')
            ->assertOk()
            ->assertSee('Peragaan');

        $this->actingAs($operator)->get('/lingkungan/'.$lingkungan->id)
            ->assertOk()
            ->assertSee($lingkungan->id);
    }
}
