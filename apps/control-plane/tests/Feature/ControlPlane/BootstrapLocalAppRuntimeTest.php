<?php

namespace Tests\Feature\ControlPlane;

use App\Models\CoreApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
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

        $this->artisan('app:bootstrap-local-runtime', [
            'manifest' => $manifest,
            '--api-image' => 'local/sample-api@sha256:'.str_repeat('a', 64),
            '--ui-image' => 'local/sample-ui@sha256:'.str_repeat('b', 64),
            '--ui-entry' => 'http://localhost:18092/',
            '--api-service' => 'sample-api',
            '--ui-service' => 'sample-ui',
            '--database-service' => 'sample-db',
        ])->expectsOutputToContain('LOCAL_SERVICE_TOKEN=')
            ->expectsOutputToContain('Runtime lokal sample-app siap pada pooled-primary.')
            ->assertSuccessful();

        $this->assertDatabaseHas('app_releases', [
            'app_id' => 'sample-app',
            'version' => '1.0.0',
            'status' => 'available',
        ]);
        $this->assertDatabaseHas('app_placements', [
            'app_id' => 'sample-app',
            'placement' => 'pooled-primary',
            'artifact_status' => 'placed',
            'migration_status' => 'succeeded',
            'runtime_status' => 'ready',
            'ui_entry' => 'http://localhost:18092/',
        ]);
        $this->assertDatabaseHas('app_service_credentials', [
            'app_id' => 'sample-app',
            'tenant_id' => null,
            'name' => 'Local development',
            'status' => 'active',
        ]);

        $this->artisan('app:bootstrap-local-runtime', [
            'manifest' => $manifest,
            '--api-image' => 'local/sample-api@sha256:'.str_repeat('a', 64),
            '--ui-image' => 'local/sample-ui@sha256:'.str_repeat('b', 64),
            '--ui-entry' => 'http://localhost:18092/',
            '--api-service' => 'sample-api',
            '--ui-service' => 'sample-ui',
            '--database-service' => 'sample-db',
        ])->expectsOutputToContain('LOCAL_SERVICE_TOKEN=')
            ->assertSuccessful();

        $this->assertDatabaseCount('app_service_credentials', 1);
    }
}
