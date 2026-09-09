<?php

namespace Tests\Feature\ControlPlane;

use App\Models\CoreApp;
use App\Models\User;
use Database\Seeders\ProviderAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppReleaseRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private User $provider;

    private CoreApp $catalogApp;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('coreerp.provider.password', 'LocalProviderPassword!123');
        $this->seed(ProviderAdminSeeder::class);
        $this->provider = User::query()->where('email', config('coreerp.provider.email'))->firstOrFail();
        $this->catalogApp = CoreApp::query()->create([
            'id' => 'sample-app',
            'name' => 'Sample app',
            'version' => '1.0.0',
            'status' => 'available',
            'database_name' => 'core_app_sample',
        ]);
    }

    public function test_provider_registers_an_immutable_release_for_a_catalogued_app(): void
    {
        $this->actingAs($this->provider)
            ->postJson("/api/v1/provider/apps/{$this->catalogApp->id}/releases", $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.app_id', 'sample-app')
            ->assertJsonPath('data.version', '1.0.0')
            ->assertJsonPath('data.status', 'available');

        $this->assertDatabaseHas('app_releases', [
            'app_id' => 'sample-app',
            'version' => '1.0.0',
            'manifest_sha256' => str_repeat('a', 64),
            'edition_image' => 'registry.example/sample-edition@sha256:'.str_repeat('b', 64),
        ]);
    }

    /**
     * Ketiga nama layanan tidak lagi wajib, dan yang tidak disebutkan disimpan sebagai
     * null. String kosong akan membuat `app:render-proxy-config` dan worker Compose
     * mengira ada layanan bernama "" yang bisa dihubungi.
     */
    public function test_release_tanpa_nama_layanan_diterima_dan_disimpan_sebagai_null(): void
    {
        $this->actingAs($this->provider)
            ->postJson("/api/v1/provider/apps/{$this->catalogApp->id}/releases", $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.edition_image', 'registry.example/sample-edition@sha256:'.str_repeat('b', 64));

        $this->assertDatabaseHas('app_releases', [
            'app_id' => 'sample-app',
            'api_service' => null,
            'ui_service' => null,
            'database_service' => null,
        ]);
    }

    /**
     * Kelonggarannya berhenti pada nama layanan. Sidik jari image edisi tetap wajib:
     * tanpa satu digest yang tidak bisa berpindah, tidak ada yang bisa dibuktikan tentang
     * apa yang berjalan di server pelanggan.
     */
    public function test_release_ditolak_bila_image_edisinya_bukan_digest(): void
    {
        $this->actingAs($this->provider)
            ->postJson("/api/v1/provider/apps/{$this->catalogApp->id}/releases", [
                ...$this->payload(),
                'edition_image' => 'registry.example/sample-edition:latest',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('edition_image');
    }

    public function test_release_version_must_match_the_catalogue_until_upgrade_is_supported(): void
    {
        $this->actingAs($this->provider)
            ->postJson("/api/v1/provider/apps/{$this->catalogApp->id}/releases", [
                ...$this->payload(),
                'version' => '1.1.0',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('version');
    }

    public function test_release_version_cannot_be_registered_twice(): void
    {
        $this->actingAs($this->provider)
            ->postJson("/api/v1/provider/apps/{$this->catalogApp->id}/releases", $this->payload())
            ->assertCreated();

        $this->postJson("/api/v1/provider/apps/{$this->catalogApp->id}/releases", $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('version');
    }

    public function test_standard_user_cannot_register_a_release(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson("/api/v1/provider/apps/{$this->catalogApp->id}/releases", $this->payload())
            ->assertForbidden();
    }

    /** @return array<string, string> */
    private function payload(): array
    {
        return [
            'version' => '1.0.0',
            'manifest_sha256' => str_repeat('a', 64),
            'edition_image' => 'registry.example/sample-edition@sha256:'.str_repeat('b', 64),
            'bundle_path' => 'sample-app/1.0.0',
            'compose_file' => 'compose.yaml',
            'compose_project' => 'sample-app',
        ];
    }
}
