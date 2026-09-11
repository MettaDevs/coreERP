<?php

namespace Tests\Feature\ControlPlane;

use App\Models\CoreApp;
use App\Models\ModuleInstallation;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\AppCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BusinessOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AppCatalogSeeder::class);
        Queue::fake();
    }

    public function test_api_registration_creates_the_complete_business_boundary_atomically(): void
    {
        $response = $this->postJson('/api/v1/business-registrations', [
            'name' => 'Owner Metta',
            'business_name' => 'PT Metta',
            'app_ids' => ['app-uji'],
            'email' => 'owner@metta.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertCreated()->assertJsonPath('data.email', 'owner@metta.test');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseCount('tenants', 1);
        $tenantId = Tenant::query()->value('id');
        $this->assertDatabaseHas('units_of_measure', ['tenant_id' => $tenantId, 'code' => 'PCS', 'active' => true]);
        $kg = DB::table('units_of_measure')->where(['tenant_id' => $tenantId, 'code' => 'KG'])->value('id');
        $gram = DB::table('units_of_measure')->where(['tenant_id' => $tenantId, 'code' => 'G'])->value('id');
        $this->assertDatabaseHas('uom_conversions', ['tenant_id' => $tenantId, 'from_unit_id' => $kg, 'to_unit_id' => $gram, 'factor' => 1000]);
        $this->assertDatabaseHas('uom_conversions', ['tenant_id' => $tenantId, 'from_unit_id' => $gram, 'to_unit_id' => $kg, 'factor' => 0.001]);
        $lusin = DB::table('units_of_measure')->where(['tenant_id' => $tenantId, 'code' => 'LUSIN'])->value('id');
        $pcs = DB::table('units_of_measure')->where(['tenant_id' => $tenantId, 'code' => 'PCS'])->value('id');
        $this->assertDatabaseHas('uom_conversions', ['tenant_id' => $tenantId, 'from_unit_id' => $lusin, 'to_unit_id' => $pcs, 'factor' => 12]);
        $this->assertDatabaseCount('organizations', 0);
        $this->assertDatabaseHas('tenant_memberships', ['system_role' => 'owner', 'status' => 'active']);
        $this->assertDatabaseCount('tenant_app_entitlements', 1);
        $this->assertDatabaseCount('tenant_deployments', 1);
        $this->assertDatabaseHas('outbox_events', [
            'tenant_id' => $tenantId,
            'type' => 'core.tenant.provisioned.v1',
            'correlation_id' => $tenantId,
        ]);
        $this->assertDatabaseCount('roles', 1);
        $this->assertDatabaseCount('role_assignments', 1);
        // Role owner menerima seluruh duty app yang menjadi haknya; fixture katalog
        // mendeklarasikan dua master, jadi dua duty.
        $this->assertDatabaseCount('security_role_duties', 2);
    }

    public function test_registration_includes_transitive_app_dependencies(): void
    {
        CoreApp::query()->create([
            'id' => 'business-partner',
            'name' => 'Data pihak bisnis',
            'version' => '1.0.0',
            'status' => 'available',
            'database_name' => 'app_erp_business_partner',
            'has_ui' => false,
        ]);
        DB::table('app_dependencies')->insert([
            'app_id' => 'app-uji',
            'depends_on_app_id' => 'business-partner',
            'version_range' => '^1.0',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/v1/business-registrations', [
            'name' => 'Owner Dependency',
            'business_name' => 'PT Dependency',
            'app_ids' => ['app-uji'],
            'email' => 'dependency@metta.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertCreated();

        $tenant = Tenant::query()->where('slug', 'pt-dependency')->firstOrFail();
        $this->assertDatabaseHas('tenant_app_entitlements', ['tenant_id' => $tenant->id, 'app_id' => 'business-partner']);
        $this->assertDatabaseHas('tenant_app_entitlements', ['tenant_id' => $tenant->id, 'app_id' => 'app-uji']);
        Queue::assertNothingPushed();
    }

    /**
     * Urutan nomor tenant dibuat saat module dipasang, bukan saat sebuah penempatan
     * container dinyatakan siap.
     *
     * `contoh-a` dipakai di sini karena ia benar-benar ada sebagai folder di `modules/`;
     * hanya id yang ada di sana yang dipasang pendaftaran usaha.
     */
    public function test_registration_creates_number_sequence_drafts_for_installed_modules(): void
    {
        DB::table('apps')->updateOrInsert(
            ['id' => 'contoh-a'],
            [
                'name' => 'Contoh A',
                'description' => 'Module contoh untuk test pendaftaran.',
                'version' => '0.1.0',
                'status' => 'available',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
        DB::table('app_number_sequence_references')->insert([
            'id' => (string) Str::ulid(),
            'app_id' => 'contoh-a',
            'code' => 'contoh-a.entity-code',
            'name' => 'Kode entitas',
            'default_prefix' => 'ETA',
            'allowed_scopes' => json_encode(['tenant']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/v1/business-registrations', [
            'name' => 'Pooled Owner',
            'business_name' => 'Pooled Tenant',
            'app_ids' => ['contoh-a'],
            'email' => 'pooled@metta.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertCreated();

        Queue::assertNothingPushed();
        $this->assertSame(0, DB::table('app_placements')->count());
        $this->assertDatabaseHas('tenant_number_sequences', [
            'status' => 'active',
            'minimum_number' => 0,
            'maximum_number' => 19999,
        ]);
    }

    public function test_duplicate_email_rolls_back_without_creating_a_second_tenant(): void
    {
        $payload = [
            'name' => 'Owner Metta',
            'business_name' => 'PT Metta',
            'app_ids' => ['app-uji'],
            'email' => 'owner@metta.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ];
        $this->postJson('/api/v1/business-registrations', $payload)->assertCreated();
        $this->postJson('/api/v1/business-registrations', $payload)->assertUnprocessable();

        $this->assertDatabaseCount('tenants', 1);
    }

    public function test_registration_only_entitles_selected_apps(): void
    {
        $this->postJson('/api/v1/business-registrations', [
            'name' => 'Asset Owner',
            'business_name' => 'Asset Only',
            'app_ids' => ['app-uji'],
            'email' => 'asset@metta.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertCreated();

        $this->assertDatabaseCount('tenant_app_entitlements', 1);
        $this->assertDatabaseHas('tenant_app_entitlements', ['app_id' => 'app-uji']);
        $this->assertDatabaseCount('roles', 1);
        $this->assertDatabaseCount('role_assignments', 1);

        $owner = User::query()->where('email', 'asset@metta.test')->firstOrFail();
        $this->actingAs($owner)
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->has('entitledProducts', 1)
                ->where('entitledProducts.0.id', 'app-uji')
                ->where('entitledProducts.0.href', '/apps/app-uji')
                ->has('launchableProducts', 0)
            );
    }

    public function test_owner_can_launch_an_entitled_product_after_the_module_is_installed(): void
    {
        $this->postJson('/api/v1/business-registrations', [
            'name' => 'Owner',
            'business_name' => 'PT Ready',
            'app_ids' => ['app-uji'],
            'email' => 'ready@metta.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertCreated();
        $owner = User::query()->where('email', 'ready@metta.test')->firstOrFail();
        $tenantId = (string) Tenant::query()->where('slug', 'pt-ready')->value('id');
        DB::table('core_module_installations')->insert([
            'tenant_id' => $tenantId,
            'module_id' => 'app-uji',
            'version' => '0.1.0',
            'status' => ModuleInstallation::STATUS_INSTALLED,
            'installed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($owner)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->has('launchableProducts', 1)
            ->where('launchableProducts.0.id', 'app-uji'));

        $this->actingAs($owner)->getJson('/api/v1/launch-manifest')
            ->assertOk()
            ->assertJsonPath('data.apps.0.id', 'app-uji')
            ->assertJsonPath('data.apps.0.entry', '/apps/app-uji');

        // `/apps/<id>` tidak lagi menyajikan halaman apa pun. Ia satu pengalihan ke entri
        // menu pertama yang boleh dilihat pengguna ini, dan jalurnya diturunkan dengan
        // aturan tetap `/<id module>/<id entri menu>`.
        $this->actingAs($owner)
            ->get('/apps/app-uji')
            ->assertRedirect('/app-uji/entitas');

        DB::table('core_module_installations')
            ->where('tenant_id', $tenantId)
            ->update(['status' => ModuleInstallation::STATUS_DISABLED]);
        $this->actingAs($owner)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->has('launchableProducts', 0));
    }
}
