<?php

namespace Tests\Feature\Foundation\WorkingCalendar;

use App\Foundation\WorkingCalendar\Models\WorkingTimeTemplate;
use App\Platform\Access\Models\AppDataPolicy;
use App\Platform\Access\Support\CoreSecurityCatalog;
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
        if ($owner) {
            AppDataPolicy::firstOrCreate(['code' => 'test.working-calendar.organizations'], [
                'app_id' => CoreSecurityCatalog::APP_ID, 'name' => 'Akses organisasi uji', 'protected_permissions' => [],
                'requires_legal_entity' => false, 'requires_operating_unit' => false, 'allows_descendants' => false,
            ]);
            $membership->roleAssignments()->firstOrFail()->dataPolicyScopes()->create([
                'tenant_id' => $tenant->id, 'policy_code' => 'test.working-calendar.organizations',
                'include_descendants' => false, 'valid_from' => now(),
            ]);
            $this->withSession(['workspace.legal_entity_id' => $org->id]);
        }

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

    public function test_commands_cannot_target_another_company_in_the_same_tenant(): void
    {
        [$user, $tenant, $company] = $this->createTenantUser();
        $otherCompany = Organization::create(['tenant_id' => $tenant->id, 'classification' => 'legal_entity', 'name' => 'Perusahaan lain', 'status' => 'active']);
        $template = WorkingTimeTemplate::create(['tenant_id' => $tenant->id, 'legal_entity_id' => $otherCompany->id, 'code' => 'OTHER', 'name' => 'Tidak berubah', 'is_active' => true]);
        $this->actingAs($user)->withSession(['workspace.legal_entity_id' => $company->id]);
        $url = "/settings/working-time-templates/{$template->id}";

        $this->putJson($url, ['code' => 'OTHER', 'name' => 'Ditimpa', 'version' => 1])->assertNotFound();
        $this->putJson("{$url}/lines", ['lines' => [], 'version' => 1])->assertNotFound();
        $this->postJson("{$url}/copy", ['code' => 'COPY', 'name' => 'Salinan'])->assertNotFound();
        $this->deleteJson($url, ['version' => 1])->assertNotFound();
        $this->postJson('/settings/working-time-templates', ['code' => 'NEW', 'name' => 'Baru', 'legal_entity_id' => $otherCompany->id])
            ->assertUnprocessable()->assertJsonValidationErrors('legal_entity_id');

        $this->assertSame('Tidak berubah', $template->refresh()->name);
        $this->assertSame(1, $template->version);
        $this->assertNull($template->deleted_at);
        $this->assertDatabaseMissing('working_time_templates', ['code' => 'COPY']);
        $this->postJson('/settings/working-time-templates', ['code' => 'OWN', 'name' => 'Perusahaan aktif'])
            ->assertCreated()->assertJsonPath('data.legal_entity_id', $company->id);
    }

    public function test_no_company_access_does_not_fall_back_to_an_arbitrary_organization(): void
    {
        [$user, $tenant, $company, $membership] = $this->createTenantUser(owner: false);
        $this->grantDuties($membership, ['core.reference-data.manage']);
        WorkingTimeTemplate::create(['tenant_id' => $tenant->id, 'legal_entity_id' => $company->id, 'code' => 'HIDDEN', 'name' => 'Pola perusahaan', 'is_active' => true]);
        $this->actingAs($user)->getJson('/settings/working-time-templates')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/settings/working-time-templates', ['code' => 'NEW', 'name' => 'Baru', 'legal_entity_id' => $company->id])
            ->assertUnprocessable()->assertJsonValidationErrors('legal_entity_id');
        $this->assertDatabaseMissing('working_time_templates', ['code' => 'NEW']);
    }

    public function test_json_requests_cannot_bypass_the_bounded_header_list(): void
    {
        [$user, $tenant, $company] = $this->createTenantUser();
        foreach (range(1, 45) as $number) {
            WorkingTimeTemplate::create(['tenant_id' => $tenant->id, 'legal_entity_id' => $company->id, 'code' => sprintf('SHIFT-%03d', $number), 'name' => 'Shift '.$number, 'is_active' => true]);
        }
        $this->actingAs($user)->withSession(['workspace.legal_entity_id' => $company->id]);
        $first = $this->getJson('/settings/working-time-templates')->assertOk()
            ->assertJsonCount(40, 'data')->assertJsonMissingPath('data.0.lines');
        $this->getJson('/settings/working-time-templates?cursor='.urlencode($first->json('next_cursor')))
            ->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('next_cursor', null);
        $this->getJson('/settings/working-time-templates?per_page=100000')
            ->assertUnprocessable()->assertJsonValidationErrors('per_page');
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
