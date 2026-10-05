<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Hak pengelolaan koneksi dipisahkan dari setup finance; rantai keamanan tetap sama. */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $prefix = 'core.integration-clients';
        DB::table('app_entry_points')->updateOrInsert(['code' => $prefix.'.form'], [
            'app_id' => 'core', 'name' => 'Layar klien integrasi', 'type' => 'form', 'created_at' => $now, 'updated_at' => $now,
        ]);

        foreach (['read' => 'Lihat', 'update' => 'Kelola'] as $access => $label) {
            DB::table('permissions')->updateOrInsert(['code' => $prefix.'.'.$access], [
                'app_id' => 'core', 'entry_point_code' => $prefix.'.form', 'access_level' => $access,
                'name' => $label.' klien integrasi', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        foreach ([
            'inquire' => ['view', 'Lihat', ['read']],
            'manage' => ['maintain', 'Kelola', ['read', 'update']],
        ] as $duty => [$privilege, $label, $permissions]) {
            DB::table('security_privileges')->updateOrInsert(['code' => $prefix.'.'.$privilege], [
                'app_id' => 'core', 'name' => $label.' koneksi sistem luar', 'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ($permissions as $permission) {
                DB::table('security_privilege_permissions')->insertOrIgnore([
                    'privilege_code' => $prefix.'.'.$privilege, 'permission_code' => $prefix.'.'.$permission,
                ]);
            }
            DB::table('security_duties')->updateOrInsert(['code' => $prefix.'.'.$duty], [
                'app_id' => 'core', 'name' => $label.' klien integrasi', 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('security_duty_privileges')->insertOrIgnore([
                'duty_code' => $prefix.'.'.$duty, 'privilege_code' => $prefix.'.'.$privilege,
            ]);
            // Hanya Owner mengikuti semua duty Core. Role finance tidak otomatis mendapat hak integrasi.
            foreach (DB::table('roles')->where('is_owner', true)->pluck('id') as $roleId) {
                DB::table('security_role_duties')->insertOrIgnore([
                    'role_id' => $roleId, 'duty_code' => $prefix.'.'.$duty,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Tidak mencabut hak atau menghapus katalog saat rollback image.
    }
};
