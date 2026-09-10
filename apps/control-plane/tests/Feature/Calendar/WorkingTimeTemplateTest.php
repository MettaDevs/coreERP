<?php

namespace Tests\Feature\Calendar;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkingTimeTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkingTimeTemplateTest extends TestCase
{
    use RefreshDatabase;

    private function createTenantUser(string $role = 'admin'): array
    {
        $tenant = Tenant::create([
            'name' => 'PT MettaDevs Indonesia',
            'edition' => 'enterprise',
            'status' => 'active',
        ]);

        $user = User::factory()->create();

        $membership = Membership::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'system_role' => $role,
            'status' => 'active',
        ]);

        $org = Organization::create([
            'tenant_id' => $tenant->id,
            'classification' => 'legal_entity',
            'name' => 'PT MettaDevs Indonesia',
            'status' => 'active',
        ]);

        $org->legalEntity()->create([
            'tenant_id' => $tenant->id,
            'company_code' => 'USMF',
            'country_code' => 'ID',
        ]);

        return [$user, $tenant, $org, $membership];
    }

    public function test_working_time_template_page_can_be_rendered(): void
    {
        [$user, $tenant, $org] = $this->createTenantUser('admin');

        $response = $this
            ->actingAs($user)
            ->get('/settings/working-time-templates');

        $response->assertOk();
    }

    public function test_initial_templates_are_seeded_with_prod_day_and_empty_std_day(): void
    {
        [$user, $tenant, $org] = $this->createTenantUser('admin');

        $response = $this
            ->actingAs($user)
            ->getJson('/settings/working-time-templates');

        $response->assertOk();

        // Harus ada 3 template bawaan: 24HR-DAY, PROD-DAY, STD-DAY
        $this->assertDatabaseHas('working_time_templates', [
            'tenant_id' => $tenant->id,
            'legal_entity_id' => $org->id,
            'code' => 'PROD-DAY',
        ]);

        $this->assertDatabaseHas('working_time_templates', [
            'tenant_id' => $tenant->id,
            'legal_entity_id' => $org->id,
            'code' => 'STD-DAY',
        ]);

        $stdDay = WorkingTimeTemplate::where('tenant_id', $tenant->id)
            ->where('code', 'STD-DAY')
            ->first();

        $this->assertNotNull($stdDay);
        // Sesuai arahan atasan: input std-day dikosongkan agar user yang mengisi
        $this->assertSame(0, $stdDay->lines()->count());

        $prodDay = WorkingTimeTemplate::where('tenant_id', $tenant->id)
            ->where('code', 'PROD-DAY')
            ->first();

        $this->assertNotNull($prodDay);
        // PROD-DAY memiliki jam kerja bawaan
        $this->assertGreaterThan(0, $prodDay->lines()->count());
    }

    public function test_user_can_create_new_template_with_empty_inputs(): void
    {
        [$user, $tenant, $org] = $this->createTenantUser('admin');

        $response = $this
            ->actingAs($user)
            ->postJson('/settings/working-time-templates', [
                'code' => 'SHIFT-PAGI',
                'name' => 'Shift Pagi Khusus',
                'legal_entity_id' => $org->id,
            ]);

        $response->assertCreated();

        $this->assertDatabaseHas('working_time_templates', [
            'tenant_id' => $tenant->id,
            'legal_entity_id' => $org->id,
            'code' => 'SHIFT-PAGI',
            'name' => 'Shift Pagi Khusus',
        ]);
    }

    public function test_user_can_update_day_lines_and_calculate_hours(): void
    {
        [$user, $tenant, $org] = $this->createTenantUser('admin');

        $template = WorkingTimeTemplate::create([
            'tenant_id' => $tenant->id,
            'legal_entity_id' => $org->id,
            'code' => 'CUSTOM-DAY',
            'name' => 'Custom Work Day',
            'is_active' => true,
        ]);

        $response = $this
            ->actingAs($user)
            ->putJson("/settings/working-time-templates/{$template->id}/lines", [
                'lines' => [
                    [
                        'day_of_week' => 1, // Tuesday
                        'from_time' => '08:00',
                        'to_time' => '12:00',
                        'efficiency' => 100.0,
                        'property' => null,
                        'closed_for_pickup' => false,
                        'hours' => 4.0,
                    ],
                    [
                        'day_of_week' => 1, // Tuesday
                        'from_time' => '13:00',
                        'to_time' => '17:00',
                        'efficiency' => 100.0,
                        'property' => null,
                        'closed_for_pickup' => false,
                        'hours' => 4.0,
                    ],
                ],
            ]);

        $response->assertOk();

        $this->assertSame(2, $template->lines()->count());
    }

    public function test_user_can_copy_template(): void
    {
        [$user, $tenant, $org] = $this->createTenantUser('admin');

        $source = WorkingTimeTemplate::create([
            'tenant_id' => $tenant->id,
            'legal_entity_id' => $org->id,
            'code' => 'BASE-DAY',
            'name' => 'Base Work Day',
            'is_active' => true,
        ]);

        $source->lines()->create([
            'tenant_id' => $tenant->id,
            'day_of_week' => 0,
            'from_time' => '09:00',
            'to_time' => '18:00',
            'efficiency' => 100.0,
            'closed_for_pickup' => false,
            'hours' => 9.0,
        ]);

        $response = $this
            ->actingAs($user)
            ->postJson("/settings/working-time-templates/{$source->id}/copy", [
                'code' => 'BASE-DAY-COPY',
                'name' => 'Base Work Day (Copy)',
            ]);

        $response->assertCreated();

        $this->assertDatabaseHas('working_time_templates', [
            'tenant_id' => $tenant->id,
            'code' => 'BASE-DAY-COPY',
        ]);

        $copy = WorkingTimeTemplate::where('tenant_id', $tenant->id)
            ->where('code', 'BASE-DAY-COPY')
            ->first();

        $this->assertSame(1, $copy->lines()->count());
    }

    public function test_user_can_delete_template(): void
    {
        [$user, $tenant, $org] = $this->createTenantUser('admin');

        $template = WorkingTimeTemplate::create([
            'tenant_id' => $tenant->id,
            'legal_entity_id' => $org->id,
            'code' => 'TO-DELETE',
            'name' => 'To Delete',
            'is_active' => true,
        ]);

        $response = $this
            ->actingAs($user)
            ->deleteJson("/settings/working-time-templates/{$template->id}");

        $response->assertOk();

        $this->assertSoftDeleted('working_time_templates', [
            'id' => $template->id,
        ]);
    }
}
