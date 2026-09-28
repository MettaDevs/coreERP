<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Katalog keamanan layar Core masuk ke tabel katalog yang sama dengan milik module, lalu setiap role Owner
 * diselaraskan sehingga memegangnya (SEC-22, TODO feed posting 7.4). Keputusan pemilik produk, 25 September 2026:
 * delapan kelompok layar, masing-masing duty *Lihat* dan *Kelola* — padanan duty *Inquire* dan *Maintain* di
 * D365 — ditambah Pantau posting yang tindak lanjutnya duty sendiri. Delapan belas duty.
 *
 * Tiap kelompok punya satu entry point `form` dan dua permission: `read` untuk melihat, dan `update` untuk
 * mengubah. Pada layar setup Core, tingkat `update` mencakup membuat dan mengarsipkan: tidak ada layar Core yang
 * butuh memberi "boleh membuat tetapi tidak boleh mengubah". Tindak lanjut Pantau posting — Validasi ulang dan
 * Tandai manual — adalah operasi, jadi tingkatnya `invoke`.
 *
 * Isinya ditulis di sini, bukan dibaca dari kelas aplikasi: admin.erp menjalankan migration Core di runtime-nya
 * sendiri tanpa kelas `App\`, dan migration adalah potret yang tidak boleh ikut berubah saat kodenya berubah.
 * Kode permission yang dipakai kode aplikasi ada sebagai konstanta di `CoreSecurityCatalog`. Mengubah katalog
 * berarti migration baru. `up()` idempoten.
 */
return new class extends Migration
{
    /** Kunci kelompok => [nama entry point, nama kelompok dalam kalimat duty]. */
    private const GROUPS = [
        'access' => ['Layar akses dan keamanan', 'akses'],
        'organization' => ['Layar organisasi dan buku alamat', 'organisasi'],
        'number-sequence' => ['Layar number sequence dan kalender fiskal', 'number sequence'],
        'reference-data' => ['Layar data referensi', 'data referensi'],
        'report-layout' => ['Layar tata letak laporan', 'tata letak laporan'],
        'workflow' => ['Layar workflow', 'workflow'],
        'finance-setup' => ['Layar setup finance', 'setup finance'],
        'vendor' => ['Layar vendor', 'vendor'],
    ];

    public function up(): void
    {
        $catalog = $this->catalog();
        $now = now();

        // Baris app `core` sudah ditulis migration vendor; dipasang lagi bila tabel `apps` pernah dikosongkan.
        DB::table('apps')->insertOrIgnore([
            'id' => 'core', 'name' => 'CoreERP', 'version' => '1.0.0', 'status' => 'internal', 'database_name' => null,
            'description' => 'Pemilik referensi nomor milik Core sendiri, misalnya nomor vendor. Bukan produk yang dipasang.',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        foreach ($catalog['entry_points'] as $entry) {
            DB::table('app_entry_points')->updateOrInsert(['code' => $entry['code']], [
                'app_id' => 'core', 'name' => $entry['name'], 'type' => $entry['type'], 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        foreach ($catalog['permissions'] as $permission) {
            DB::table('permissions')->updateOrInsert(['code' => $permission['code']], [
                'app_id' => 'core', 'entry_point_code' => $permission['entry_point'], 'access_level' => $permission['access'],
                'name' => $permission['name'], 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        foreach ($catalog['privileges'] as $privilege) {
            DB::table('security_privileges')->updateOrInsert(['code' => $privilege['code']], [
                'app_id' => 'core', 'name' => $privilege['name'], 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('security_privilege_permissions')->where('privilege_code', $privilege['code'])->delete();
            DB::table('security_privilege_permissions')->insert(array_map(
                fn (string $code): array => ['privilege_code' => $privilege['code'], 'permission_code' => $code],
                $privilege['permissions'],
            ));
        }
        foreach ($catalog['duties'] as $duty) {
            DB::table('security_duties')->updateOrInsert(['code' => $duty['code']], [
                'app_id' => 'core', 'name' => $duty['name'], 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('security_duty_privileges')->where('duty_code', $duty['code'])->delete();
            DB::table('security_duty_privileges')->insert(array_map(
                fn (string $code): array => ['duty_code' => $duty['code'], 'privilege_code' => $code],
                $duty['privileges'],
            ));
        }

        $this->syncOwnerRoles();
    }

    public function down(): void
    {
        $catalog = $this->catalog();
        $duties = array_column($catalog['duties'], 'code');
        $privileges = array_column($catalog['privileges'], 'code');

        DB::table('security_role_duties')->whereIn('duty_code', $duties)->delete();
        DB::table('security_duty_privileges')->whereIn('duty_code', $duties)->delete();
        DB::table('security_duties')->whereIn('code', $duties)->delete();
        DB::table('security_privilege_permissions')->whereIn('privilege_code', $privileges)->delete();
        DB::table('security_privileges')->whereIn('code', $privileges)->delete();
        DB::table('permissions')->whereIn('code', array_column($catalog['permissions'], 'code'))->delete();
        DB::table('app_entry_points')->whereIn('code', array_column($catalog['entry_points'], 'code'))->delete();
    }

    /**
     * @return array{
     *     entry_points: list<array{code: string, name: string, type: string}>,
     *     permissions: list<array{code: string, entry_point: string, access: string, name: string}>,
     *     privileges: list<array{code: string, name: string, permissions: list<string>}>,
     *     duties: list<array{code: string, name: string, privileges: list<string>}>
     * }
     */
    private function catalog(): array
    {
        $catalog = ['entry_points' => [], 'permissions' => [], 'privileges' => [], 'duties' => []];

        foreach (self::GROUPS as $key => [$screen, $name]) {
            $prefix = 'core.'.$key;
            $catalog['entry_points'][] = ['code' => $prefix.'.form', 'name' => $screen, 'type' => 'form'];
            $catalog['permissions'][] = ['code' => $prefix.'.read', 'entry_point' => $prefix.'.form', 'access' => 'read', 'name' => 'Lihat '.$name];
            $catalog['permissions'][] = ['code' => $prefix.'.update', 'entry_point' => $prefix.'.form', 'access' => 'update', 'name' => 'Ubah '.$name];
            $catalog['privileges'][] = ['code' => $prefix.'.view', 'name' => 'Melihat '.$name, 'permissions' => [$prefix.'.read']];
            $catalog['privileges'][] = ['code' => $prefix.'.maintain', 'name' => 'Memelihara '.$name, 'permissions' => [$prefix.'.read', $prefix.'.update']];
            $catalog['duties'][] = ['code' => $prefix.'.inquire', 'name' => 'Lihat '.$name, 'privileges' => [$prefix.'.view']];
            $catalog['duties'][] = ['code' => $prefix.'.manage', 'name' => 'Kelola '.$name, 'privileges' => [$prefix.'.maintain']];
        }

        $catalog['entry_points'][] = ['code' => 'core.finance-posting.form', 'name' => 'Layar Pantau posting', 'type' => 'form'];
        $catalog['permissions'][] = ['code' => 'core.finance-posting.read', 'entry_point' => 'core.finance-posting.form', 'access' => 'read', 'name' => 'Lihat Pantau posting'];
        $catalog['permissions'][] = ['code' => 'core.finance-posting.process', 'entry_point' => 'core.finance-posting.form', 'access' => 'invoke', 'name' => 'Validasi ulang dan tandai manual posting'];
        $catalog['privileges'][] = ['code' => 'core.finance-posting.view', 'name' => 'Melihat Pantau posting', 'permissions' => ['core.finance-posting.read']];
        $catalog['privileges'][] = ['code' => 'core.finance-posting.follow-up', 'name' => 'Menindaklanjuti posting', 'permissions' => ['core.finance-posting.read', 'core.finance-posting.process']];
        $catalog['duties'][] = ['code' => 'core.finance-posting.inquire', 'name' => 'Pantau posting (lihat)', 'privileges' => ['core.finance-posting.view']];
        $catalog['duties'][] = ['code' => 'core.finance-posting.follow-up', 'name' => 'Pantau posting (tindak lanjut)', 'privileges' => ['core.finance-posting.follow-up']];

        return $catalog;
    }

    /**
     * Setiap role Owner memegang semua duty yang sah untuk tenantnya: duty Core, duty app yang dibeli, dan duty
     * buatan tenant yang aktif. Aturan yang sama dengan `OwnerRoleDuties`, ditulis ulang di sini karena migration
     * tidak memakai kelas aplikasi.
     */
    private function syncOwnerRoles(): void
    {
        $now = now();

        foreach (DB::table('roles')->where('is_owner', true)->get(['id', 'tenant_id']) as $role) {
            $appIds = DB::table('tenant_app_entitlements')
                ->where('tenant_id', $role->tenant_id)
                ->where('status', 'active')
                ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $now))
                ->pluck('app_id')
                ->push('core')
                ->unique()
                ->all();
            $duties = DB::table('security_duties')
                ->where(fn ($query) => $query
                    ->where(fn ($query) => $query->whereNull('tenant_id')->whereIn('app_id', $appIds))
                    ->orWhere(fn ($query) => $query->where('tenant_id', $role->tenant_id)->where('source', 'custom')->where('status', 'active')))
                ->pluck('code');

            DB::table('security_role_duties')->where('role_id', $role->id)->whereNotIn('duty_code', $duties)->delete();
            DB::table('security_role_duties')->insertOrIgnore(
                $duties->map(fn (string $code): array => ['role_id' => $role->id, 'duty_code' => $code])->all(),
            );
        }
    }
};
