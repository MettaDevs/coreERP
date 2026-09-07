<?php

namespace Tests\Feature\ControlPlane;

use App\Actions\Onboarding\RegisterBusiness;
use App\Models\RoleAssignment;
use App\Models\SecurityDuty;
use App\Models\SecurityPrivilege;
use App\Models\User;
use App\Support\LaunchableAppCatalog;
use Database\Seeders\AppCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AppCatalogSeeder::class);
        $this->owner = app(RegisterBusiness::class)->handle([
            'name' => 'Owner', 'business_name' => 'Tenant test',
            'app_ids' => ['management-aset'], 'email' => 'owner@security.test', 'password' => 'password',
        ]);
    }

    public function test_custom_security_configuration_is_drafted_published_and_then_usable_by_a_role(): void
    {
        $this->actingAs($this->owner)->post('/settings/security-configuration/privileges', [
            'name' => 'Lihat perencanaan',
            'permission_codes' => ['management-aset.perencanaan-aset.read'],
        ])->assertRedirect();
        $privilege = SecurityPrivilege::query()->where('source', 'custom')->firstOrFail();
        $this->assertSame('draft', $privilege->status);

        $this->actingAs($this->owner)->post('/settings/security-configuration/duties', [
            'name' => 'Pembaca perencanaan', 'privilege_codes' => [$privilege->code],
        ])->assertRedirect();
        $duty = SecurityDuty::query()->where('source', 'custom')->firstOrFail();

        // Draf tidak boleh menjadi sumber hak role.
        $this->actingAs($this->owner)->postJson('/api/v1/roles', [
            'name' => 'Pembaca', 'duty_codes' => [$duty->code],
        ])->assertStatus(422);

        $this->actingAs($this->owner)->post("/settings/security-configuration/privileges/{$privilege->code}/publish")->assertRedirect();
        $this->actingAs($this->owner)->post("/settings/security-configuration/duties/{$duty->code}/publish")->assertRedirect();

        $roleId = $this->actingAs($this->owner)->postJson('/api/v1/roles', [
            'name' => 'Pembaca', 'duty_codes' => [$duty->code],
        ])->assertCreated()->json('data.id');
        $this->assertNotNull($roleId);
        $this->assertSame('active', $duty->refresh()->status);
        RoleAssignment::create([
            'membership_id' => $this->owner->activeMembership()->id,
            'role_id' => $roleId,
            'source' => 'manual', 'status' => 'active', 'valid_from' => now(),
        ]);
        $this->assertContains('management-aset.perencanaan-aset.read', app(LaunchableAppCatalog::class)
            ->permissionsFor($this->owner->activeMembership()->refresh(), 'management-aset'));
    }
}
