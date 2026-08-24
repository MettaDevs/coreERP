<?php

namespace Tests\Feature\ControlPlane;

use App\Models\CoreApp;
use App\Models\User;
use Database\Seeders\ProviderAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class AppCatalogManagementTest extends TestCase
{
    use RefreshDatabase;

    private function providerAdmin(): User
    {
        config()->set('coreerp.provider.password', 'LocalProviderPassword!123');
        $this->seed(ProviderAdminSeeder::class);

        return User::query()->where('email', config('coreerp.provider.email'))->firstOrFail();
    }

    /**
     * Manifest empat lapis: entry point -> permission -> privilege -> duty.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function manifest(array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 'sample-app',
            'name' => 'Sample app',
            'description' => 'Aplikasi untuk memeriksa katalog.',
            'version' => '1.0.0',
            'database_name' => 'core_app_sample',
            'has_ui' => true,
            'navigation' => [
                'rail' => [
                    ['id' => 'records', 'label' => 'Data'],
                ],
                'sidebar' => [
                    'records' => [
                        ['id' => 'records', 'label' => 'Daftar data', 'permission' => 'sample-app.records.read'],
                    ],
                ],
            ],
            'repository_url' => 'https://example.test/sample-app',
            'contract_url' => 'https://example.test/sample-app/openapi.yaml',
            'security' => [
                'entry_points' => [
                    ['code' => 'sample-app.records.form', 'name' => 'Layar data', 'type' => 'form'],
                    ['code' => 'sample-app.records.api', 'name' => 'API data', 'type' => 'api'],
                ],
                'permissions' => [
                    ['code' => 'sample-app.records.read', 'name' => 'Baca data', 'entry_point' => 'sample-app.records.form', 'access' => 'read'],
                    ['code' => 'sample-app.records.create', 'name' => 'Tambah data', 'entry_point' => 'sample-app.records.api', 'access' => 'create'],
                    ['code' => 'sample-app.records.archive', 'name' => 'Arsipkan data', 'entry_point' => 'sample-app.records.api', 'access' => 'delete'],
                ],
                'privileges' => [
                    ['code' => 'sample-app.records.maintain', 'name' => 'Pelihara data', 'permissions' => ['sample-app.records.read', 'sample-app.records.create']],
                    ['code' => 'sample-app.records.retire', 'name' => 'Arsipkan data', 'permissions' => ['sample-app.records.archive']],
                ],
                'duties' => [
                    ['code' => 'sample-app.records.manage', 'name' => 'Kelola data', 'privileges' => ['sample-app.records.maintain', 'sample-app.records.retire']],
                ],
                'data_policies' => [[
                    'code' => 'sample-app.records-responsibility',
                    'name' => 'Akses data menurut unit penanggung jawab',
                    'protected_permissions' => ['sample-app.records.read', 'sample-app.records.create'],
                    'requires_legal_entity' => true,
                    'requires_operating_unit' => true,
                    'allows_descendants' => true,
                ]],
            ],
            'number_sequences' => [
                'references' => [[
                    'code' => 'sample-app.document',
                    'name' => 'Nomor dokumen',
                    'default_prefix' => 'SDOC',
                    'allowed_scopes' => ['tenant'],
                ]],
            ],
            'workflow_types' => [[
                'code' => 'sample-app.records-approval',
                'name' => 'Persetujuan data',
                'decision_context_schema' => ['required' => ['record_id']],
            ]],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function manifestFor(string $appId, array $overrides = []): array
    {
        $manifest = $this->manifest();
        array_walk_recursive($manifest, function (mixed &$value) use ($appId): void {
            if (is_string($value)) {
                $value = str_replace('sample-app', $appId, $value);
            }
        });
        $manifest['database_name'] = 'core_'.str_replace('-', '_', $appId);

        return array_replace_recursive($manifest, $overrides);
    }

    private function availableApp(string $appId, string $version = '1.0.0'): void
    {
        CoreApp::query()->create([
            'id' => $appId,
            'name' => $appId,
            'version' => $version,
            'status' => 'available',
            'database_name' => 'core_'.str_replace('-', '_', $appId),
            'has_ui' => false,
        ]);
    }

    public function test_provider_admin_can_register_an_app(): void
    {
        $this->actingAs($this->providerAdmin())
            ->postJson('/api/v1/provider/apps', $this->manifest())
            ->assertCreated()
            ->assertJsonPath('data.id', 'sample-app');

        $this->assertDatabaseHas('apps', ['id' => 'sample-app', 'database_name' => 'core_app_sample']);
        $this->assertSame(
            'sample-app.records.read',
            CoreApp::query()->findOrFail('sample-app')->navigation['sidebar']['records'][0]['permission'],
        );
        $this->assertDatabaseHas('app_number_sequence_references', [
            'code' => 'sample-app.document',
            'default_prefix' => 'SDOC',
        ]);
        $this->assertDatabaseHas('app_data_policies', [
            'code' => 'sample-app.records-responsibility',
            'app_id' => 'sample-app',
            'requires_legal_entity' => true,
            'requires_operating_unit' => true,
            'allows_descendants' => true,
        ]);
        $this->assertDatabaseHas('workflow_types', [
            'code' => 'sample-app.records-approval',
            'app_id' => 'sample-app',
        ]);
    }

    public function test_registration_stores_versioned_dependencies_and_returns_them(): void
    {
        $this->availableApp('business-partner', '1.2.0');
        $manifest = $this->manifest(['dependsOn' => ['business-partner' => '^1.0']]);

        $this->actingAs($this->providerAdmin())
            ->postJson('/api/v1/provider/apps', $manifest)
            ->assertCreated()
            ->assertJsonPath('data.dependsOn.business-partner', '^1.0');

        $this->assertDatabaseHas('app_dependencies', [
            'app_id' => 'sample-app',
            'depends_on_app_id' => 'business-partner',
            'version_range' => '^1.0',
        ]);
    }

    public function test_manifest_command_stores_versioned_dependencies(): void
    {
        $this->availableApp('business-partner', '1.2.0');
        $path = tempnam(sys_get_temp_dir(), 'coreerp-manifest-');

        try {
            $manifest = $this->manifest([
                'dependsOn' => ['business-partner' => '^1.0'],
            ]);
            $manifest['database'] = ['logical_name' => $manifest['database_name']];
            $manifest['ui'] = ['navigation' => $manifest['navigation']];
            unset($manifest['database_name'], $manifest['has_ui'], $manifest['navigation']);
            file_put_contents($path, Yaml::dump($manifest, 8, 2));

            $this->artisan('app:register-manifest', ['path' => $path])
                ->assertSuccessful();
        } finally {
            if (is_string($path) && is_file($path)) {
                unlink($path);
            }
        }

        $this->assertDatabaseHas('app_dependencies', [
            'app_id' => 'sample-app',
            'depends_on_app_id' => 'business-partner',
            'version_range' => '^1.0',
        ]);
    }

    public function test_registration_rejects_unknown_incompatible_and_cyclic_dependencies(): void
    {
        $provider = $this->providerAdmin();

        $this->actingAs($provider)
            ->postJson('/api/v1/provider/apps', $this->manifest(['dependsOn' => ['business-partner' => '^1.0']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('dependsOn.business-partner');

        $this->availableApp('business-partner', '1.0.0');
        $this->actingAs($provider)
            ->postJson('/api/v1/provider/apps', $this->manifest(['dependsOn' => ['business-partner' => '^2.0']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('dependsOn.business-partner');

        $this->actingAs($provider)
            ->postJson('/api/v1/provider/apps', $this->manifest(['dependsOn' => ['business-partner' => '^1.0']]))
            ->assertCreated();

        $this->actingAs($provider)
            ->postJson('/api/v1/provider/apps', $this->manifestFor('business-partner', [
                'dependsOn' => ['sample-app' => '^1.0'],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('dependsOn.sample-app');
    }

    public function test_registration_rejects_a_version_that_breaks_registered_dependents(): void
    {
        $this->availableApp('business-partner', '1.0.0');
        $provider = $this->providerAdmin();
        $this->actingAs($provider)
            ->postJson('/api/v1/provider/apps', $this->manifest(['dependsOn' => ['business-partner' => '^1.0']]))
            ->assertCreated();

        $this->actingAs($provider)
            ->postJson('/api/v1/provider/apps', $this->manifestFor('business-partner', ['version' => '2.0.0']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('version');
    }

    public function test_registration_removes_dependencies_that_left_the_manifest(): void
    {
        $this->availableApp('business-partner');
        $provider = $this->providerAdmin();
        $this->actingAs($provider)
            ->postJson('/api/v1/provider/apps', $this->manifest(['dependsOn' => ['business-partner' => '^1.0']]))
            ->assertCreated();

        $this->actingAs($provider)
            ->postJson('/api/v1/provider/apps', $this->manifest())
            ->assertOk();

        $this->assertDatabaseMissing('app_dependencies', [
            'app_id' => 'sample-app',
            'depends_on_app_id' => 'business-partner',
        ]);
    }

    public function test_every_number_sequence_reference_requires_a_four_letter_prefix(): void
    {
        $provider = $this->providerAdmin();

        $missing = $this->manifest();
        unset($missing['number_sequences']['references'][0]['default_prefix']);
        $this->actingAs($provider)
            ->postJson('/api/v1/provider/apps', $missing)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('number_sequences.references.0.default_prefix');

        $this->actingAs($provider)
            ->postJson('/api/v1/provider/apps', $this->manifest([
                'number_sequences' => ['references' => [[
                    'default_prefix' => 'LONGER',
                ]]],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('number_sequences.references.0.default_prefix');
    }

    public function test_registration_keeps_the_four_security_layers_distinct(): void
    {
        $this->actingAs($this->providerAdmin())
            ->postJson('/api/v1/provider/apps', $this->manifest())
            ->assertCreated();

        // Permission menunjuk entry point yang dideklarasikan, dengan access level
        // Dynamics 365 — bukan potongan terakhir dari kode permission.
        $this->assertDatabaseHas('app_entry_points', ['code' => 'sample-app.records.form', 'type' => 'form']);
        $this->assertDatabaseHas('app_entry_points', ['code' => 'sample-app.records.api', 'type' => 'api']);
        $this->assertDatabaseHas('permissions', [
            'code' => 'sample-app.records.archive',
            'entry_point_code' => 'sample-app.records.api',
            'access_level' => 'delete',
        ]);

        // Privilege bukan salinan satu-ke-satu dari permission.
        $this->assertDatabaseMissing('security_privileges', ['code' => 'sample-app.records.read']);
        $this->assertSame(2, DB::table('security_privilege_permissions')
            ->where('privilege_code', 'sample-app.records.maintain')->count());

        // Duty menunjuk privilege, bukan permission.
        $this->assertSame(2, DB::table('security_duty_privileges')
            ->where('duty_code', 'sample-app.records.manage')->count());
        $this->assertDatabaseMissing('security_duty_privileges', [
            'duty_code' => 'sample-app.records.manage',
            'privilege_code' => 'sample-app.records.read',
        ]);
    }

    public function test_privilege_cannot_reuse_a_permission_code(): void
    {
        $manifest = $this->manifest();
        $manifest['security']['privileges'][0]['code'] = 'sample-app.records.read';

        $this->actingAs($this->providerAdmin())
            ->postJson('/api/v1/provider/apps', $manifest)
            ->assertStatus(422)
            ->assertJsonValidationErrors('security.privileges');
    }

    public function test_permission_must_point_at_a_declared_entry_point(): void
    {
        $manifest = $this->manifest();
        $manifest['security']['permissions'][0]['entry_point'] = 'sample-app.records.unknown';

        $this->actingAs($this->providerAdmin())
            ->postJson('/api/v1/provider/apps', $manifest)
            ->assertStatus(422)
            ->assertJsonValidationErrors('security.permissions');
    }

    public function test_data_policy_must_use_app_code_and_declared_permissions(): void
    {
        $provider = $this->providerAdmin();
        $wrongCode = $this->manifest();
        $wrongCode['security']['data_policies'][0]['code'] = 'other-app.records-responsibility';
        $this->actingAs($provider)
            ->postJson('/api/v1/provider/apps', $wrongCode)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('security.data_policies');

        $wrongPermission = $this->manifest();
        $wrongPermission['security']['data_policies'][0]['protected_permissions'] = ['other-app.records.read'];
        $this->actingAs($provider)
            ->postJson('/api/v1/provider/apps', $wrongPermission)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('security.data_policies');
    }

    public function test_navigation_must_use_a_declared_read_permission(): void
    {
        $manifest = $this->manifest();
        $manifest['navigation']['sidebar']['records'][0]['permission'] = 'sample-app.records.create';

        $this->actingAs($this->providerAdmin())
            ->postJson('/api/v1/provider/apps', $manifest)
            ->assertStatus(422)
            ->assertJsonValidationErrors('navigation.sidebar');
    }

    public function test_duty_must_use_a_declared_privilege(): void
    {
        $manifest = $this->manifest();
        $manifest['security']['duties'][0]['privileges'] = ['sample-app.records.read'];

        $this->actingAs($this->providerAdmin())
            ->postJson('/api/v1/provider/apps', $manifest)
            ->assertStatus(422)
            ->assertJsonValidationErrors('security.duties');
    }

    public function test_security_metadata_that_left_the_manifest_is_removed(): void
    {
        $provider = $this->providerAdmin();

        $this->actingAs($provider)->postJson('/api/v1/provider/apps', $this->manifest())->assertCreated();
        $this->assertDatabaseHas('security_privileges', ['code' => 'sample-app.records.retire']);

        $trimmed = $this->manifest();
        $trimmed['security']['privileges'] = [$trimmed['security']['privileges'][0]];
        $trimmed['security']['duties'][0]['privileges'] = ['sample-app.records.maintain'];

        $this->actingAs($provider)->postJson('/api/v1/provider/apps', $trimmed)->assertOk();

        $this->assertDatabaseMissing('security_privileges', ['code' => 'sample-app.records.retire']);
    }

    public function test_standard_user_cannot_register_an_app(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/provider/apps', $this->manifest())
            ->assertForbidden();
    }
}
