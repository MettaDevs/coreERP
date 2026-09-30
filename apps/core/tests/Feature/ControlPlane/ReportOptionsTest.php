<?php

declare(strict_types=1);

namespace Tests\Feature\ControlPlane;

use App\Actions\Onboarding\RegisterBusiness;
use App\Models\ReportPreset;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\Modules\Contracts\TenantDisiapkan;
use App\Support\Reporting\LayoutRef;
use App\Support\Reporting\RelativeDates;
use Carbon\CarbonImmutable;
use Database\Seeders\NumberSequenceProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\GrantsCoreRoles;
use Tests\TestCase;

/**
 * Opsi terakhir dan preset laporan per pengguna (K-24, K-25), padanan "Last used options and filters" dan
 * setelan laporan bernama di Business Central.
 *
 * Laporannya daftar work order module aset yang sungguhan: parameternya dibaca dari katalog yang didaftarkan
 * `app:register-manifest`, dan hak menjalankannya lewat rantai role, duty, privilege, dan permission yang
 * sama dengan layar. Tidak ada yang dipalsukan di antara Core dan module.
 */
class ReportOptionsTest extends TestCase
{
    use GrantsCoreRoles, RefreshDatabase;

    private const REPORT = 'management-aset.daftar-work-order';

    private User $owner;

    private TenantMembership $membership;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'sync', 'reporting.disk' => 'reporting-test']);
        Storage::fake('reporting-test');
        $this->seed(NumberSequenceProfileSeeder::class);
        $this->artisan('app:register-manifest', ['module' => 'management-aset'])->assertSuccessful();
        Event::fake([TenantDisiapkan::class]);

        $this->owner = $this->business('owner@opsi.test', 'Tenant opsi');
        $this->membership = $this->owner->activeMembership();
    }

    public function test_last_used_filters_and_options_prefill_the_next_run_for_that_user_only(): void
    {
        $base = '/api/v1/reports/'.self::REPORT;
        $this->actingAs($this->owner)->getJson($base.'/options')->assertOk()->assertJsonPath('data.last_used', null);

        // Parameter yang tidak dikenal laporan dan nilai kosong tidak disimpan.
        $this->actingAs($this->owner)
            ->putJson($base.'/options/last-used', ['parameters' => ['status' => 'draft', 'dari' => '2026-09-01', 'sampai' => '', 'bukan_parameter' => 'x']])
            ->assertNoContent();
        $this->actingAs($this->owner)->getJson($base.'/options')->assertOk()
            ->assertJsonPath('data.last_used.parameters', ['status' => 'draft', 'dari' => '2026-09-01']);

        // Menjalankan ekspor mencatat filter, format, dan layout yang dipakai.
        $this->actingAs($this->owner)
            ->postJson($base.'/exports', ['format' => 'xlsx', 'parameters' => ['status' => 'dijadwalkan']])
            ->assertStatus(202);
        $this->actingAs($this->owner)->getJson($base.'/options')->assertOk()
            ->assertJsonPath('data.last_used.parameters', ['status' => 'dijadwalkan'])
            ->assertJsonPath('data.last_used.format', 'xlsx')
            ->assertJsonPath('data.last_used.layout_ref', LayoutRef::BUILTIN_PREFIX.'standar');
        $this->assertSame(1, DB::table('report_last_used_options')->where('user_id', $this->owner->id)->count());

        // Pengguna lain di tenant yang sama, dan pemilik tenant lain, mulai dari kosong.
        $colleague = $this->member(['management-aset.pemeliharaan-aset.manage']);
        $this->actingAs($colleague)->getJson($base.'/options')->assertOk()->assertJsonPath('data.last_used', null);
        $other = $this->business('owner@lain.test', 'Tenant lain');
        $this->actingAs($other)->getJson($base.'/options')->assertOk()->assertJsonPath('data.last_used', null);
    }

    public function test_private_presets_stay_with_their_owner_and_shared_presets_reach_everyone_allowed_to_run_the_report(): void
    {
        $base = '/api/v1/reports/'.self::REPORT;
        $private = $this->actingAs($this->owner)
            ->postJson($base.'/presets', ['name' => 'Draf saya', 'parameters' => ['status' => 'draft']])
            ->assertCreated()
            ->assertJsonPath('data.shared', false)
            ->assertJsonPath('data.mine', true)
            ->json('data.id');
        // Preset bersama belum dapat dibuat lewat layar (menunggu keputusan permission, K-25); isinya ditulis
        // langsung supaya aturan siapa yang melihatnya tetap teruji.
        $shared = ReportPreset::query()->create([
            'tenant_id' => $this->membership->tenant_id, 'user_id' => $this->owner->id, 'report_code' => self::REPORT,
            'name' => 'Selesai bulan ini', 'shared' => true, 'parameters' => ['status' => 'selesai', 'dari' => '@this_month.start'],
        ]);

        $colleague = $this->member(['management-aset.pemeliharaan-aset.manage']);
        $seen = $this->actingAs($colleague)->getJson($base.'/options')->assertOk()->json('data.presets');
        $this->assertSame([$shared->id], array_column($seen, 'id'));
        $this->assertFalse($seen[0]['mine']);
        $this->assertSame('Owner', $seen[0]['owner_name'], 'Preset bersama menyebut nama pembuatnya, bukan id.');

        // Rekan tidak dapat mengubah atau mengarsipkan preset orang lain, pribadi maupun bersama.
        foreach ([$private, $shared->id] as $id) {
            $this->actingAs($colleague)->patchJson($base.'/presets/'.$id, ['name' => 'Ambil alih', 'version' => 1])->assertNotFound();
            $this->actingAs($colleague)->deleteJson($base.'/presets/'.$id, [], ['If-Match' => 'W/"1"'])->assertNotFound();
        }

        // Pemilik melihat keduanya: bersama lebih dulu, lalu miliknya.
        $mine = $this->actingAs($this->owner)->getJson($base.'/options')->assertOk()->json('data.presets');
        $this->assertSame([$shared->id, $private], array_column($mine, 'id'));

        // Tanpa hak menjalankan laporan, opsinya tidak ada sama sekali.
        $outsider = $this->member([]);
        $this->actingAs($outsider)->getJson($base.'/options')->assertNotFound();
        $this->actingAs($outsider)->postJson($base.'/presets', ['name' => 'X', 'parameters' => []])->assertNotFound();

        // Tenant lain tidak melihat preset bersama tenant ini, walau kode laporannya sama.
        $other = $this->business('owner@lain.test', 'Tenant lain');
        $this->actingAs($other)->getJson($base.'/options')->assertOk()->assertJsonPath('data.presets', []);
    }

    public function test_preset_changes_need_the_current_row_version_and_archiving_keeps_the_row(): void
    {
        $base = '/api/v1/reports/'.self::REPORT;
        $id = $this->actingAs($this->owner)
            ->postJson($base.'/presets', ['name' => 'Draf', 'parameters' => ['status' => 'draft']])
            ->assertCreated()->assertJsonPath('data.version', 1)->json('data.id');

        $this->actingAs($this->owner)->patchJson($base.'/presets/'.$id, ['name' => 'Draf baru'])->assertStatus(428);
        // Klaim versi dan penyimpanan masing-masing menaikkan versi, seperti setiap penyimpanan lain di Core.
        $this->actingAs($this->owner)->patchJson($base.'/presets/'.$id, ['name' => 'Draf baru', 'version' => 1])
            ->assertOk()->assertJsonPath('data.name', 'Draf baru')->assertJsonPath('data.version', 3);
        // Tab lain yang masih memegang versi 1 ditolak, bukan menimpa diam-diam.
        $this->actingAs($this->owner)->patchJson($base.'/presets/'.$id, ['parameters' => ['status' => 'selesai'], 'version' => 1])->assertStatus(409);

        // Nama sama dengan preset lain milik orang yang sama ditolak tanpa membedakan huruf besar.
        $this->actingAs($this->owner)->postJson($base.'/presets', ['name' => 'DRAF BARU', 'parameters' => []])
            ->assertStatus(422)->assertJsonValidationErrors('name');

        $this->actingAs($this->owner)->deleteJson($base.'/presets/'.$id, [], ['If-Match' => 'W/"1"'])->assertStatus(409);
        $this->actingAs($this->owner)->deleteJson($base.'/presets/'.$id, [], ['If-Match' => 'W/"3"'])->assertNoContent();
        $this->actingAs($this->owner)->getJson($base.'/options')->assertOk()->assertJsonPath('data.presets', []);
        $this->assertNotNull(DB::table('report_presets')->where('id', $id)->value('deleted_at'), 'Mengarsipkan mengisi deleted_at; barisnya tetap ada.');

        // Nama preset yang sudah diarsipkan boleh dipakai lagi.
        $this->actingAs($this->owner)->postJson($base.'/presets', ['name' => 'Draf baru', 'parameters' => []])->assertCreated();
    }

    public function test_relative_dates_resolve_in_the_users_time_zone_when_the_preset_is_used(): void
    {
        // 1 Oktober 2026 pukul 00.30 WIB masih 30 September pukul 17.30 di UTC.
        // Tenantnya dibuat sesudah jam dibekukan, supaya hak pakai module berlaku pada jam itu.
        Carbon::setTestNow(Carbon::parse('2026-09-30 17:30:00', 'UTC'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-30 17:30:00', 'UTC'));
        $this->owner = $this->business('zona@opsi.test', 'Tenant zona');
        $this->owner->forceFill(['timezone' => 'Asia/Jakarta'])->save();
        $base = '/api/v1/reports/'.self::REPORT;

        $preset = $this->actingAs($this->owner)->postJson($base.'/presets', [
            'name' => 'Bulan ini',
            'parameters' => ['dari' => '@this_month.start', 'sampai' => '@this_month.end'],
        ])->assertCreated()->json('data');
        $this->assertSame(['dari' => '@this_month.start', 'sampai' => '@this_month.end'], $preset['parameters'], 'Yang tersimpan tokennya, bukan tanggal hari ini.');
        $this->assertSame(['dari' => '2026-10-01', 'sampai' => '2026-10-31'], $preset['resolved_parameters']);

        // Pengguna berzona UTC pada saat yang sama masih di bulan September.
        $this->owner->forceFill(['timezone' => 'UTC'])->save();
        $this->actingAs($this->owner)->getJson($base.'/options')->assertOk()
            ->assertJsonPath('data.presets.0.resolved_parameters', ['dari' => '2026-09-01', 'sampai' => '2026-09-30']);

        // Permintaan ekspor yang membawa token diterjemahkan saat diminta, lalu yang tersimpan tanggalnya.
        $this->owner->forceFill(['timezone' => 'Asia/Jakarta'])->save();
        $export = $this->actingAs($this->owner)
            ->postJson($base.'/exports', ['format' => 'xlsx', 'parameters' => ['dari' => '@last_month.start', 'sampai' => '@last_month.end']])
            ->assertStatus(202)->json('data');
        $this->assertSame(['dari' => '2026-09-01', 'sampai' => '2026-09-30'], $export['parameters']);

        $this->actingAs($this->owner)->postJson($base.'/presets', ['name' => 'Aneh', 'parameters' => ['dari' => '@kemarin_lusa']])
            ->assertStatus(422)->assertJsonValidationErrors('parameters.dari');

        $now = CarbonImmutable::parse('2026-01-01 00:30:00', 'Asia/Jakarta');
        $this->assertSame(
            ['a' => '2025-12-01', 'b' => '2025-12-31', 'c' => '2026-01-01', 'd' => '2025-01-01', 'e' => '2025-12-31', 'f' => '2025-12', 'g' => '2026-01', 'h' => 'tetap'],
            RelativeDates::resolve([
                'a' => '@last_month.start', 'b' => '@last_month.end', 'c' => '@this_year.start', 'd' => '@last_year.start',
                'e' => '@last_year.end', 'f' => '@last_month', 'g' => '@this_month', 'h' => 'tetap',
            ], $now),
        );
    }

    private function business(string $email, string $name): User
    {
        return app(RegisterBusiness::class)->handle([
            'name' => 'Owner', 'business_name' => $name,
            'app_ids' => ['management-aset'], 'email' => $email, 'password' => 'password',
        ]);
    }

    /** @param list<string> $duties */
    private function member(array $duties): User
    {
        $user = User::factory()->create();
        $membership = TenantMembership::query()->create([
            'tenant_id' => $this->membership->tenant_id, 'user_id' => $user->id, 'status' => 'active',
        ]);
        if ($duties !== []) {
            $this->grantDuties($membership, $duties);
        }

        return $user;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }
}
