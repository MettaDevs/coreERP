<?php

namespace Tests\Feature\Foundation\WorkingCalendar;

use App\Foundation\WorkingCalendar\Models\WorkingTimeTemplate;
use App\Platform\ControlPlane\Models\Client;
use App\Platform\Identity\Models\User;
use App\Platform\Organization\Models\Organization;
use App\Platform\Tenant\Models\Tenant;
use App\Platform\Tenant\Models\TenantMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\GrantsCoreRoles;
use Tests\TestCase;

class WorkingTimeTemplateTest extends TestCase
{
    use GrantsCoreRoles;
    use RefreshDatabase;

    private function createTenantUser(bool $owner = true): array
    {
        $slug = 'tenant-'.strtolower(Str::random(6));

        $client = Client::create([
            'legal_name' => 'PT MettaDevs Indonesia',
            'slug' => $slug,
            'status' => 'active',
        ]);

        $tenant = Tenant::create([
            'client_id' => $client->id,
            'name' => 'PT MettaDevs Indonesia',
            'slug' => $slug,
            'status' => 'active',
        ]);

        $user = User::factory()->create();

        $membership = TenantMembership::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        if ($owner) {
            $this->makeOwner($membership);
        }

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
        [$user, $tenant, $org] = $this->createTenantUser();

        $response = $this
            ->actingAs($user)
            ->get('/settings/working-time-templates');

        $response->assertOk();
    }

    public function test_working_time_templates_empty_by_default(): void
    {
        [$user, $tenant, $org] = $this->createTenantUser();

        $response = $this
            ->actingAs($user)
            ->getJson('/settings/working-time-templates');

        $response->assertOk();

        $this->assertDatabaseCount('working_time_templates', 0);
    }

    public function test_template_list_fetches_batches_and_searches_beyond_the_first_batch(): void
    {
        [$user, $tenant, $org] = $this->createTenantUser();
        $templates = collect(range(1, 45))->map(fn (int $number) => WorkingTimeTemplate::create([
            'tenant_id' => $tenant->id,
            'legal_entity_id' => $org->id,
            'code' => sprintf('SHIFT-%03d', $number),
            'name' => 'Shift '.$number,
            'is_active' => true,
        ]));
        $this->actingAs($user);

        $first = $this->getJson('/settings/working-time-templates?incremental=1')
            ->assertOk()->assertJsonCount(40, 'data')->assertJsonMissingPath('data.0.lines');
        $second = $this->getJson('/settings/working-time-templates?'.http_build_query([
            'incremental' => 1,
            'cursor' => $first->json('next_cursor'),
        ]))->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('next_cursor', null);

        $ids = [...array_column($first->json('data'), 'id'), ...array_column($second->json('data'), 'id')];
        $this->assertCount(45, array_unique($ids));
        $this->getJson('/settings/working-time-templates?incremental=1&q=SHIFT-045')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $templates->last()->id);
        $this->getJson('/settings/working-time-templates?incremental=1&sort=tenant_id')->assertUnprocessable();
    }

    public function test_template_batches_and_details_keep_tenant_and_company_scope(): void
    {
        [$user, $tenant, $org] = $this->createTenantUser();
        [, $otherTenant, $otherOrg] = $this->createTenantUser();
        $otherCompany = Organization::create([
            'tenant_id' => $tenant->id,
            'classification' => 'legal_entity',
            'name' => 'Perusahaan lain',
            'status' => 'active',
        ]);
        $templates = collect([
            [$tenant->id, $org->id],
            [$tenant->id, $otherCompany->id],
            [$otherTenant->id, $otherOrg->id],
        ])->map(fn (array $scope) => WorkingTimeTemplate::create([
            'tenant_id' => $scope[0],
            'legal_entity_id' => $scope[1],
            'code' => 'SHIFT',
            'name' => 'Jam kerja',
            'is_active' => true,
        ]));
        $this->actingAs($user)->withSession(['workspace.legal_entity_id' => $org->id]);
        $this->getJson('/settings/working-time-templates?incremental=1')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $templates[0]->id);
        $this->getJson('/settings/working-time-templates?incremental=1&record_id='.$templates[0]->id)
            ->assertOk()->assertJsonPath('data.0.lines', []);
        foreach ([$templates[1], $templates[2]] as $hidden) {
            $this->getJson('/settings/working-time-templates?incremental=1&record_id='.$hidden->id)->assertNotFound();
        }
    }

    public function test_unauthorized_user_cannot_manage_working_time_templates(): void
    {
        [$user, $tenant, $org] = $this->createTenantUser(owner: false);

        $response = $this
            ->actingAs($user)
            ->postJson('/settings/working-time-templates', [
                'code' => 'SHIFT-PAGI',
                'name' => 'Shift Pagi Khusus',
                'legal_entity_id' => $org->id,
            ]);

        $response->assertForbidden();
    }

    public function test_user_can_create_new_template_with_empty_inputs(): void
    {
        [$user, $tenant, $org] = $this->createTenantUser();

        $response = $this
            ->actingAs($user)
            ->postJson('/settings/working-time-templates', [
                'code' => 'SHIFT-PAGI',
                'name' => 'Shift Pagi Khusus',
                'legal_entity_id' => $org->id,
            ]);

        $response->assertCreated();

        $response->assertJsonPath('data.code', 'SHIFT-PAGI')
            ->assertJsonPath('data.name', 'Shift Pagi Khusus')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.lines', []);

        $this->assertDatabaseHas('working_time_templates', [
            'tenant_id' => $tenant->id,
            'legal_entity_id' => $org->id,
            'code' => 'SHIFT-PAGI',
            'name' => 'Shift Pagi Khusus',
        ]);
    }

    public function test_user_can_update_day_lines_and_calculate_hours(): void
    {
        [$user, $tenant, $org] = $this->createTenantUser();

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
                'version' => 1,
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

    public function test_both_save_paths_preserve_zero_efficiency_and_pickup_hours_and_reject_overlap(): void
    {
        [$user, $tenant, $org] = $this->createTenantUser();
        $template = WorkingTimeTemplate::create([
            'tenant_id' => $tenant->id, 'legal_entity_id' => $org->id,
            'code' => 'INTERVALS', 'name' => 'Interval kerja', 'is_active' => true,
        ]);
        $this->actingAs($user);
        foreach (['', '/lines'] as $suffix) {
            $template->refresh();
            $line = ['day_of_week' => 0, 'from_time' => '08:00', 'to_time' => '12:00', 'efficiency' => 0, 'closed_for_pickup' => true];
            $body = ['code' => $template->code, 'name' => $template->name, 'version' => $template->version, 'lines' => [$line]];
            $this->putJson("/settings/working-time-templates/{$template->id}{$suffix}", $body)->assertOk()
                ->assertJsonPath('data.lines.0.efficiency', 0)
                ->assertJsonPath('data.lines.0.hours', 4)
                ->assertJsonPath('data.lines.0.closed_for_pickup', true);
            $template->refresh();
            $version = $template->version;
            $body['version'] = $version;
            $body['lines'][] = [...$line, 'from_time' => '11:00', 'to_time' => '13:00'];
            $this->putJson("/settings/working-time-templates/{$template->id}{$suffix}", $body)
                ->assertUnprocessable()->assertJsonValidationErrors('lines.1.from_time');
            $this->assertSame($version, $template->refresh()->version);
            $this->assertSame(1, $template->lines()->count());
            $this->assertSame(4.0, $template->lines()->first()->hours);
        }
    }

    public function test_user_can_copy_template(): void
    {
        [$user, $tenant, $org] = $this->createTenantUser();

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

        $response->assertCreated()
            ->assertJsonPath('data.code', 'BASE-DAY-COPY')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.lines.0.from_time', '09:00');

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
        [$user, $tenant, $org] = $this->createTenantUser();

        $template = WorkingTimeTemplate::create([
            'tenant_id' => $tenant->id,
            'legal_entity_id' => $org->id,
            'code' => 'TO-DELETE',
            'name' => 'To Delete',
            'is_active' => true,
        ]);

        $response = $this
            ->actingAs($user)
            ->deleteJson("/settings/working-time-templates/{$template->id}", ['version' => 1]);

        $response->assertOk();

        $this->assertSoftDeleted('working_time_templates', [
            'id' => $template->id,
        ]);
    }
}
