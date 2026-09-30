<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Katalog keamanan preset laporan bersama (K-25): membuat, mengubah, dan mengarsipkan preset yang terlihat
 * semua pengguna tenant (`core.report-preset.update`). Preset pribadi tidak butuh permission ini; hak
 * menjalankan laporannya sudah cukup.
 *
 * Di BC, setelan laporan yang "Shared with all users" dikelola di halaman Report Settings yang dijaga izin
 * tabel `Object Options` sendiri, terpisah dari izin layout laporan. Di sini juga terpisah dari
 * `core.report-layout.update`, supaya hak mengubah kop dokumen dan hak membagikan preset dapat diberikan
 * kepada orang yang berbeda. Tidak ada duty Lihat: preset bersama sudah terbaca oleh setiap orang yang boleh
 * menjalankan laporannya.
 *
 * Bentuknya mengikuti `2026_09_30_100100_register_retention_security_catalog`: entry point, permission,
 * privilege, duty, lalu role Owner diselaraskan. Tanpa kelas `App\` karena admin.erp ikut menjalankan
 * migration Core. `up()` idempoten.
 */
return new class extends Migration
{
    private const PREFIX = 'core.report-preset';

    public function up(): void
    {
        $now = now();
        $p = self::PREFIX;

        DB::table('app_entry_points')->updateOrInsert(['code' => "{$p}.form"], [
            'app_id' => 'core', 'name' => 'Preset laporan bersama', 'type' => 'form', 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('permissions')->updateOrInsert(['code' => "{$p}.update"], [
            'app_id' => 'core', 'entry_point_code' => "{$p}.form", 'access_level' => 'update',
            'name' => 'Bagikan dan kelola preset laporan bersama', 'created_at' => $now, 'updated_at' => $now,
        ]);

        DB::table('security_privileges')->updateOrInsert(['code' => "{$p}.maintain"], [
            'app_id' => 'core', 'name' => 'Memelihara preset laporan bersama', 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('security_privilege_permissions')->where('privilege_code', "{$p}.maintain")->delete();
        DB::table('security_privilege_permissions')->insert(['privilege_code' => "{$p}.maintain", 'permission_code' => "{$p}.update"]);

        DB::table('security_duties')->updateOrInsert(['code' => "{$p}.manage"], [
            'app_id' => 'core', 'name' => 'Kelola preset laporan bersama', 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('security_duty_privileges')->where('duty_code', "{$p}.manage")->delete();
        DB::table('security_duty_privileges')->insert(['duty_code' => "{$p}.manage", 'privilege_code' => "{$p}.maintain"]);

        // Owner memegang semua duty Core; duty baru ini ditambahkan ke setiap role Owner.
        foreach (DB::table('roles')->where('is_owner', true)->pluck('id') as $roleId) {
            DB::table('security_role_duties')->insertOrIgnore(['role_id' => $roleId, 'duty_code' => "{$p}.manage"]);
        }

        // Nama preset unik per pemilik untuk preset pribadi, dan unik per laporan untuk preset bersama: dua
        // preset bersama bernama sama membuat daftar Preset rekan tidak dapat dibedakan.
        DB::statement('DROP INDEX IF EXISTS report_presets_owner_name_unique');
        DB::statement('CREATE UNIQUE INDEX report_presets_owner_name_unique ON report_presets (tenant_id, report_code, user_id, lower(name)) WHERE deleted_at IS NULL AND NOT shared');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS report_presets_shared_name_unique ON report_presets (tenant_id, report_code, lower(name)) WHERE deleted_at IS NULL AND shared');
    }

    public function down(): void
    {
        $p = self::PREFIX;

        DB::statement('DROP INDEX IF EXISTS report_presets_shared_name_unique');
        DB::statement('DROP INDEX IF EXISTS report_presets_owner_name_unique');
        DB::statement('CREATE UNIQUE INDEX report_presets_owner_name_unique ON report_presets (tenant_id, report_code, user_id, lower(name)) WHERE deleted_at IS NULL');

        DB::table('security_role_duties')->where('duty_code', "{$p}.manage")->delete();
        DB::table('security_duty_privileges')->where('duty_code', "{$p}.manage")->delete();
        DB::table('security_duties')->where('code', "{$p}.manage")->delete();
        DB::table('security_privilege_permissions')->where('privilege_code', "{$p}.maintain")->delete();
        DB::table('security_privileges')->where('code', "{$p}.maintain")->delete();
        DB::table('permissions')->where('code', "{$p}.update")->delete();
        DB::table('app_entry_points')->where('code', "{$p}.form")->delete();
    }
};
