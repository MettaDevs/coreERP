<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Foundation\FiscalCalendar\Models\FiscalCalendar;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\WorkingTimeCalendar;
use App\Models\WorkingTimeTemplate;
use App\Platform\ControlPlane\Models\Client;
use App\Support\Modules\Contracts\RowVersion;
use Database\Seeders\NumberSequenceProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\GrantsCoreRoles;
use Tests\TestCase;

/**
 * Versi baris (K-03) pada layar pengaturan Core: urutan nomor, pola dan kalender jam kerja, presisi mata
 * uang, kalender fiskal, dan satuan. Setiap endpoint dibuktikan dua hal: dua penyimpanan dengan versi yang
 * sama hanya meloloskan yang pertama, dan penyimpanan tanpa versi ditolak tanpa mengubah apa pun.
 */
final class SettingsRowVersionTest extends TestCase
{
    use GrantsCoreRoles;
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    private Organization $legalEntity;

    protected function setUp(): void
    {
        parent::setUp();

        $slug = 'tenant-'.strtolower(Str::random(6));
        $client = Client::create(['legal_name' => 'Klien '.$slug, 'slug' => $slug, 'status' => 'active']);
        $this->tenant = Tenant::create(['client_id' => $client->id, 'name' => 'Tenant '.$slug, 'slug' => $slug, 'status' => 'active']);
        $this->user = User::factory()->create();
        $this->makeOwner(TenantMembership::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id, 'status' => 'active']));

        $this->legalEntity = Organization::create(['tenant_id' => $this->tenant->id, 'classification' => 'legal_entity', 'name' => 'Entitas A', 'status' => 'active']);
        $this->legalEntity->legalEntity()->create(['tenant_id' => $this->tenant->id, 'company_code' => 'ENTA', 'country_code' => 'ID']);
    }

    public function test_urutan_nomor_lewat_api_menolak_versi_basi_dan_versi_kosong(): void
    {
        $this->seed(NumberSequenceProfileSeeder::class);
        $row = $this->actingAs($this->user)->getJson('/api/v1/number-sequences')->assertOk()->json('data.0');
        $this->assertIsInt($row['version']);
        $url = "/api/v1/number-sequences/{$row['id']}";

        $this->actingAs($this->user)->patchJson($url, [...$row, 'preallocation_quantity' => 21])
            ->assertOk()
            ->assertJsonPath('data.version', $row['version'] + 2);
        $this->actingAs($this->user)->patchJson($url, [...$row, 'preallocation_quantity' => 33])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'stale_version');
        $this->actingAs($this->user)->patchJson($url, [...array_diff_key($row, ['version' => true]), 'preallocation_quantity' => 44])
            ->assertStatus(428)
            ->assertJsonPath('error.code', 'version_required');

        $this->assertSame(21, (int) DB::table('tenant_number_sequences')->where('id', $row['id'])->value('preallocation_quantity'));
    }

    public function test_urutan_nomor_yang_ditolak_validasi_tidak_menaikkan_versi(): void
    {
        $this->seed(NumberSequenceProfileSeeder::class);
        $row = $this->actingAs($this->user)->getJson('/api/v1/number-sequences')->assertOk()->json('data.0');

        $this->actingAs($this->user)->patchJson("/api/v1/number-sequences/{$row['id']}", [...$row, 'minimum_number' => 10, 'maximum_number' => 5])
            ->assertStatus(422);

        $this->assertSame($row['version'], (int) DB::table('tenant_number_sequences')->where('id', $row['id'])->value('version'));
    }

    public function test_pola_jam_kerja_menolak_versi_basi_dan_baris_penyimpanan_pertama_bertahan(): void
    {
        $template = $this->template();
        $url = "/settings/working-time-templates/{$template->id}";
        $lines = fn (string $to): array => [['day_of_week' => 0, 'from_time' => '08:00', 'to_time' => $to]];

        $this->actingAs($this->user)->putJson($url, ['code' => 'POLA', 'name' => 'Pertama', 'lines' => $lines('12:00'), 'version' => 1])
            ->assertOk()
            ->assertJsonPath('data.version', 3);
        $this->actingAs($this->user)->putJson($url, ['code' => 'POLA', 'name' => 'Kedua', 'lines' => $lines('17:00'), 'version' => 1])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'stale_version');
        $this->actingAs($this->user)->putJson("{$url}/lines", ['lines' => $lines('17:00'), 'version' => 1])
            ->assertStatus(409);
        $this->actingAs($this->user)->putJson("{$url}/lines", ['lines' => $lines('17:00')])
            ->assertStatus(428);
        $this->actingAs($this->user)->deleteJson($url, ['version' => 1])->assertStatus(409);

        $template->refresh();
        $this->assertSame('Pertama', $template->name);
        $this->assertNull($template->deleted_at);
        $this->assertSame(['12:00:00'], $template->lines()->pluck('to_time')->all());
    }

    public function test_form_pola_jam_kerja_kembali_dengan_galat_version(): void
    {
        $template = $this->template();

        $this->actingAs($this->user)->from('/settings/working-time-templates')
            ->put("/settings/working-time-templates/{$template->id}", ['code' => 'POLA', 'name' => 'Tanpa versi'])
            ->assertRedirect('/settings/working-time-templates')
            ->assertSessionHasErrors('version');

        $this->assertSame('Pola', $template->refresh()->name);
    }

    public function test_kalender_kerja_menolak_versi_basi_pada_ubah_hari_susun_dan_arsip(): void
    {
        $calendar = WorkingTimeCalendar::create([
            'tenant_id' => $this->tenant->id, 'legal_entity_id' => $this->legalEntity->id, 'code' => 'CAL', 'name' => 'Kalender', 'standard_work_hours' => 8.0,
        ]);
        $template = $this->template();
        $compose = ['template_id' => $template->id, 'from_date' => '2026-09-07', 'to_date' => '2026-09-07'];

        $this->actingAs($this->user)->postJson("/settings/working-time-calendars/{$calendar->id}/compose", [...$compose, 'version' => 1])
            ->assertOk()
            ->assertJsonPath('version', 2);
        $this->actingAs($this->user)->postJson('/settings/compose-working-times', [...$compose, 'calendar_id' => $calendar->id, 'version' => 1])
            ->assertStatus(409);
        $this->actingAs($this->user)->postJson('/settings/compose-working-times', [...$compose, 'calendar_id' => $calendar->id])
            ->assertStatus(428);

        $day = $calendar->days()->firstOrFail();
        $dayUrl = "/settings/working-time-calendars/{$calendar->id}/days/{$day->id}";
        $this->actingAs($this->user)->putJson($dayUrl, ['control' => 'closed', 'version' => 2])->assertOk()->assertJsonPath('version', 3);
        $this->actingAs($this->user)->putJson($dayUrl, ['control' => 'open', 'version' => 2])->assertStatus(409);
        $this->assertSame('closed', $day->refresh()->control);

        $this->actingAs($this->user)->putJson("/settings/working-time-calendars/{$calendar->id}", ['code' => 'CAL', 'name' => 'Baru', 'version' => 3])
            ->assertOk()
            ->assertJsonPath('data.version', 5);
        $this->actingAs($this->user)->putJson("/settings/working-time-calendars/{$calendar->id}", ['code' => 'CAL', 'name' => 'Basi', 'version' => 3])
            ->assertStatus(409);
        $this->actingAs($this->user)->deleteJson("/settings/working-time-calendars/{$calendar->id}")->assertStatus(428);
        $this->actingAs($this->user)->deleteJson("/settings/working-time-calendars/{$calendar->id}", ['version' => 3])->assertStatus(409);

        $calendar->refresh();
        $this->assertSame('Baru', $calendar->name);
        $this->assertNull($calendar->deleted_at);
    }

    public function test_presisi_mata_uang_baris_pertama_dan_baris_yang_sudah_ada(): void
    {
        $this->actingAs($this->user)->get('/settings/currencies')->assertOk()
            ->assertInertia(fn ($page) => $page->where('currencies.0.version', 0));

        // Belum ada baris: field version wajib ada (0), lalu penyimpanan kedua yang masih membawa 0
        // berarti ia membuka nilai bawaan padahal baris sudah dibuat.
        $this->from('/settings/currencies')->put('/settings/currencies/IDR', ['amount_decimals' => 0, 'unit_amount_decimals' => 3])
            ->assertSessionHasErrors(['version' => RowVersion::REQUIRED_MESSAGE]);
        $this->from('/settings/currencies')->put('/settings/currencies/IDR', ['amount_decimals' => 0, 'unit_amount_decimals' => 3, 'version' => 0])
            ->assertSessionHasNoErrors();
        $this->from('/settings/currencies')->put('/settings/currencies/IDR', ['amount_decimals' => 1, 'unit_amount_decimals' => 3, 'version' => 0])
            ->assertSessionHasErrors(['version' => RowVersion::STALE_MESSAGE]);

        $this->actingAs($this->user)->get('/settings/currencies')
            ->assertInertia(fn ($page) => $page->where('currencies.0.version', 1));
        $this->from('/settings/currencies')->put('/settings/currencies/IDR', ['amount_decimals' => 2, 'unit_amount_decimals' => 4, 'version' => 1])
            ->assertSessionHasNoErrors();
        $this->from('/settings/currencies')->put('/settings/currencies/IDR', ['amount_decimals' => 1, 'unit_amount_decimals' => 3, 'version' => 1])
            ->assertSessionHasErrors(['version' => RowVersion::STALE_MESSAGE]);
        $this->from('/settings/currencies')->put('/settings/currencies/IDR', ['amount_decimals' => 1, 'unit_amount_decimals' => 3])
            ->assertSessionHasErrors(['version' => RowVersion::REQUIRED_MESSAGE]);

        $this->assertSame([2, 4], array_values((array) DB::table('currency_precisions')->where('tenant_id', $this->tenant->id)
            ->first(['amount_decimals', 'unit_amount_decimals'])));
    }

    public function test_kalender_fiskal_menambah_tahun_dan_menetapkan_entitas_dengan_versi(): void
    {
        $calendar = FiscalCalendar::query()->create(['tenant_id' => $this->tenant->id, 'code' => 'FY', 'name' => 'Fiskal']);
        $data = $this->actingAs($this->user)->getJson('/api/v1/fiscal-calendars')->assertOk()->json('data');
        $this->assertSame(1, $data['calendars'][0]['version']);
        $this->assertSame(1, $data['legal_entities'][0]['version']);

        $year = fn (string $name, string $start): array => ['name' => $name, 'starts_on' => $start, 'months' => 12];
        $this->actingAs($this->user)->postJson("/api/v1/fiscal-calendars/{$calendar->id}/years", [...$year('FY2026', '2026-01-01'), 'version' => 1])
            ->assertOk();
        $this->actingAs($this->user)->postJson("/api/v1/fiscal-calendars/{$calendar->id}/years", [...$year('FY2027', '2027-01-01'), 'version' => 1])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'stale_version');
        $this->actingAs($this->user)->postJson("/api/v1/fiscal-calendars/{$calendar->id}/years", $year('FY2027', '2027-01-01'))
            ->assertStatus(428);
        $this->assertSame(['FY2026'], $calendar->years()->pluck('name')->all());

        $other = FiscalCalendar::query()->create(['tenant_id' => $this->tenant->id, 'code' => 'FY2', 'name' => 'Fiskal lain']);
        $assign = ['legal_entity_id' => $this->legalEntity->id];
        $this->actingAs($this->user)->postJson("/api/v1/fiscal-calendars/{$calendar->id}/assign", [...$assign, 'version' => 1])->assertOk();
        $this->actingAs($this->user)->postJson("/api/v1/fiscal-calendars/{$other->id}/assign", [...$assign, 'version' => 1])->assertStatus(409);
        $this->actingAs($this->user)->postJson("/api/v1/fiscal-calendars/{$other->id}/assign", $assign)->assertStatus(428);

        $this->assertSame($calendar->id, DB::table('legal_entities')->where('organization_id', $this->legalEntity->id)->value('fiscal_calendar_id'));
    }

    public function test_satuan_lewat_api_menolak_versi_basi_dan_versi_kosong(): void
    {
        $classId = (string) Str::ulid();
        DB::table('uom_classes')->insert(['id' => $classId, 'tenant_id' => $this->tenant->id, 'code' => 'QTY', 'name' => 'Jumlah']);
        $unitId = (string) Str::ulid();
        DB::table('units_of_measure')->insert([
            'id' => $unitId, 'tenant_id' => $this->tenant->id, 'uom_class_id' => $classId, 'code' => 'PCS', 'name' => 'Buah', 'decimal_places' => 0,
        ]);
        $url = "/api/v1/units-of-measure/{$unitId}";

        $this->assertSame(1, $this->actingAs($this->user)->getJson('/api/v1/units-of-measure')->assertOk()->json('data.units.0.version'));
        $this->actingAs($this->user)->patchJson($url, ['name' => 'Biji', 'version' => 1])->assertCreated()->assertJsonPath('data.version', 3);
        $this->actingAs($this->user)->patchJson($url, ['name' => 'Unit', 'version' => 1])->assertStatus(409)->assertJsonPath('error.code', 'stale_version');
        $this->actingAs($this->user)->patchJson($url, ['name' => 'Unit'])->assertStatus(428);

        $this->assertSame('Biji', DB::table('units_of_measure')->where('id', $unitId)->value('name'));
    }

    private function template(): WorkingTimeTemplate
    {
        $template = WorkingTimeTemplate::create([
            'tenant_id' => $this->tenant->id, 'legal_entity_id' => $this->legalEntity->id, 'code' => 'POLA', 'name' => 'Pola', 'is_active' => true,
        ]);
        $template->lines()->create([
            'tenant_id' => $this->tenant->id, 'day_of_week' => 0, 'from_time' => '08:00:00', 'to_time' => '16:00:00', 'efficiency' => 100, 'hours' => 8.0,
        ]);

        return $template;
    }
}
