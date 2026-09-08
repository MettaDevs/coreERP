<?php

declare(strict_types=1);

namespace Tests\Feature\ControlPlane;

use App\Models\ModuleInstallation;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\LaunchableAppCatalog;
use Database\Seeders\AppCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Kesiapan sebuah produk kini bisa datang dari dua arah, dan keduanya harus hidup
 * berdampingan sampai app terakhir dipindah.
 *
 * App yang masih berjalan sebagai container siap bila penempatannya siap. Module yang
 * berjalan di runtime Core siap bila catatan pemasangannya berstatus terpasang. Tanpa jalur
 * kedua, setiap halaman module akan 404 untuk semua orang begitu container per app hilang —
 * dan tanpa jalur pertama, app yang belum dipindah ikut mati.
 */
class ModuleReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Katalog app diisi seeder yang sama dengan yang dipakai pendaftaran usaha. Tanpa
        // itu, pendaftaran menolak app_ids karena produknya memang belum dikenal platform.
        $this->seed(AppCatalogSeeder::class);
        Queue::fake();
    }

    public function test_module_terpasang_membuat_produk_dapat_diluncurkan_tanpa_penempatan_container(): void
    {
        $membership = $this->tenantDenganHak('management-aset');

        $this->assertSame([], $this->app->make(LaunchableAppCatalog::class)->for($membership));

        $this->pasangSebagaiModule($membership->tenant_id, 'management-aset');

        $produk = $this->app->make(LaunchableAppCatalog::class)->for($membership);

        $this->assertCount(1, $produk);
        $this->assertSame('management-aset', $produk[0]['id']);
        $this->assertSame(0, DB::table('app_placements')->count(), 'Tidak boleh ada satu pun baris penempatan container.');
    }

    public function test_module_yang_dinonaktifkan_tidak_lagi_dapat_diluncurkan(): void
    {
        $membership = $this->tenantDenganHak('management-aset');
        $this->pasangSebagaiModule($membership->tenant_id, 'management-aset');

        DB::table('core_module_installations')
            ->where('tenant_id', $membership->tenant_id)
            ->update(['status' => ModuleInstallation::STATUS_DISABLED]);

        $this->assertSame([], $this->app->make(LaunchableAppCatalog::class)->for($membership));
    }

    public function test_module_terpasang_tanpa_hak_akses_tidak_muncul(): void
    {
        $membership = $this->tenantDenganHak('management-aset');

        // Hak aksesnya dicabut, pemasangannya tidak. Ini keadaan yang sengaja diuji:
        // pemasangan menjawab "module ini ada untuk tenant ini", bukan "orang ini boleh
        // membukanya". Menyimpulkan yang kedua dari yang pertama adalah kesalahan yang
        // sudah dilarang pada empat kebenaran lifecycle.
        DB::table('role_assignments')->where('membership_id', $membership->id)->delete();

        $this->pasangSebagaiModule($membership->tenant_id, 'management-aset');

        $this->assertSame(
            [],
            $this->app->make(LaunchableAppCatalog::class)->for($membership),
            'Pemasangan module bukan izin. Rantai role sampai permission tetap yang memutuskan.'
        );
    }

    public function test_module_milik_tenant_lain_tidak_muncul(): void
    {
        $membership = $this->tenantDenganHak('management-aset');
        $this->pasangSebagaiModule((string) Str::ulid(), 'management-aset');

        $this->assertSame([], $this->app->make(LaunchableAppCatalog::class)->for($membership));
    }

    public function test_penentu_menyebut_module_mana_yang_dilayani_runtime_core(): void
    {
        $membership = $this->tenantDenganHak('management-aset');
        $katalog = $this->app->make(LaunchableAppCatalog::class);

        $this->assertFalse($katalog->berjalanSebagaiModul($membership, 'management-aset'));

        $this->pasangSebagaiModule($membership->tenant_id, 'management-aset');

        $this->assertTrue($katalog->berjalanSebagaiModul($membership, 'management-aset'));
    }

    private function pasangSebagaiModule(string $tenantId, string $moduleId): void
    {
        DB::table('core_module_installations')->insert([
            'tenant_id' => $tenantId,
            'module_id' => $moduleId,
            'version' => '0.1.0',
            'status' => ModuleInstallation::STATUS_INSTALLED,
            'installed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Tenant yang pemiliknya benar-benar berhak atas app itu, lewat pendaftaran usaha yang
     * sudah ada. Membangun rantai role sampai permission dengan tangan di sini akan menjadi
     * salinan kedua dari rantai yang sebenarnya, dan salinan itu akan menyimpang.
     */
    private function tenantDenganHak(string $appId): TenantMembership
    {
        $this->postJson('/api/v1/business-registrations', [
            'name' => 'Pemilik',
            'business_name' => 'PT Modul',
            'app_ids' => [$appId],
            'email' => 'modul@metta.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertCreated();

        $pemilik = User::query()->where('email', 'modul@metta.test')->firstOrFail();

        /** @var TenantMembership $membership */
        $membership = TenantMembership::query()->where('user_id', $pemilik->id)->firstOrFail();

        return $membership;
    }
}
