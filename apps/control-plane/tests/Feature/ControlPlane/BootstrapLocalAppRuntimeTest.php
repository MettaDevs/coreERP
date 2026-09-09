<?php

namespace Tests\Feature\ControlPlane;

use App\Models\CoreApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BootstrapLocalAppRuntimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_a_verified_local_runtime_and_issues_a_service_token(): void
    {
        CoreApp::query()->create([
            'id' => 'sample-app',
            'name' => 'Sample app',
            'version' => '1.0.0',
            'status' => 'available',
            'database_name' => 'core_app_sample',
        ]);
        $manifest = tempnam(sys_get_temp_dir(), 'coreerp-manifest-');
        $this->assertIsString($manifest);
        File::put($manifest, "id: sample-app\nversion: 1.0.0\n");

        // Tanpa satu pun nama layanan: runtime satu image tidak punya layanan API, UI,
        // maupun database yang terpisah untuk disebut namanya.
        $this->artisan('app:bootstrap-local-runtime', [
            'manifest' => $manifest,
            '--edition-image' => 'local/edisi@sha256:'.str_repeat('a', 64),
        ])->expectsOutputToContain('LOCAL_SERVICE_TOKEN=')
            ->expectsOutputToContain('Runtime lokal sample-app siap pada pooled-primary.')
            // Path konten dilaporkan agar developer tahu di mana app disajikan,
            // tanpa nilai itu pernah ditulis ke database.
            ->expectsOutputToContain('/apps-content/pooled-primary/sample-app/')
            ->assertSuccessful();

        $this->assertDatabaseHas('app_releases', [
            'app_id' => 'sample-app',
            'version' => '1.0.0',
            'status' => 'available',
            'edition_image' => 'local/edisi@sha256:'.str_repeat('a', 64),
            'api_service' => null,
            'ui_service' => null,
            'database_service' => null,
        ]);
        $this->assertDatabaseHas('app_placements', [
            'app_id' => 'sample-app',
            'placement' => 'pooled-primary',
            'artifact_status' => 'placed',
            'migration_status' => 'succeeded',
            'runtime_status' => 'ready',
        ]);
        $this->assertDatabaseHas('app_service_credentials', [
            'app_id' => 'sample-app',
            'tenant_id' => null,
            'name' => 'Local development',
            'status' => 'active',
        ]);

        $this->artisan('app:bootstrap-local-runtime', [
            'manifest' => $manifest,
            '--edition-image' => 'local/edisi@sha256:'.str_repeat('a', 64),
        ])->expectsOutputToContain('LOCAL_SERVICE_TOKEN=')
            ->assertSuccessful();

        $this->assertDatabaseCount('app_service_credentials', 1);
    }

    /**
     * Kedua kolom image dihapus, bukan sekadar berhenti diisi.
     *
     * Selama `ui_image` masih ada, catatan rilis tetap bisa menyimpan sidik jari image UI
     * yang tidak dibangun siapa pun lagi — dan sidik jari artifact yang tidak ada adalah
     * cara tercepat membuat orang berikutnya percaya artifact itu masih dibuat.
     */
    public function test_catatan_rilis_hanya_menyimpan_satu_kolom_image(): void
    {
        $this->assertTrue(Schema::hasColumn('app_releases', 'edition_image'));
        $this->assertFalse(Schema::hasColumn('app_releases', 'api_image'));
        $this->assertFalse(Schema::hasColumn('app_releases', 'ui_image'));
    }

    public function test_it_no_longer_stores_a_ui_entry_column(): void
    {
        // Kolomnya dihapus, bukan sekadar tidak diisi. Selama kolom itu ada,
        // jalur tulis mana pun bisa mengembalikan nilai basi yang mengikat host.
        $this->assertFalse(Schema::hasColumn('app_placements', 'ui_entry'));
        $this->assertFalse(Schema::hasColumn('apps', 'ui_entry'));
    }
}
