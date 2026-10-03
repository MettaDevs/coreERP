<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Katalog keamanan engine analitik (KA-14, disetujui pemilik produk apa adanya 3 Oktober 2026). Rantainya
 * di docs/todo/analitik/keamanan.md bagian *Rantai izin yang diusulkan*; kode di bawah sama persis dengan
 * yang disetujui, karena kode yang sudah masuk role tenant tidak dapat diganti diam-diam.
 *
 * Hak membaca angka sebuah dataset tidak ada di sini: ia permission baca resource module yang sama dengan
 * layar daftarnya (KA-15). Rantai ini hanya mengatur fitur analitik — melihat dasbor, menyusunnya,
 * menjalankan analisis bebas, dasbor bersama, data pribadi, dan publikasi.
 *
 * Tanpa kelas `App\` karena admin.erp ikut menjalankan migration Core. `up()` idempoten, dan
 * `Tests\TestCase::reinstallCoreSecurityCatalog()` menjalankannya ulang setelah test `DatabaseTruncation`.
 */
return new class extends Migration
{
    private const ENTRY_POINTS = [
        'core.analytics.dashboard.form' => ['Dasbor', 'form'],
        'core.analytics.explore.form' => ['Analisis data', 'form'],
        'core.analytics.shared-dashboard.form' => ['Dasbor bersama', 'form'],
        'core.analytics.personal-data.action' => ['Data pribadi di analitik', 'action'],
        'core.analytics.publication.form' => ['Publikasi dan embed', 'form'],
    ];

    /** kode => [entry point, access, nama] */
    private const PERMISSIONS = [
        'core.analytics.dashboard.read' => ['core.analytics.dashboard.form', 'read', 'Lihat dasbor'],
        'core.analytics.dashboard.create' => ['core.analytics.dashboard.form', 'create', 'Buat dasbor pribadi'],
        'core.analytics.explore.invoke' => ['core.analytics.explore.form', 'invoke', 'Jalankan analisis data'],
        'core.analytics.shared-dashboard.update' => ['core.analytics.shared-dashboard.form', 'update', 'Kelola dasbor bersama'],
        'core.analytics.personal-data.read' => ['core.analytics.personal-data.action', 'read', 'Pakai data pribadi di analitik'],
        'core.analytics.publication.read' => ['core.analytics.publication.form', 'read', 'Lihat publikasi'],
        'core.analytics.publication.update' => ['core.analytics.publication.form', 'update', 'Kelola publikasi dan embed'],
    ];

    /** kode => [nama, permission] */
    private const PRIVILEGES = [
        'core.analytics.dashboard.view' => ['Melihat dasbor', ['core.analytics.dashboard.read']],
        'core.analytics.dashboard.author' => ['Menyusun dasbor dan analisis', ['core.analytics.dashboard.read', 'core.analytics.dashboard.create', 'core.analytics.explore.invoke']],
        'core.analytics.shared-dashboard.maintain' => ['Memelihara dasbor bersama', ['core.analytics.shared-dashboard.update']],
        'core.analytics.personal-data.view' => ['Memakai data pribadi di analitik', ['core.analytics.personal-data.read']],
        'core.analytics.publication.maintain' => ['Memelihara publikasi dan embed', ['core.analytics.publication.read', 'core.analytics.publication.update']],
    ];

    /** kode => [nama, privilege] */
    private const DUTIES = [
        'core.analytics.inquire' => ['Lihat dasbor', ['core.analytics.dashboard.view']],
        'core.analytics.analyze' => ['Susun dasbor dan analisis data', ['core.analytics.dashboard.author']],
        'core.analytics.manage' => ['Kelola dasbor bersama', ['core.analytics.shared-dashboard.maintain']],
        'core.analytics.publish' => ['Kelola publikasi dan embed', ['core.analytics.publication.maintain']],
        'core.analytics.personal-data' => ['Pakai data pribadi di analitik', ['core.analytics.personal-data.view']],
    ];

    public function up(): void
    {
        $now = now();
        foreach (self::ENTRY_POINTS as $code => [$name, $type]) {
            DB::table('app_entry_points')->updateOrInsert(['code' => $code], [
                'app_id' => 'core', 'name' => $name, 'type' => $type, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        foreach (self::PERMISSIONS as $code => [$entryPoint, $access, $name]) {
            DB::table('permissions')->updateOrInsert(['code' => $code], [
                'app_id' => 'core', 'entry_point_code' => $entryPoint, 'access_level' => $access,
                'name' => $name, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        foreach (self::PRIVILEGES as $code => [$name, $permissions]) {
            DB::table('security_privileges')->updateOrInsert(['code' => $code], [
                'app_id' => 'core', 'name' => $name, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('security_privilege_permissions')->where('privilege_code', $code)->delete();
            foreach ($permissions as $permission) {
                DB::table('security_privilege_permissions')->insert(['privilege_code' => $code, 'permission_code' => $permission]);
            }
        }
        foreach (self::DUTIES as $code => [$name, $privileges]) {
            DB::table('security_duties')->updateOrInsert(['code' => $code], [
                'app_id' => 'core', 'name' => $name, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('security_duty_privileges')->where('duty_code', $code)->delete();
            foreach ($privileges as $privilege) {
                DB::table('security_duty_privileges')->insert(['duty_code' => $code, 'privilege_code' => $privilege]);
            }
        }

        // Owner memegang semua duty Core, termasuk data pribadi (PQ-04, disetujui pemilik 3 Okt 2026). Role
        // lain tidak mendapat satu pun duty ini secara otomatis; admin tenant memberikannya dengan sengaja.
        foreach (DB::table('roles')->where('is_owner', true)->pluck('id') as $roleId) {
            foreach (array_keys(self::DUTIES) as $duty) {
                DB::table('security_role_duties')->insertOrIgnore(['role_id' => $roleId, 'duty_code' => $duty]);
            }
        }
    }

    public function down(): void
    {
        $duties = array_keys(self::DUTIES);
        $privileges = array_keys(self::PRIVILEGES);

        DB::table('security_role_duties')->whereIn('duty_code', $duties)->delete();
        DB::table('security_duty_privileges')->whereIn('duty_code', $duties)->delete();
        DB::table('security_duties')->whereIn('code', $duties)->delete();
        DB::table('security_privilege_permissions')->whereIn('privilege_code', $privileges)->delete();
        DB::table('security_privileges')->whereIn('code', $privileges)->delete();
        DB::table('permissions')->whereIn('code', array_keys(self::PERMISSIONS))->delete();
        DB::table('app_entry_points')->whereIn('code', array_keys(self::ENTRY_POINTS))->delete();
    }
};
