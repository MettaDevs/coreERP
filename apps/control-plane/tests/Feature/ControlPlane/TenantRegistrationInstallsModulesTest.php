<?php

declare(strict_types=1);

namespace Tests\Feature\ControlPlane;

use App\Jobs\DeployAppPlacement;
use App\Models\ModuleInstallation;
use App\Models\Tenant;
use Database\Seeders\AppCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Pendaftaran tenant baru memasang module, bukan mengantre penempatan container.
 *
 * Dua jalur dipilih dari satu pertanyaan: apakah id itu ada sebagai folder di `modules/`.
 * Jalur container dipertahankan selama masih ada app yang belum dipindah, dan dibuang pada
 * fase 7 — bukan sekarang. Test ini menjaga keduanya sekaligus, karena membuang salah
 * satunya lebih awal akan mematikan produk yang sedang dipakai.
 */
class TenantRegistrationInstallsModulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AppCatalogSeeder::class);
        Queue::fake();
    }

    public function test_tenant_baru_langsung_memiliki_module_terpasang_beserta_data_awalnya(): void
    {
        $this->daftarkan(['contoh-a']);

        $tenantId = (string) Tenant::query()->value('id');

        $this->assertDatabaseHas('core_module_installations', [
            'tenant_id' => $tenantId,
            'module_id' => 'contoh-a',
            'status' => ModuleInstallation::STATUS_INSTALLED,
        ]);

        $this->assertSame(
            2,
            DB::table('contoh_a_m_barang')->where('tenant_id', $tenantId)->where('bawaan', true)->count(),
            'Data awal module harus ikut terisi saat tenant mendaftar.'
        );
    }

    public function test_pendaftaran_module_tidak_menjalankan_penempatan_container(): void
    {
        $this->daftarkan(['contoh-a']);

        Queue::assertNotPushed(DeployAppPlacement::class);
        $this->assertSame(0, DB::table('app_placements')->count());
    }

    public function test_app_yang_belum_dipindah_tetap_memakai_jalur_penempatan_container(): void
    {
        $this->daftarkan(['management-aset']);

        Queue::assertPushed(DeployAppPlacement::class);
        $this->assertSame(
            0,
            DB::table('core_module_installations')->count(),
            'App yang masih berjalan sebagai container tidak boleh dicatat sebagai module.'
        );
    }

    public function test_mendaftar_dengan_module_dan_app_lama_sekaligus_memakai_kedua_jalur(): void
    {
        $this->daftarkan(['contoh-a', 'management-aset']);

        $tenantId = (string) Tenant::query()->value('id');

        Queue::assertPushed(DeployAppPlacement::class);
        $this->assertDatabaseHas('core_module_installations', [
            'tenant_id' => $tenantId,
            'module_id' => 'contoh-a',
        ]);
        $this->assertDatabaseMissing('core_module_installations', [
            'tenant_id' => $tenantId,
            'module_id' => 'management-aset',
        ]);
    }

    /** @param  list<string>  $appIds */
    private function daftarkan(array $appIds): void
    {
        // Module contoh belum ada di katalog app, dan pendaftaran menolak id yang tidak
        // dikenal. Ini urutan yang benar: katalog menyatakan produknya dikenal platform,
        // pemasangan menyatakan ia ada untuk tenant tertentu.
        foreach ($appIds as $appId) {
            DB::table('apps')->updateOrInsert(
                ['id' => $appId],
                [
                    'name' => ucfirst($appId),
                    'description' => 'Module contoh untuk test pendaftaran.',
                    'version' => '0.1.0',
                    'status' => 'available',
                    // Sisa rancangan database per app: kolom ini masih wajib diisi walau
                    // module memakai database yang sama dengan Core. Dicatat sebagai F2-12.
                    'database_name' => 'core_erp',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }

        $this->postJson('/api/v1/business-registrations', [
            'name' => 'Pemilik',
            'business_name' => 'PT Daftar',
            'app_ids' => $appIds,
            'email' => 'daftar@metta.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertCreated();
    }
}
