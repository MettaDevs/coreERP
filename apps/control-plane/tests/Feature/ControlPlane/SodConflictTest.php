<?php

namespace Tests\Feature\ControlPlane;

use App\Actions\Onboarding\RegisterBusiness;
use App\Models\Role;
use App\Models\TenantMembership;
use App\Models\User;
use Database\Seeders\AppCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SodConflictTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private TenantMembership $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AppCatalogSeeder::class);
        $this->owner = app(RegisterBusiness::class)->handle([
            'name' => 'Owner', 'business_name' => 'Tenant test',
            'app_ids' => ['management-aset'], 'email' => 'owner@sod.test', 'password' => 'password',
        ]);
        $user = User::factory()->create();
        $this->member = TenantMembership::create([
            'tenant_id' => $this->owner->activeMembership()->tenant_id,
            'user_id' => $user->id, 'system_role' => 'user', 'status' => 'active',
        ]);
    }

    public function test_manual_assignment_rejects_conflicting_effective_duties(): void
    {
        $first = $this->createRole('Pengaju', ['management-aset.entitas-aset.manage']);
        $second = $this->createRole('Verifikator', ['management-aset.group-aset.manage']);
        DB::table('sod_rules')->insert([
            'id' => (string) \Illuminate\Support\Str::ulid(),
            'tenant_id' => $this->member->tenant_id,
            'first_duty_code' => 'management-aset.entitas-aset.manage',
            'second_duty_code' => 'management-aset.group-aset.manage',
            'severity' => 'high', 'risk' => 'Pengaju dan verifikator harus berbeda.',
            'allows_mitigation' => false, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->owner)->patchJson("/api/v1/memberships/{$this->member->id}", [
            'system_role' => 'user',
            'assignments' => [['role_id' => $first->id], ['role_id' => $second->id]],
        ])->assertUnprocessable()->assertJsonValidationErrors('assignments');

        $this->assertDatabaseMissing('role_assignments', ['membership_id' => $this->member->id]);
    }

    private function createRole(string $name, array $duties): Role
    {
        $id = $this->actingAs($this->owner)->postJson('/api/v1/roles', [
            'name' => $name, 'duty_codes' => $duties,
        ])->assertCreated()->json('data.id');

        return Role::findOrFail($id);
    }
}
