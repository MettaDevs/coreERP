<?php

namespace Tests\Feature\Calendar;

use App\Models\Client;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\WorkingTimeCalendar;
use App\Models\WorkingTimeCalendarDay;
use App\Models\WorkingTimeTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkingTimeCalendarTest extends TestCase
{
    use RefreshDatabase;

    private function createTenantUser(string $role = 'admin'): array
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

    public function test_working_time_calendar_page_can_be_rendered(): void
    {
        [$user, $tenant, $org] = $this->createTenantUser('admin');

        $response = $this
            ->actingAs($user)
            ->get('/settings/working-time-calendars');

        $response->assertOk();
    }

    public function test_user_can_create_calendar(): void
    {
        [$user, $tenant, $org] = $this->createTenantUser('admin');

        $response = $this
            ->actingAs($user)
            ->postJson('/settings/working-time-calendars', [
                'code' => 'PROD-24HR',
                'name' => 'Production 24 Hours',
                'standard_work_hours' => 24.0,
            ]);

        $response->assertCreated();

        $this->assertDatabaseHas('working_time_calendars', [
            'tenant_id' => $tenant->id,
            'code' => 'PROD-24HR',
            'name' => 'Production 24 Hours',
            'standard_work_hours' => 24.0,
        ]);
    }

    public function test_user_can_update_calendar(): void
    {
        [$user, $tenant, $org] = $this->createTenantUser('admin');

        $calendar = WorkingTimeCalendar::create([
            'tenant_id' => $tenant->id,
            'legal_entity_id' => $org->id,
            'code' => 'STD-DAY',
            'name' => 'Standard Day Calendar',
            'standard_work_hours' => 8.0,
        ]);

        $response = $this
            ->actingAs($user)
            ->putJson("/settings/working-time-calendars/{$calendar->id}", [
                'code' => 'STD-DAY-UPDATED',
                'name' => 'Updated Calendar Name',
                'standard_work_hours' => 7.5,
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('working_time_calendars', [
            'id' => $calendar->id,
            'code' => 'STD-DAY-UPDATED',
            'name' => 'Updated Calendar Name',
            'standard_work_hours' => 7.5,
        ]);
    }

    public function test_user_can_soft_delete_calendar(): void
    {
        [$user, $tenant, $org] = $this->createTenantUser('admin');

        $calendar = WorkingTimeCalendar::create([
            'tenant_id' => $tenant->id,
            'legal_entity_id' => $org->id,
            'code' => 'TO-DELETE',
            'name' => 'Calendar To Delete',
        ]);

        $response = $this
            ->actingAs($user)
            ->deleteJson("/settings/working-time-calendars/{$calendar->id}");

        $response->assertOk();

        $this->assertSoftDeleted('working_time_calendars', [
            'id' => $calendar->id,
        ]);
    }

    public function test_user_can_copy_calendar(): void
    {
        [$user, $tenant, $org] = $this->createTenantUser('admin');

        $calendar = WorkingTimeCalendar::create([
            'tenant_id' => $tenant->id,
            'legal_entity_id' => $org->id,
            'code' => 'ORIGINAL',
            'name' => 'Original Calendar',
            'standard_work_hours' => 8.0,
        ]);

        $response = $this
            ->actingAs($user)
            ->postJson("/settings/working-time-calendars/{$calendar->id}/copy", [
                'code' => 'ORIGINAL-COPY',
                'name' => 'Copied Calendar',
            ]);

        $response->assertCreated();

        $this->assertDatabaseHas('working_time_calendars', [
            'tenant_id' => $tenant->id,
            'code' => 'ORIGINAL-COPY',
            'name' => 'Copied Calendar',
        ]);
    }

    public function test_compose_working_times_generates_days_and_lines_accurately(): void
    {
        [$user, $tenant, $org] = $this->createTenantUser('admin');

        $calendar = WorkingTimeCalendar::create([
            'tenant_id' => $tenant->id,
            'legal_entity_id' => $org->id,
            'code' => 'PROD',
            'name' => 'Production Calendar',
            'standard_work_hours' => 8.0,
        ]);

        // Buat template pola jam kerja: Senin s/d Jumat masuk 08:00 - 16:00 (8 jam)
        $template = WorkingTimeTemplate::create([
            'tenant_id' => $tenant->id,
            'legal_entity_id' => $org->id,
            'code' => 'TPL-REGULER',
            'name' => 'Pola Reguler 5 Hari',
        ]);

        // 0=Senin, 1=Selasa, 2=Rabu, 3=Kamis, 4=Jumat (8 jam), 5=Sabtu (0 jam/libur), 6=Minggu (0 jam/libur)
        for ($day = 0; $day <= 4; $day++) {
            $template->lines()->create([
                'tenant_id' => $tenant->id,
                'day_of_week' => $day,
                'from_time' => '08:00:00',
                'to_time' => '16:00:00',
                'efficiency' => 100.0,
                'hours' => 8.0,
            ]);
        }

        // Jalankan compose untuk 1 minggu: 2026-09-07 (Senin) s/d 2026-09-13 (Minggu)
        $response = $this
            ->actingAs($user)
            ->postJson("/settings/working-time-calendars/{$calendar->id}/compose", [
                'template_id' => $template->id,
                'from_date' => '2026-09-07',
                'to_date' => '2026-09-13',
            ]);

        $response->assertOk();
        $response->assertJsonPath('days_processed', 7);

        // Verifikasi hari kerja Senin (Open, 8 jam)
        $this->assertDatabaseHas('working_time_calendar_days', [
            'working_time_calendar_id' => $calendar->id,
            'date' => '2026-09-07',
            'control' => 'open',
            'hours' => 8.0,
        ]);

        // Verifikasi hari kerja Minggu (Closed, 0 jam)
        $this->assertDatabaseHas('working_time_calendar_days', [
            'working_time_calendar_id' => $calendar->id,
            'date' => '2026-09-13',
            'control' => 'closed',
            'hours' => 0.0,
        ]);
    }

    public function test_tenant_isolation_is_strictly_enforced(): void
    {
        [$userA, $tenantA, $orgA] = $this->createTenantUser('admin');
        [$userB, $tenantB, $orgB] = $this->createTenantUser('admin');

        $calendarA = WorkingTimeCalendar::create([
            'tenant_id' => $tenantA->id,
            'legal_entity_id' => $orgA->id,
            'code' => 'CAL-A',
            'name' => 'Calendar Tenant A',
        ]);

        // User B tidak boleh dapat mengakses kalender milik Tenant A
        $response = $this
            ->actingAs($userB)
            ->getJson("/settings/working-time-calendars/{$calendarA->id}/times");

        $response->assertNotFound();
    }
}
