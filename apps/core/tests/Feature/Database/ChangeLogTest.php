<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Actions\Onboarding\RegisterBusiness;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\Database\ChangeLogSwitch;
use App\Support\Modules\Contracts\AuditColumns;
use App\Support\Modules\Contracts\ChangeLogDefaults;
use App\Support\Modules\Contracts\ChangeLogValueResolver;
use App\Support\Modules\Contracts\ChangeLogValueResolvers;
use Database\Seeders\AppCatalogSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\GrantsCoreRoles;
use Tests\TestCase;

/**
 * Log perubahan per field (area 2, gap 6): trigger `coreerp_log_change` mencatat sesuai setelan bawaan atau
 * setelan tenant, tabel keamanan selalu dicatat, entri tidak dapat diubah, dan riwayat dibaca bernama pelaku
 * dan bernilai tampilan. Tabelnya dibuat di test supaya yang diuji mesinnya, bukan aturan tabel tertentu.
 */
final class ChangeLogTest extends TestCase
{
    use GrantsCoreRoles;
    use RefreshDatabase;

    private User $owner;

    private TenantMembership $membership;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AppCatalogSeeder::class);
        config(['coreerp.base_domain' => null]);

        $this->owner = $this->register('owner@log.test', 'Usaha Uji');
        $this->membership = $this->owner->activeMembership();

        Schema::create('contoh_log', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->string('nama');
            $table->string('catatan')->nullable();
            $table->string('lokasi_id')->nullable();
            AuditColumns::add($table);
            $table->timestamps();
        });
        AuditColumns::attach('contoh_log');
    }

    public function test_tabel_tanpa_setelan_tidak_dicatat(): void
    {
        $this->actingAs($this->owner);
        $id = $this->insert('awal');
        DB::table('contoh_log')->where('id', $id)->update(['nama' => 'ubah']);

        $this->assertSame(0, $this->logged());
    }

    public function test_semua_field_mencatat_nilai_lama_baru_dan_pelaku_termasuk_update_query_builder(): void
    {
        $this->tenantSetup(modification: 'all');
        $this->actingAs($this->owner);
        $id = $this->insert('awal', 'catatan awal');

        DB::table('contoh_log')->where('id', $id)->update(['nama' => 'ubah', 'updated_at' => now()]);

        $entry = DB::table('change_log_entries')->where('table_name', 'contoh_log')->sole();
        $this->assertSame(['contoh_log', $id, 'nama', 'modification', 'awal', 'ubah', (int) $this->owner->id], [
            $entry->table_name, $entry->record_id, $entry->field_name, $entry->change_type,
            $entry->old_value, $entry->new_value, (int) $entry->created_by_user_id,
        ]);
    }

    public function test_bawaan_berlaku_sampai_tenant_menyimpan_setelannya_sendiri(): void
    {
        ChangeLogDefaults::register('contoh_log', 'Contoh', ['catatan' => 'Catatan']);
        $id = $this->insert('awal', 'catatan awal');
        DB::table('contoh_log')->where('id', $id)->update(['nama' => 'ubah', 'catatan' => 'catatan baru']);

        // Bawaan: hanya `catatan`, saat dibuat dan saat diubah.
        $this->assertSame([['catatan', 'insertion'], ['catatan', 'modification']], $this->entries());

        // Tenant mematikan perubahan: setelannya menggantikan bawaan untuk tabel ini.
        $this->tenantSetup(insertion: 'some', modification: 'none');
        DB::table('contoh_log')->where('id', $id)->update(['catatan' => 'lagi']);
        $this->assertSame(2, $this->logged());

        // Tenant lain tetap memakai bawaan.
        $lain = $this->register('owner@lain.test', 'Usaha Lain')->activeMembership()->tenant_id;
        $idLain = $this->insert('milik tenant lain', 'catatan', tenant: $lain);
        $this->assertSame(1, DB::table('change_log_entries')->where('record_id', $idLain)->count());
    }

    public function test_sebagian_field_hanya_mencatat_field_yang_dicentang(): void
    {
        $this->tenantSetup(insertion: 'some', modification: 'some');
        DB::table('change_log_setup_fields')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $this->membership->tenant_id, 'table_name' => 'contoh_log',
            'field_name' => 'catatan', 'log_insertion' => false, 'log_modification' => true, 'log_deletion' => false,
        ]);
        $id = $this->insert('awal', 'catatan awal');

        DB::table('contoh_log')->where('id', $id)->update(['nama' => 'ubah', 'catatan' => 'catatan baru']);

        $this->assertSame([['catatan', 'modification']], $this->entries());
    }

    public function test_setelan_satu_tenant_tidak_mencatat_tenant_lain(): void
    {
        $this->tenantSetup(insertion: 'all');
        $this->insert('milik tenant lain', tenant: (string) Str::ulid());

        $this->assertSame(0, $this->logged());
    }

    public function test_tabel_keamanan_selalu_dicatat_tanpa_setelan(): void
    {
        $this->actingAs($this->owner);
        $role = DB::table('roles')->where('tenant_id', $this->membership->tenant_id)->value('id');

        DB::table('roles')->where('id', $role)->update(['name' => 'Peran diganti']);

        $entry = DB::table('change_log_entries')->where(['table_name' => 'roles', 'change_type' => 'modification'])->sole();
        $this->assertSame([$role, 'name', 'Peran diganti', (int) $this->owner->id], [
            $entry->record_id, $entry->field_name, $entry->new_value, (int) $entry->created_by_user_id,
        ]);
    }

    public function test_tabel_akses_tanpa_tenant_id_dicatat_pada_tenant_induknya(): void
    {
        $tenant = $this->membership->tenant_id;
        $role = DB::table('roles')->where('tenant_id', $tenant)->value('id');
        $privilege = DB::table('security_privileges')->whereNull('tenant_id')->value('code');
        $permission = DB::table('permissions')->value('code');
        $anggota = TenantMembership::query()->create([
            'tenant_id' => $tenant, 'user_id' => User::factory()->create()->id, 'status' => 'active',
        ]);
        foreach (['contoh.duty.tenant' => $tenant, 'contoh.duty.katalog' => null] as $code => $owner) {
            DB::table('security_duties')->insert([
                'code' => $code, 'app_id' => 'core', 'tenant_id' => $owner, 'name' => $code, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('security_privileges')->insert([
            'code' => 'contoh.privilege.tenant', 'app_id' => 'core', 'tenant_id' => $tenant, 'name' => 'Privilege tenant',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($this->owner);

        $assignment = (string) Str::ulid();
        DB::table('role_assignments')->insert([
            'id' => $assignment, 'membership_id' => $anggota->id, 'role_id' => $role, 'source' => 'manual',
            'status' => 'active', 'valid_from' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('role_assignments')->where('id', $assignment)->update(['status' => 'revoked']);
        DB::table('security_role_duties')->insert(['role_id' => $role, 'duty_code' => 'contoh.duty.tenant']);
        DB::table('security_role_duties')->where(['role_id' => $role, 'duty_code' => 'contoh.duty.tenant'])->delete();
        DB::table('security_duty_privileges')->insert([
            ['duty_code' => 'contoh.duty.tenant', 'privilege_code' => $privilege],
            ['duty_code' => 'contoh.duty.katalog', 'privilege_code' => $privilege],
        ]);
        DB::table('security_privilege_permissions')->insert(['privilege_code' => 'contoh.privilege.tenant', 'permission_code' => $permission]);

        $entries = DB::table('change_log_entries')
            ->whereIn('table_name', ['role_assignments', 'security_role_duties', 'security_duty_privileges', 'security_privilege_permissions'])
            ->where('created_by_user_id', $this->owner->id)->get();
        $this->assertSame([$tenant], $entries->pluck('tenant_id')->unique()->values()->all());

        $revoked = $entries->where('record_id', $assignment)->firstWhere('change_type', 'modification');
        $this->assertSame(['status', 'active', 'revoked'], [$revoked->field_name, $revoked->old_value, $revoked->new_value]);
        $this->assertSame($role, $entries->where('record_id', $assignment)->firstWhere('field_name', 'role_id')->new_value);
        $this->assertSame(
            ['insertion', 'deletion'],
            $entries->where('record_id', "{$role},contoh.duty.tenant")->pluck('change_type')->unique()->values()->all(),
        );
        $this->assertSame(
            ["contoh.duty.tenant,{$privilege}"],
            $entries->where('table_name', 'security_duty_privileges')->pluck('record_id')->unique()->values()->all(),
        );
        $this->assertSame(2, $entries->where('record_id', "contoh.privilege.tenant,{$permission}")->count());
    }

    public function test_baris_katalog_bertenant_kosong_tidak_dicatat_dan_tidak_menggagalkan_penulisan(): void
    {
        DB::table('security_duties')->insert([
            'code' => 'contoh.duty.baru', 'app_id' => 'core', 'name' => 'Duty contoh', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(0, $this->logged('security_duties'));
    }

    public function test_kunci_gabungan_tercatat_sebagai_kunci_record(): void
    {
        Schema::create('contoh_kunci', function (Blueprint $table): void {
            $table->ulid('tenant_id');
            $table->string('kode');
            $table->string('nama');
            AuditColumns::add($table);
            $table->primary(['tenant_id', 'kode']);
        });
        AuditColumns::attach('contoh_kunci');
        DB::table('change_log_setup_tables')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $this->membership->tenant_id, 'table_name' => 'contoh_kunci',
            'log_insertion' => 'all', 'log_modification' => 'none', 'log_deletion' => 'none',
        ]);

        DB::table('contoh_kunci')->insert(['tenant_id' => $this->membership->tenant_id, 'kode' => 'K-01', 'nama' => 'Satu']);

        $this->assertSame('K-01', DB::table('change_log_entries')->where('table_name', 'contoh_kunci')->value('record_id'));
    }

    public function test_entri_tidak_dapat_diubah(): void
    {
        $this->tenantSetup(insertion: 'all');
        $this->insert('awal');

        $this->expectException(QueryException::class);
        DB::table('change_log_entries')->update(['new_value' => 'dipalsukan']);
    }

    public function test_log_mati_selama_dijeda(): void
    {
        $this->tenantSetup(insertion: 'all');

        ChangeLogSwitch::pausedOn(null, fn () => $this->insert('saat migration'));

        $this->assertSame(0, $this->logged());
        $this->insert('sesudahnya');
        $this->assertGreaterThan(0, $this->logged());
    }

    public function test_riwayat_admin_bernama_pelaku_nama_field_dan_nilai_tampilan(): void
    {
        ChangeLogDefaults::register('contoh_log', 'Contoh', ['nama' => 'Nama', 'lokasi_id' => 'Lokasi']);
        app(ChangeLogValueResolvers::class)->register(new class implements ChangeLogValueResolver
        {
            public function table(): string
            {
                return 'contoh_log';
            }

            public function display(string $tenantId, string $field, array $values): array
            {
                return $field === 'lokasi_id' ? array_intersect_key(['L1' => 'Gudang pusat', 'L2' => 'Ruang rapat'], array_flip($values)) : [];
            }
        });
        $this->actingAs($this->owner);
        $id = $this->insert('awal', lokasi: 'L1');
        DB::table('contoh_log')->where('id', $id)->update(['lokasi_id' => 'L2']);

        $this->getJson("/api/v1/change-log/contoh_log/{$id}")
            ->assertOk()
            ->assertJsonPath('data.0.field_name', 'lokasi_id')
            ->assertJsonPath('data.0.field_caption', 'Lokasi')
            ->assertJsonPath('data.0.old_display', 'Gudang pusat')
            ->assertJsonPath('data.0.new_display', 'Ruang rapat')
            ->assertJsonPath('data.0.user_name', $this->owner->name)
            ->assertJsonPath('next_page', null);
    }

    public function test_riwayat_admin_tertutup_tanpa_izin_dan_tidak_membocorkan_tenant_lain(): void
    {
        $this->tenantSetup(insertion: 'all');
        $id = $this->insert('rahasia');

        $anggota = User::factory()->create();
        $keanggotaan = TenantMembership::query()->create([
            'tenant_id' => $this->membership->tenant_id, 'user_id' => $anggota->id, 'status' => 'active',
        ]);
        $this->actingAs($anggota)->getJson("/api/v1/change-log/contoh_log/{$id}")->assertForbidden();
        $this->grantDuties($keanggotaan, ['core.change-log.inquire']);
        $this->actingAs($anggota)->getJson("/api/v1/change-log/contoh_log/{$id}")->assertOk()->assertJsonCount(1, 'data');

        $lain = $this->register('owner@lain.test', 'Usaha Lain');
        $this->actingAs($lain)->getJson("/api/v1/change-log/contoh_log/{$id}")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_layar_setelan_menampilkan_bawaan_dan_menyimpan_setelan_tenant(): void
    {
        ChangeLogDefaults::register('contoh_log', 'Contoh', ['nama' => 'Nama', 'catatan' => 'Catatan']);

        $this->actingAs($this->owner)->get('/settings/change-log')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('settings/change-log')
                ->where('canManage', true)
                ->where('tables', fn (Collection $tables): bool => self::contohLog($tables)['customized'] === false
                    && self::contohLog($tables)['log_modification'] === true));

        $this->actingAs($this->owner)->put('/settings/change-log/contoh_log', [
            'log_insertion' => false, 'log_modification' => true, 'log_deletion' => false,
            'fields' => ['catatan' => ['log_insertion' => false, 'log_modification' => true, 'log_deletion' => false]],
        ])->assertRedirect();

        $id = $this->insert('awal', 'catatan awal');
        DB::table('contoh_log')->where('id', $id)->update(['nama' => 'ubah', 'catatan' => 'baru']);
        $this->assertSame([['catatan', 'modification']], $this->entries('contoh_log'));

        $this->actingAs($this->owner)->get('/settings/change-log')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('tables', fn (Collection $tables): bool => self::contohLog($tables)['customized'] === true
                && self::contohLog($tables)['log_insertion'] === false));
    }

    public function test_setelan_menolak_tanpa_izin_ubah_tabel_tak_terdaftar_dan_field_asing(): void
    {
        ChangeLogDefaults::register('contoh_log', 'Contoh', ['nama' => 'Nama']);
        $body = ['log_insertion' => true, 'log_modification' => true, 'log_deletion' => false];

        $anggota = User::factory()->create();
        $keanggotaan = TenantMembership::query()->create([
            'tenant_id' => $this->membership->tenant_id, 'user_id' => $anggota->id, 'status' => 'active',
        ]);
        $this->grantDuties($keanggotaan, ['core.change-log.inquire']);
        $this->actingAs($anggota)->put('/settings/change-log/contoh_log', $body)->assertForbidden();

        $this->actingAs($this->owner)->put('/settings/change-log/roles', $body)->assertNotFound();
        $this->actingAs($this->owner)->put('/settings/change-log/contoh_log', [
            ...$body, 'fields' => ['tenant_id' => ['log_insertion' => true]],
        ])->assertStatus(422);
    }

    public function test_pendaftaran_bawaan_idempoten_dan_mematikan_field_yang_tidak_lagi_disebut(): void
    {
        ChangeLogDefaults::register('contoh_log', 'Contoh', ['nama' => 'Nama', 'catatan' => 'Catatan']);
        ChangeLogDefaults::register('contoh_log', 'Contoh baru', ['nama' => 'Nama']);

        $this->assertSame(1, DB::table('change_log_setup_tables')->whereNull('tenant_id')->where('table_name', 'contoh_log')->count());
        $this->assertSame('Contoh baru', DB::table('change_log_setup_tables')->whereNull('tenant_id')->where('table_name', 'contoh_log')->value('table_caption'));
        $this->assertFalse((bool) DB::table('change_log_setup_fields')->whereNull('tenant_id')->where('table_name', 'contoh_log')->where('field_name', 'catatan')->value('log_modification'));
    }

    /**
     * Setelan tabel contoh di layar. Bawaan module produk (aset, pekerja) ikut tampil karena migration-nya
     * berjalan sekali per database test, jadi baris contoh dicari namanya, bukan urutannya.
     *
     * @param  Collection<int, array<string, mixed>>  $tables
     * @return array<string, mixed>
     */
    private static function contohLog(Collection $tables): array
    {
        return (array) $tables->firstWhere('table_name', 'contoh_log');
    }

    private function register(string $email, string $business): User
    {
        return app(RegisterBusiness::class)->handle([
            'name' => 'Owner '.$business, 'business_name' => $business,
            'app_ids' => ['app-uji'], 'email' => $email, 'password' => 'password',
        ]);
    }

    private function tenantSetup(string $insertion = 'none', string $modification = 'none', string $deletion = 'none'): void
    {
        DB::table('change_log_setup_tables')->updateOrInsert(
            ['tenant_id' => $this->membership->tenant_id, 'table_name' => 'contoh_log'],
            ['id' => (string) Str::ulid(), 'log_insertion' => $insertion, 'log_modification' => $modification, 'log_deletion' => $deletion],
        );
    }

    private function insert(string $nama, ?string $catatan = null, ?string $tenant = null, ?string $lokasi = null): string
    {
        $id = (string) Str::ulid();
        DB::table('contoh_log')->insert([
            'id' => $id, 'tenant_id' => $tenant ?? $this->membership->tenant_id, 'nama' => $nama, 'catatan' => $catatan, 'lokasi_id' => $lokasi,
        ]);

        return $id;
    }

    private function logged(string $table = 'contoh_log'): int
    {
        return DB::table('change_log_entries')->where('table_name', $table)->count();
    }

    /** @return list<array{0: string, 1: string}> */
    private function entries(string $table = 'contoh_log'): array
    {
        return DB::table('change_log_entries')->where('table_name', $table)->orderBy('id')->get()
            ->map(fn (object $e): array => [(string) $e->field_name, (string) $e->change_type])->all();
    }
}
