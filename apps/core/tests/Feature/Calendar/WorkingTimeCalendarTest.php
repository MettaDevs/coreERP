<?php

namespace Tests\Feature\Calendar;

use App\Models\Client;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\WorkingTimeCalendar;
use App\Models\WorkingTimeCalendarDay;
use App\Models\WorkingTimeCalendarLine;
use App\Models\WorkingTimeTemplate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\GrantsCoreRoles;
use Tests\TestCase;

class WorkingTimeCalendarTest extends TestCase
{
    use GrantsCoreRoles;
    use RefreshDatabase;

    public function test_kode_disimpan_huruf_besar_dan_kode_ganda_ditolak_apa_pun_hurufnya(): void
    {
        [$user] = $this->tenantUser();

        $this->actingAs($user)
            ->postJson('/settings/working-time-calendars', ['code' => 'std-5', 'name' => 'Standar'])
            ->assertCreated()
            ->assertJsonPath('data.code', 'STD-5');

        // Validasi sebelumnya membandingkan kode seperti yang diketik, sedangkan yang disimpan
        // huruf besarnya: `std-5` lolos di samping `STD-5`.
        foreach (['STD-5', 'std-5', ' Std-5 '] as $kode) {
            $this->actingAs($user)
                ->postJson('/settings/working-time-calendars', ['code' => $kode, 'name' => 'Ganda'])
                ->assertStatus(422)
                ->assertJsonValidationErrors('code');
        }

        $this->assertSame(1, WorkingTimeCalendar::query()->where('code', 'STD-5')->count());
    }

    public function test_indeks_unik_kode_per_entitas_legal_dan_mengabaikan_kalender_terarsip(): void
    {
        [, $tenant, $orgA] = $this->tenantUser();
        $orgB = $this->legalEntity($tenant, 'Entitas B');

        $pertama = $this->calendar($tenant, $orgA, 'STD');
        // Entitas legal lain boleh memakai kode yang sama: daftar kalender memang per entitas.
        $this->calendar($tenant, $orgB, 'STD');

        try {
            // Transaksi bersarang menjadi SAVEPOINT; tanpanya insert yang ditolak membatalkan
            // seluruh transaksi test, dan langkah berikutnya ikut gagal.
            DB::transaction(fn () => $this->calendar($tenant, $orgA, 'STD'));
            $this->fail('Dua kalender hidup dengan kode sama di satu entitas legal seharusnya ditolak database.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('working_time_calendars_code_unique', $exception->getMessage());
        }

        $pertama->delete();
        $this->calendar($tenant, $orgA, 'STD');
        $this->assertSame(3, WorkingTimeCalendar::withTrashed()->where('code', 'STD')->count());
    }

    public function test_ubah_menjaga_keunikan_kode_dan_arsip_hanya_mengisi_deleted_at(): void
    {
        [$user, $tenant, $org] = $this->tenantUser();
        $calendar = $this->calendar($tenant, $org, 'CAL-1');
        $this->calendar($tenant, $org, 'CAL-2');

        $this->actingAs($user)
            ->putJson("/settings/working-time-calendars/{$calendar->id}", ['code' => 'cal-1', 'name' => 'Nama baru'])
            ->assertOk();
        $this->assertSame('Nama baru', $calendar->refresh()->name);

        $this->actingAs($user)
            ->putJson("/settings/working-time-calendars/{$calendar->id}", ['code' => 'cal-2', 'name' => 'Bentrok'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        $this->actingAs($user)->deleteJson("/settings/working-time-calendars/{$calendar->id}")->assertOk();
        $this->assertSoftDeleted($calendar);
    }

    public function test_susun_jadwal_membuat_hari_dan_jam_sesuai_pola_dan_menimpa_saat_diulang(): void
    {
        [$user, $tenant, $org] = $this->tenantUser();
        $calendar = $this->calendar($tenant, $org);
        $template = $this->template($tenant, $org);

        // 7–13 September 2026: Senin sampai Minggu.
        $this->compose($user, $calendar, $template)->assertOk()->assertJsonPath('days_processed', 7);

        $days = $calendar->days()->with('lines')->orderBy('date')->get();
        $this->assertSame([0, 1, 2, 3, 4, 5, 6], $days->pluck('day_of_week')->all());
        $this->assertSame(['open', 'open', 'open', 'open', 'open', 'closed', 'closed'], $days->pluck('control')->all());
        $this->assertEquals([8, 8, 8, 8, 8, 0, 0], $days->pluck('hours')->all());
        $this->assertSame(['08:00:00', '16:00:00'], [$days[0]->lines[0]->from_time, $days[0]->lines[0]->to_time]);
        $this->assertTrue($days[6]->lines->isEmpty());

        // Hari yang diubah tangan ditimpa kembali oleh pola, dan tidak ada hari atau baris ganda.
        $this->actingAs($user)
            ->putJson("/settings/working-time-calendars/{$calendar->id}/days/{$days[0]->id}", ['control' => 'closed'])
            ->assertOk();
        $this->compose($user, $calendar, $template)->assertOk();

        $this->assertSame(7, $calendar->days()->count());
        $this->assertSame(5, $this->linesOf($calendar)->count());
        $this->assertSame('open', $days[0]->refresh()->control);
        $this->assertEquals(8.0, $days[0]->hours);
        $this->assertSame($days[0]->id, $calendar->days()->orderBy('date')->first()?->id, 'Hari yang sudah ada mempertahankan id-nya.');
    }

    public function test_salin_kalender_menyalin_hari_dan_jam_kerjanya(): void
    {
        [$user, $tenant, $org] = $this->tenantUser();
        $calendar = $this->calendar($tenant, $org);
        $this->compose($user, $calendar, $this->template($tenant, $org))->assertOk();

        $this->actingAs($user)
            ->postJson("/settings/working-time-calendars/{$calendar->id}/copy", ['code' => 'cal-salin', 'name' => 'Salinan'])
            ->assertCreated()
            ->assertJsonPath('data.code', 'CAL-SALIN');

        $copy = WorkingTimeCalendar::query()->where('code', 'CAL-SALIN')->firstOrFail();
        $this->assertSame($org->id, $copy->legal_entity_id);
        $this->assertSame(7, $copy->days()->count());
        $this->assertSame(
            $this->linesOf($calendar)->orderBy('from_time')->get(['from_time', 'to_time', 'hours'])->toArray(),
            $this->linesOf($copy)->orderBy('from_time')->get(['from_time', 'to_time', 'hours'])->toArray(),
        );
    }

    public function test_menutup_lalu_membuka_hari_memulihkan_jam_kerjanya(): void
    {
        [$user, $tenant, $org] = $this->tenantUser();
        $calendar = $this->calendar($tenant, $org);
        $this->compose($user, $calendar, $this->template($tenant, $org), '2026-09-07', '2026-09-07')->assertOk();
        $day = $calendar->days()->firstOrFail();
        $url = "/settings/working-time-calendars/{$calendar->id}/days/{$day->id}";

        $this->actingAs($user)->putJson($url, ['control' => 'closed'])->assertOk();
        $this->assertEquals(0.0, $day->refresh()->hours);
        $this->assertSame(1, $day->lines()->count(), 'Menutup hari tidak membuang jam kerjanya.');

        // Sebelumnya hari yang dibuka kembali tetap 0 jam karena jumlah jamnya diambil dari
        // nilai tersimpan, yang baru saja dinolkan saat ditutup.
        $this->actingAs($user)->putJson($url, ['control' => 'open'])->assertOk();
        $day->refresh();
        $this->assertSame('open', $day->control);
        $this->assertEquals(8.0, $day->hours);
        $this->assertFalse($day->closed_for_pickup, 'Tutup pengambilan hanya berubah bila memang dikirim.');
    }

    public function test_jam_kerja_hari_dihitung_di_server_dan_jam_yang_tidak_masuk_akal_ditolak(): void
    {
        [$user, $tenant, $org] = $this->tenantUser();
        $calendar = $this->calendar($tenant, $org);
        $this->compose($user, $calendar, $this->template($tenant, $org), '2026-09-07', '2026-09-07')->assertOk();
        $day = $calendar->days()->firstOrFail();
        $url = "/settings/working-time-calendars/{$calendar->id}/days/{$day->id}";

        // Jumlah jam kiriman pengguna diabaikan; yang dipakai selisih jam mulai dan selesai.
        // `24:00` diterima karena pola jam kerja memang menyimpannya.
        $this->actingAs($user)->putJson($url, ['control' => 'open', 'lines' => [
            ['from_time' => '08:00', 'to_time' => '12:00', 'hours' => 24],
            ['from_time' => '16:00', 'to_time' => '24:00', 'hours' => 24],
        ]])->assertOk();
        $this->assertEquals(12.0, $day->refresh()->hours);
        $this->assertEquals([4.0, 8.0], $day->lines()->orderBy('from_time')->pluck('hours')->all());

        $tolak = fn (array $lines, string $field) => $this->actingAs($user)
            ->putJson($url, ['control' => 'open', 'lines' => $lines])
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);

        $tolak([['from_time' => '17:00', 'to_time' => '08:00']], 'lines.0.to_time');
        $tolak([['from_time' => '08:00', 'to_time' => '12:00'], ['from_time' => '11:00', 'to_time' => '13:00']], 'lines.1.from_time');
        $tolak([['from_time' => '08:00']], 'lines.0.to_time');
        $tolak([['from_time' => '8:00', 'to_time' => '25:00']], 'lines.0.from_time');
        $tolak([['hours' => 20], ['hours' => 20]], 'lines');

        $this->assertEquals(12.0, $day->refresh()->hours, 'Kiriman yang ditolak tidak mengubah hari.');
    }

    public function test_rentang_penyusunan_lebih_dari_tiga_tahun_dijawab_pesan_bukan_galat(): void
    {
        [$user, $tenant, $org] = $this->tenantUser();
        $calendar = $this->calendar($tenant, $org);
        $template = $this->template($tenant, $org);

        $this->compose($user, $calendar, $template, '2026-01-01', '2030-01-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors('to_date');
        $this->actingAs($user)->postJson('/settings/compose-working-times', [
            'calendar_id' => $calendar->id, 'template_id' => $template->id,
            'from_date' => '2026-01-01', 'to_date' => '2030-01-01',
        ])->assertStatus(422)->assertJsonValidationErrors('to_date');

        $this->assertSame(0, $calendar->days()->count());
    }

    public function test_pola_jam_kerja_harus_aktif_dan_milik_entitas_legal_kalendernya(): void
    {
        [$user, $tenant, $org] = $this->tenantUser();
        $calendar = $this->calendar($tenant, $org);

        $this->compose($user, $calendar, $this->template($tenant, $org, active: false), '2026-09-07', '2026-09-07')
            ->assertStatus(422)
            ->assertJsonValidationErrors('template_id');
        $this->compose($user, $calendar, $this->template($tenant, $this->legalEntity($tenant, 'Entitas lain')), '2026-09-07', '2026-09-07')
            ->assertStatus(422)
            ->assertJsonValidationErrors('template_id');

        $this->assertSame(0, $calendar->days()->count());
    }

    public function test_tanggal_tidak_valid_di_halaman_jadwal_diganti_bulan_berjalan(): void
    {
        [$user, $tenant, $org] = $this->tenantUser();
        $calendar = $this->calendar($tenant, $org);
        $bulanIni = [now()->startOfMonth()->format('Y-m-d'), now()->endOfMonth()->format('Y-m-d')];

        foreach (['abc', '2026-02-31', '07-09-2026'] as $tanggal) {
            $this->actingAs($user)
                ->getJson("/settings/working-time-calendars/{$calendar->id}/times?from={$tanggal}&to={$tanggal}")
                ->assertOk()
                ->assertJsonPath('data.from', $bulanIni[0])
                ->assertJsonPath('data.to', $bulanIni[1]);
        }

        $this->actingAs($user)
            ->getJson("/settings/working-time-calendars/{$calendar->id}/times?from=2026-09-30&to=2026-09-01")
            ->assertOk()
            ->assertJsonPath('data.from', '2026-09-01')
            ->assertJsonPath('data.to', '2026-09-30');
    }

    public function test_daftar_dan_pilihan_hanya_milik_entitas_legal_yang_sedang_dipakai(): void
    {
        [$user, $tenant, $aktif] = $this->tenantUser(true, 'Entitas aktif');
        $lain = $this->legalEntity($tenant, 'Entitas lain');
        $this->calendar($tenant, $lain, 'MILIK-LAIN');
        $this->template($tenant, $lain);
        $sesi = ['workspace.legal_entity_id' => $aktif->id];

        // Sebelumnya entitas yang belum punya kalender justru diperlihatkan kalender entitas lain.
        $this->actingAs($user)->withSession($sesi)->getJson('/settings/working-time-calendars')
            ->assertOk()
            ->assertJsonPath('data.current_legal_entity.name', 'Entitas aktif')
            ->assertJsonPath('data.calendars', []);
        $this->actingAs($user)->withSession($sesi)->getJson('/settings/compose-working-times')
            ->assertOk()
            ->assertJsonPath('data.calendars', [])
            ->assertJsonPath('data.templates', []);
    }

    public function test_tenant_lain_tidak_dapat_membaca_atau_mengubah_apa_pun(): void
    {
        [$userA, $tenantA, $orgA] = $this->tenantUser();
        [$userB, $tenantB, $orgB] = $this->tenantUser();

        $calA = $this->calendar($tenantA, $orgA, 'CALA');
        $calA2 = $this->calendar($tenantA, $orgA, 'CALA2');
        $tplA = $this->template($tenantA, $orgA);
        $this->compose($userA, $calA, $tplA, '2026-09-07', '2026-09-07')->assertOk();
        $dayA = $calA->days()->firstOrFail();
        $calB = $this->calendar($tenantB, $orgB, 'CALB');
        $tplB = $this->template($tenantB, $orgB);

        $this->actingAs($userB)->putJson("/settings/working-time-calendars/{$calB->id}/days/{$dayA->id}", ['control' => 'closed'])->assertNotFound();
        $this->actingAs($userB)->putJson("/settings/working-time-calendars/{$calA->id}/days/{$dayA->id}", ['control' => 'closed'])->assertNotFound();
        $this->actingAs($userA)->putJson("/settings/working-time-calendars/{$calA2->id}/days/{$dayA->id}", ['control' => 'closed'])->assertNotFound();
        $this->compose($userB, $calB, $tplA, '2026-09-07', '2026-09-07')->assertStatus(422);
        $this->compose($userB, $calA, $tplB, '2026-09-07', '2026-09-07')->assertNotFound();
        $this->actingAs($userB)->postJson('/settings/compose-working-times', [
            'calendar_id' => $calA->id, 'template_id' => $tplB->id, 'from_date' => '2026-09-07', 'to_date' => '2026-09-07',
        ])->assertStatus(422);
        $this->actingAs($userB)->postJson('/settings/compose-working-times', [
            'calendar_id' => $calB->id, 'template_id' => $tplA->id, 'from_date' => '2026-09-07', 'to_date' => '2026-09-07',
        ])->assertStatus(422);
        $this->actingAs($userB)->postJson('/settings/working-time-calendars', ['code' => 'X1', 'name' => 'X', 'base_calendar_id' => $calA->id])->assertStatus(422);
        $this->actingAs($userB)->putJson("/settings/working-time-calendars/{$calA->id}", ['code' => 'HACK', 'name' => 'X'])->assertNotFound();
        $this->actingAs($userB)->deleteJson("/settings/working-time-calendars/{$calA->id}")->assertNotFound();
        $this->actingAs($userB)->postJson("/settings/working-time-calendars/{$calA->id}/copy", ['code' => 'CURI', 'name' => 'X'])->assertNotFound();
        $this->actingAs($userB)->getJson("/settings/working-time-calendars/{$calA->id}/times")->assertNotFound();
        $this->assertNotSame(
            $calA->id,
            $this->actingAs($userB)->getJson('/settings/working-time-calendar-times?calendar_id='.$calA->id)->assertOk()->json('data.calendar.id'),
        );

        $this->assertSame('open', $dayA->refresh()->control);
        $this->assertSame('CALA', $calA->refresh()->code);
        $this->assertNull($calA->deleted_at);
        $this->assertSame(0, WorkingTimeCalendar::query()->where('code', 'CURI')->count());
        $this->assertSame(1, $calA->days()->count());
    }

    public function test_pengguna_baca_saja_tidak_dapat_mengubah_dan_tidak_membuka_halaman_penyusunan(): void
    {
        [$user, $tenant, $org, $membership] = $this->tenantUser(false);
        $this->grantDuties($membership, ['core.reference-data.inquire']);
        $calendar = $this->calendar($tenant, $org);
        $template = $this->template($tenant, $org);
        $day = $calendar->days()->create(['tenant_id' => $tenant->id, 'date' => '2026-09-07', 'day_of_week' => 0, 'control' => 'open', 'hours' => 8]);

        $this->actingAs($user)->get('/settings/working-time-calendars')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('settings/working-time-calendars')->where('canManage', false)->etc());
        $this->actingAs($user)->get("/settings/working-time-calendars/{$calendar->id}/times")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canManage', false)->etc());
        // Halaman "Jadwal dari pola" hanya berisi formulir penyusunan.
        $this->actingAs($user)->get('/settings/compose-working-times')->assertForbidden();

        $this->actingAs($user)->postJson('/settings/working-time-calendars', ['code' => 'NEW', 'name' => 'X'])->assertForbidden();
        $this->actingAs($user)->putJson("/settings/working-time-calendars/{$calendar->id}", ['code' => 'CAL', 'name' => 'X'])->assertForbidden();
        $this->actingAs($user)->deleteJson("/settings/working-time-calendars/{$calendar->id}")->assertForbidden();
        $this->actingAs($user)->postJson("/settings/working-time-calendars/{$calendar->id}/copy", ['code' => 'C2', 'name' => 'X'])->assertForbidden();
        $this->actingAs($user)->putJson("/settings/working-time-calendars/{$calendar->id}/days/{$day->id}", ['control' => 'closed'])->assertForbidden();
        $this->compose($user, $calendar, $template)->assertForbidden();
        $this->actingAs($user)->postJson('/settings/compose-working-times', [
            'calendar_id' => $calendar->id, 'template_id' => $template->id, 'from_date' => '2026-09-07', 'to_date' => '2026-09-07',
        ])->assertForbidden();
    }

    public function test_pengguna_tanpa_akses_data_referensi_tidak_dapat_membaca(): void
    {
        [$user, $tenant, $org] = $this->tenantUser(false);
        $calendar = $this->calendar($tenant, $org);

        $this->actingAs($user)->get('/settings/working-time-calendars')->assertForbidden();
        $this->actingAs($user)->get("/settings/working-time-calendars/{$calendar->id}/times")->assertForbidden();
        $this->actingAs($user)->get('/settings/working-time-calendar-times')->assertForbidden();
        $this->actingAs($user)->get('/settings/compose-working-times')->assertForbidden();
    }

    public function test_menyusun_dan_menyalin_setahun_memakai_jumlah_query_tetap(): void
    {
        [$user, $tenant, $org] = $this->tenantUser();
        $calendar = $this->calendar($tenant, $org);
        $template = $this->template($tenant, $org);

        // Sebelumnya satu tahun memakan 1.367 query untuk menyusun dan 638 untuk menyalin: satu
        // perintah per hari dan per baris. Anggaran ini menahan bentuk massalnya.
        $queries = $this->countQueries(fn () => $this->compose($user, $calendar, $template, '2026-01-01', '2026-12-31')
            ->assertOk()->assertJsonPath('days_processed', 365));
        $this->assertLessThan(40, $queries);

        $queries = $this->countQueries(fn () => $this->actingAs($user)
            ->postJson("/settings/working-time-calendars/{$calendar->id}/copy", ['code' => 'SALIN-SETAHUN', 'name' => 'Salinan'])
            ->assertCreated());
        $this->assertLessThan(40, $queries);
        $this->assertSame(365, WorkingTimeCalendar::query()->where('code', 'SALIN-SETAHUN')->firstOrFail()->days()->count());
    }

    /** @return array{0: User, 1: Tenant, 2: Organization, 3: TenantMembership} */
    private function tenantUser(bool $owner = true, string $legalEntityName = 'Entitas A'): array
    {
        $slug = 'tenant-'.strtolower(Str::random(6));
        $client = Client::create(['legal_name' => 'Klien '.$slug, 'slug' => $slug, 'status' => 'active']);
        $tenant = Tenant::create(['client_id' => $client->id, 'name' => 'Tenant '.$slug, 'slug' => $slug, 'status' => 'active']);
        $user = User::factory()->create();
        $membership = TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'status' => 'active']);

        if ($owner) {
            $this->makeOwner($membership);
        }

        return [$user, $tenant, $this->legalEntity($tenant, $legalEntityName), $membership];
    }

    private function legalEntity(Tenant $tenant, string $name): Organization
    {
        $org = Organization::create(['tenant_id' => $tenant->id, 'classification' => 'legal_entity', 'name' => $name, 'status' => 'active']);
        $org->legalEntity()->create(['tenant_id' => $tenant->id, 'company_code' => strtoupper(Str::random(4)), 'country_code' => 'ID']);

        return $org;
    }

    private function calendar(Tenant $tenant, Organization $org, string $code = 'CAL'): WorkingTimeCalendar
    {
        return WorkingTimeCalendar::create([
            'tenant_id' => $tenant->id, 'legal_entity_id' => $org->id, 'code' => $code, 'name' => $code, 'standard_work_hours' => 8.0,
        ]);
    }

    /** Pola Senin–Jumat 08:00–16:00. */
    private function template(Tenant $tenant, Organization $org, bool $active = true): WorkingTimeTemplate
    {
        $template = WorkingTimeTemplate::create([
            'tenant_id' => $tenant->id, 'legal_entity_id' => $org->id, 'code' => 'POLA-'.strtoupper(Str::random(4)), 'name' => 'Pola', 'is_active' => $active,
        ]);

        for ($day = 0; $day <= 4; $day++) {
            $template->lines()->create([
                'tenant_id' => $tenant->id, 'day_of_week' => $day, 'from_time' => '08:00:00', 'to_time' => '16:00:00', 'efficiency' => 100, 'hours' => 8.0,
            ]);
        }

        return $template;
    }

    /** @return TestResponse<Response> */
    private function compose(User $user, WorkingTimeCalendar $calendar, WorkingTimeTemplate $template, string $from = '2026-09-07', string $to = '2026-09-13'): TestResponse
    {
        return $this->actingAs($user)->postJson("/settings/working-time-calendars/{$calendar->id}/compose", [
            'template_id' => $template->id, 'from_date' => $from, 'to_date' => $to,
        ]);
    }

    /** @return Builder<WorkingTimeCalendarLine> */
    private function linesOf(WorkingTimeCalendar $calendar): Builder
    {
        return WorkingTimeCalendarLine::query()
            ->whereIn('working_time_calendar_day_id', WorkingTimeCalendarDay::query()->select('id')->where('working_time_calendar_id', $calendar->id));
    }

    private function countQueries(callable $action): int
    {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });
        $action();

        return $count;
    }
}
