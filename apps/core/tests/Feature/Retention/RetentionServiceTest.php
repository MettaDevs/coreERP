<?php

declare(strict_types=1);

namespace Tests\Feature\Retention;

use App\Actions\Onboarding\RegisterBusiness;
use App\Models\NumberSequenceReference;
use App\Models\TenantMembership;
use App\Models\TenantNumberSequence;
use App\Models\User;
use App\Support\ChangeLog\AlwaysLoggedTables;
use App\Support\Reporting\ExportQueue;
use App\Support\Retention\RetentionPolicies;
use App\Support\Retention\RetentionService;
use Database\Seeders\AppCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\GrantsCoreRoles;
use Tests\TestCase;

/**
 * Layanan retensi data log (area 4, B-4): tanpa setelan tenant hasilnya sama dengan perilaku sebelum ada
 * layanan ini, masa simpan di bawah minimum ditolak, tabel di luar daftar tidak pernah tersentuh, dan
 * setelan satu tenant tidak memengaruhi tenant lain.
 */
final class RetentionServiceTest extends TestCase
{
    use GrantsCoreRoles;
    use RefreshDatabase;

    private User $owner;

    private TenantMembership $membership;

    private User $other;

    private TenantMembership $otherMembership;

    private RetentionService $retention;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AppCatalogSeeder::class);
        config(['coreerp.base_domain' => null]);

        $this->owner = $this->register('owner@retensi.test', 'Usaha Satu');
        $this->membership = $this->owner->activeMembership();
        $this->other = $this->register('owner@lain.test', 'Usaha Dua');
        $this->otherMembership = $this->other->activeMembership();
        $this->retention = app(RetentionService::class);
    }

    public function test_tanpa_setelan_tenant_hasilnya_sama_dengan_perilaku_sebelum_ada_layanan(): void
    {
        Storage::fake('local');
        $sequence = $this->sequence($this->membership->tenant_id, 'satu');

        $oldAudit = $this->audit($sequence, 401);
        $keptAudit = $this->audit($sequence, 399);
        $oldConfirmed = $this->pool($sequence, 1, 'confirmed', 31);
        $keptConfirmed = $this->pool($sequence, 2, 'confirmed', 29);
        $oldAvailable = $this->pool($sequence, 3, 'available', 300);
        $oldExport = $this->export($this->membership, 8, 'lama.pdf');
        $keptExport = $this->export($this->membership, 6, 'baru.pdf');

        $deleted = $this->retention->apply(tenantId: $this->membership->tenant_id);

        $this->assertSame(3, $deleted);
        $this->assertNull(DB::table('number_sequence_audit_events')->find($oldAudit));
        $this->assertNotNull(DB::table('number_sequence_audit_events')->find($keptAudit));
        $this->assertFalse($this->poolExists($sequence, $oldConfirmed));
        $this->assertTrue($this->poolExists($sequence, $keptConfirmed));
        $this->assertTrue($this->poolExists($sequence, $oldAvailable), 'Nomor yang belum terpakai bukan bagian retensi.');
        $this->assertNull(DB::table('report_exports')->find($oldExport));
        $this->assertNotNull(DB::table('report_exports')->find($keptExport));
        Storage::disk('local')->assertMissing('exports/lama.pdf');
        Storage::disk('local')->assertExists('exports/baru.pdf');
    }

    public function test_bawaan_dibaca_dari_config_saat_dijalankan(): void
    {
        $sequence = $this->sequence($this->membership->tenant_id, 'satu');
        $audit = $this->audit($sequence, 380);

        config(['coreerp.audit_retention_days' => 370]);
        $this->retention->apply('number_sequence_audit', $this->membership->tenant_id);

        $this->assertNull(DB::table('number_sequence_audit_events')->find($audit));
    }

    public function test_config_di_bawah_minimum_tidak_menurunkan_masa_simpan(): void
    {
        $sequence = $this->sequence($this->membership->tenant_id, 'satu');
        $audit = $this->audit($sequence, 200);

        config(['coreerp.audit_retention_days' => 30]);
        $this->retention->apply('number_sequence_audit', $this->membership->tenant_id);

        $this->assertNotNull(DB::table('number_sequence_audit_events')->find($audit), 'Minimum 365 hari tetap berlaku.');
        $this->assertSame(365, $this->retention->daysFor('number_sequence_audit', $this->membership->tenant_id));
    }

    public function test_tabel_di_luar_daftar_dan_log_yang_kebijakannya_mati_tidak_tersentuh(): void
    {
        DB::table('tenant_memberships')->update(['created_at' => now()->subYears(5), 'updated_at' => now()->subYears(5)]);
        $memberships = DB::table('tenant_memberships')->count();
        $this->assertGreaterThan(0, $memberships);
        $this->changeLog($this->membership->tenant_id, 'legal_entities', 5000);
        $this->changeLog($this->membership->tenant_id, 'roles', 5000);

        $this->retention->apply();

        $this->assertSame($memberships, DB::table('tenant_memberships')->count());
        $this->assertSame(2, DB::table('change_log_entries')->where('tenant_id', $this->membership->tenant_id)->whereIn('record_id', ['uji'])->count());
    }

    public function test_masa_simpan_di_bawah_minimum_ditolak(): void
    {
        $this->actingAs($this->owner)->put('/settings/retention/number_sequence_audit', ['retention_days' => 364])
            ->assertSessionHasErrors(['retention_days' => 'Masa simpan Catatan audit penomoran tidak boleh kurang dari 365 hari.']);
        $this->actingAs($this->owner)->put('/settings/retention/report_exports', ['retention_days' => 0])
            ->assertSessionHasErrors('retention_days');
        $this->actingAs($this->owner)->put('/settings/retention/change_log_access', ['enabled' => true, 'retention_days' => 300])
            ->assertSessionHasErrors('retention_days');
        $this->actingAs($this->owner)->put('/settings/retention/change_log_other', ['enabled' => true, 'retention_days' => 27])
            ->assertSessionHasErrors('retention_days');
        $this->actingAs($this->owner)->put('/settings/retention/retention_policy_log', ['retention_days' => 27])
            ->assertSessionHasErrors('retention_days');
        // Cadangan nomor berurutan tidak diatur tenant: hanya config operator.
        $this->actingAs($this->owner)->put('/settings/retention/number_sequence_confirmed_pool', ['retention_days' => 400])
            ->assertNotFound();

        $this->assertSame(0, DB::table('retention_policy_setups')->count());
    }

    public function test_kebijakan_tidak_dikenal_ditolak(): void
    {
        $this->actingAs($this->owner)->put('/settings/retention/parties', ['retention_days' => 400])->assertNotFound();
    }

    public function test_setelan_satu_tenant_tidak_memengaruhi_tenant_lain(): void
    {
        $one = $this->sequence($this->membership->tenant_id, 'satu');
        $two = $this->sequence($this->otherMembership->tenant_id, 'dua');
        $oneAudit = $this->audit($one, 370);
        $twoAudit = $this->audit($two, 370);

        $this->actingAs($this->owner)->put('/settings/retention/number_sequence_audit', ['retention_days' => 365])->assertRedirect();
        $this->retention->apply();

        $this->assertNull(DB::table('number_sequence_audit_events')->find($oneAudit));
        $this->assertNotNull(DB::table('number_sequence_audit_events')->find($twoAudit), 'Tenant lain tetap memakai bawaan 400 hari.');
    }

    public function test_riwayat_perubahan_punya_dua_kebijakan_dan_mati_bawaannya(): void
    {
        $tenant = $this->membership->tenant_id;
        $this->changeLog($tenant, 'legal_entities', 40, 'lama-biasa');
        $this->changeLog($tenant, 'legal_entities', 10, 'baru-biasa');
        $this->changeLog($tenant, 'roles', 40, 'lama-akses');
        $this->changeLog($tenant, 'roles', 400, 'sangat-lama-akses');

        $this->retention->apply(tenantId: $tenant);
        $this->assertSame(4, $this->changeLogCount($tenant), 'Bawaannya mati: tidak ada yang dihapus.');

        $this->actingAs($this->owner)->put('/settings/retention/change_log_other', ['enabled' => true, 'retention_days' => 30])->assertRedirect();
        $this->retention->apply(tenantId: $tenant);
        $this->assertSame(['baru-biasa', 'lama-akses', 'sangat-lama-akses'], $this->changeLogRecords($tenant), 'Tabel akses tidak ikut kebijakan biasa.');

        $this->actingAs($this->owner)->put('/settings/retention/change_log_access', ['enabled' => true, 'retention_days' => 365])->assertRedirect();
        $this->retention->apply(tenantId: $tenant);
        $this->assertSame(['baru-biasa', 'lama-akses'], $this->changeLogRecords($tenant));
    }

    public function test_hasil_penerapan_tercatat_per_tenant_dan_hanya_bila_ada_yang_dihapus(): void
    {
        $one = $this->sequence($this->membership->tenant_id, 'satu');
        $two = $this->sequence($this->otherMembership->tenant_id, 'dua');
        $this->audit($one, 401);
        $this->audit($one, 402);
        $this->audit($two, 10);

        $this->retention->apply();

        $entries = DB::table('retention_policy_log_entries')->get();
        $this->assertCount(1, $entries);
        $entry = $entries->first();
        $this->assertSame($this->membership->tenant_id, $entry->tenant_id);
        $this->assertSame('number_sequence_audit', $entry->policy_code);
        $this->assertSame(2, (int) $entry->deleted_count);
        $this->assertSame('success', $entry->status);
        $this->assertEqualsWithDelta(now()->subDays(400)->getTimestamp(), (new \DateTimeImmutable($entry->cutoff_at))->getTimestamp(), 5);
    }

    public function test_kegagalan_menghapus_dicatat_tanpa_menghentikan_kebijakan_lain(): void
    {
        $sequence = $this->sequence($this->membership->tenant_id, 'satu');
        $audit = $this->audit($sequence, 401);
        $export = $this->export($this->membership, 8, 'gagal.pdf');
        config(['reporting.disk' => 'disk-yang-tidak-ada']);

        $this->retention->apply(tenantId: $this->membership->tenant_id);

        $this->assertNull(DB::table('number_sequence_audit_events')->find($audit));
        $this->assertNotNull(DB::table('report_exports')->find($export), 'Baris tetap ada bila disk tidak bisa dibuka.');
        $failed = DB::table('retention_policy_log_entries')->where('status', 'failed')->sole();
        $this->assertSame('report_exports', $failed->policy_code);
        $this->assertNotEmpty($failed->message);
    }

    public function test_catatan_penerapan_sendiri_diretensi(): void
    {
        $tenant = $this->membership->tenant_id;
        foreach ([400, 100] as $days) {
            DB::table('retention_policy_log_entries')->insert([
                'id' => strtolower((string) Str::ulid()), 'tenant_id' => $tenant, 'policy_code' => 'report_exports',
                'deleted_count' => 1, 'cutoff_at' => now(), 'status' => 'success',
                'created_at' => now()->subDays($days), 'updated_at' => now()->subDays($days),
            ]);
        }

        $this->retention->apply('retention_policy_log', $tenant);

        $this->assertSame(1, DB::table('retention_policy_log_entries')->where('tenant_id', $tenant)->where('policy_code', 'report_exports')->count());
        $this->assertSame(0, DB::table('retention_policy_log_entries')->where('created_at', '<', now()->subDays(365))->count());
    }

    public function test_perintah_terjadwal_dan_perintah_lama_memakai_layanan(): void
    {
        Storage::fake('local');
        $sequence = $this->sequence($this->membership->tenant_id, 'satu');
        $audit = $this->audit($sequence, 401);
        $pool = $this->pool($sequence, 1, 'confirmed', 40);
        $export = $this->export($this->membership, 8, 'lama.pdf');

        Artisan::call('reporting:purge-exports');
        $this->assertNull(DB::table('report_exports')->find($export));
        $this->assertNotNull(DB::table('number_sequence_audit_events')->find($audit), 'Hanya hasil ekspor yang dibersihkan perintah ini.');

        Artisan::call('number-sequences:recover');
        $this->assertNotNull(DB::table('number_sequence_audit_events')->find($audit), 'Pemulihan urutan nomor tidak lagi menghapus berdasarkan umur.');
        $this->assertTrue($this->poolExists($sequence, $pool));

        $this->assertSame(0, Artisan::call('retention:apply'));
        $this->assertNull(DB::table('number_sequence_audit_events')->find($audit));
        $this->assertFalse($this->poolExists($sequence, $pool));
        $this->assertSame(1, Artisan::call('retention:apply', ['policy' => 'tidak-ada']));
    }

    public function test_daftar_ekspor_hanya_membersihkan_milik_tenantnya_sendiri(): void
    {
        Storage::fake('local');
        $mine = $this->export($this->membership, 8, 'saya.pdf');
        $theirs = $this->export($this->otherMembership, 8, 'mereka.pdf');

        app(ExportQueue::class)->mine($this->membership->tenant_id, $this->owner->id);

        $this->assertNull(DB::table('report_exports')->find($mine));
        $this->assertNotNull(DB::table('report_exports')->find($theirs));
    }

    public function test_masa_simpan_ekspor_mengikuti_setelan_tenant(): void
    {
        $tenant = $this->membership->tenant_id;
        $this->assertSame(7, $this->retention->daysFor('report_exports', $tenant));

        $this->actingAs($this->owner)->put('/settings/retention/report_exports', ['retention_days' => 3])->assertRedirect();

        $this->assertSame(3, $this->retention->daysFor('report_exports', $tenant));
        $this->assertSame(7, $this->retention->daysFor('report_exports', $this->otherMembership->tenant_id));
    }

    public function test_layar_tertutup_tanpa_izin_baca_dan_tanpa_izin_ubah(): void
    {
        $member = User::factory()->create();
        $membership = TenantMembership::query()->create([
            'tenant_id' => $this->membership->tenant_id, 'user_id' => $member->id, 'status' => 'active',
        ]);
        $this->actingAs($member)->get('/settings/retention')->assertForbidden();

        $this->grantDuties($membership, ['core.retention.inquire']);
        $this->actingAs($member)->get('/settings/retention')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('settings/retention')->where('canManage', false));
        $this->actingAs($member)->put('/settings/retention/report_exports', ['retention_days' => 3])->assertForbidden();
    }

    public function test_owner_melihat_semua_kebijakan_dan_hasil_terakhir(): void
    {
        $sequence = $this->sequence($this->membership->tenant_id, 'satu');
        $this->audit($sequence, 401);
        $this->retention->apply(tenantId: $this->membership->tenant_id);

        $this->actingAs($this->owner)->get('/settings/retention')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('settings/retention')
                ->where('canManage', true)
                ->has('policies', count(RetentionPolicies::all()) - 1)
                ->where('policies', fn ($policies): bool => ! collect($policies)->contains('code', 'number_sequence_confirmed_pool'))
                ->where('policies.0.code', 'number_sequence_audit')
                ->where('policies.0.days', 400)
                ->where('policies.0.minimum_days', 365)
                ->where('policies.2.enabled', false)
                ->has('entries', 1)
                ->where('entries.0.policy', 'Catatan audit penomoran')
                ->where('entries.0.deleted_count', 1));
    }

    public function test_daftar_tabel_selalu_dicatat_sama_dengan_fungsi_sql(): void
    {
        $definition = (string) DB::selectOne("select pg_get_functiondef('coreerp_log_change'::regproc) as definition")->definition;
        $this->assertSame(1, preg_match("/IN \\((\\s*'change_log_setup_tables'.*?)\\) THEN/s", $definition, $list));
        preg_match_all("/'([a-z_]+)'/", $list[1], $names);

        $this->assertEqualsCanonicalizing($names[1], AlwaysLoggedTables::NAMES);
    }

    private function register(string $email, string $business): User
    {
        return app(RegisterBusiness::class)->handle([
            'name' => 'Owner '.$business, 'business_name' => $business,
            'app_ids' => ['app-uji'], 'email' => $email, 'password' => 'password',
        ]);
    }

    private function sequence(string $tenantId, string $suffix): string
    {
        $reference = NumberSequenceReference::query()->firstOrCreate(['code' => 'app-uji.retensi'], [
            'app_id' => 'app-uji', 'name' => 'Nomor retensi', 'allowed_scopes' => ['tenant'],
        ]);

        return TenantNumberSequence::query()->create([
            'tenant_id' => $tenantId, 'reference_id' => $reference->id, 'profile_code' => 'non-continuous-default',
            'scope_type' => 'tenant', 'status' => 'active', 'is_continuous' => false, 'allow_manual' => false,
            'reset_period' => 'never', 'preallocation_enabled' => true, 'preallocation_quantity' => 20,
            'minimum_number' => 1, 'segments' => [['type' => 'number', 'length' => 6, 'note' => $suffix]],
        ])->id;
    }

    private function audit(string $sequenceId, int $daysAgo): string
    {
        $id = strtolower((string) Str::ulid());
        DB::table('number_sequence_audit_events')->insert([
            'id' => $id, 'sequence_id' => $sequenceId, 'event_type' => 'issued',
            'occurred_at' => now()->subDays($daysAgo), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function pool(string $sequenceId, int $number, string $status, int $daysAgo): int
    {
        DB::table('number_sequence_continuous_pool')->insert([
            'sequence_id' => $sequenceId, 'scope_key' => 'tenant', 'period_key' => 'all', 'numeric_value' => $number,
            'status' => $status, 'created_at' => now()->subDays($daysAgo), 'updated_at' => now()->subDays($daysAgo),
        ]);

        return $number;
    }

    private function poolExists(string $sequenceId, int $number): bool
    {
        return DB::table('number_sequence_continuous_pool')->where(['sequence_id' => $sequenceId, 'numeric_value' => $number])->exists();
    }

    private function export(TenantMembership $membership, int $daysAgo, string $file): string
    {
        $id = strtolower((string) Str::ulid());
        Storage::disk('local')->put("exports/{$file}", 'isi');
        DB::table('report_exports')->insert([
            'id' => $id, 'tenant_id' => $membership->tenant_id, 'membership_id' => $membership->id, 'user_id' => $membership->user_id,
            'app_id' => 'app-uji', 'report_code' => 'uji', 'report_name' => 'Uji', 'layout_ref' => 'a', 'layout_name' => 'A',
            'format' => 'pdf', 'parameters' => '{}', 'status' => 'done', 'file_path' => "exports/{$file}",
            'created_at' => now()->subDays($daysAgo), 'updated_at' => now()->subDays($daysAgo),
        ]);

        return $id;
    }

    private function changeLog(string $tenantId, string $table, int $daysAgo, string $record = 'uji'): void
    {
        DB::table('change_log_entries')->insert([
            'tenant_id' => $tenantId, 'changed_at' => now()->subDays($daysAgo), 'table_name' => $table, 'record_id' => $record,
            'field_name' => 'name', 'change_type' => 'modification', 'old_value' => 'a', 'new_value' => 'b',
        ]);
    }

    private function changeLogCount(string $tenantId): int
    {
        return DB::table('change_log_entries')->where('tenant_id', $tenantId)->whereIn('record_id', ['lama-biasa', 'baru-biasa', 'lama-akses', 'sangat-lama-akses'])->count();
    }

    /** @return list<string> */
    private function changeLogRecords(string $tenantId): array
    {
        return DB::table('change_log_entries')->where('tenant_id', $tenantId)
            ->whereIn('record_id', ['lama-biasa', 'baru-biasa', 'lama-akses', 'sangat-lama-akses'])
            ->orderBy('record_id')->pluck('record_id')->all();
    }
}
