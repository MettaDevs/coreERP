<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Katalog keamanan retensi data (area 4 TODO analisa gap BC fase 1, K-15): melihat setelan dan hasil
 * penerapan retensi (`core.retention.read`) dan mengubah masa simpan (`core.retention.update`). Di BC,
 * Retention Policy Setup juga izin tersendiri, terpisah dari izin atas tabel yang diretensi.
 *
 * Bentuknya sama dengan `2026_09_28_130100_register_change_log_security_catalog`: satu entry point, dua
 * permission, privilege view dan maintain, duty inquire dan manage, lalu role Owner diselaraskan. Tanpa
 * kelas `App\` karena admin.erp ikut menjalankan migration Core. `up()` idempoten.
 */
return new class extends Migration
{
    private const PREFIX = 'core.retention';

    public function up(): void
    {
        $now = now();
        $p = self::PREFIX;

        DB::table('app_entry_points')->updateOrInsert(['code' => "{$p}.form"], [
            'app_id' => 'core', 'name' => 'Retensi data', 'type' => 'form', 'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach ([['read', 'Lihat setelan retensi data'], ['update', 'Ubah masa simpan data']] as [$access, $name]) {
            DB::table('permissions')->updateOrInsert(['code' => "{$p}.{$access}"], [
                'app_id' => 'core', 'entry_point_code' => "{$p}.form", 'access_level' => $access,
                'name' => $name, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $privileges = [
            "{$p}.view" => ['Melihat setelan retensi data', ["{$p}.read"]],
            "{$p}.maintain" => ['Memelihara setelan retensi data', ["{$p}.read", "{$p}.update"]],
        ];
        foreach ($privileges as $code => [$name, $permissions]) {
            DB::table('security_privileges')->updateOrInsert(['code' => $code], [
                'app_id' => 'core', 'name' => $name, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('security_privilege_permissions')->where('privilege_code', $code)->delete();
            DB::table('security_privilege_permissions')->insert(array_map(
                fn (string $permission): array => ['privilege_code' => $code, 'permission_code' => $permission],
                $permissions,
            ));
        }

        $duties = [
            "{$p}.inquire" => ['Lihat retensi data', "{$p}.view"],
            "{$p}.manage" => ['Kelola retensi data', "{$p}.maintain"],
        ];
        foreach ($duties as $code => [$name, $privilege]) {
            DB::table('security_duties')->updateOrInsert(['code' => $code], [
                'app_id' => 'core', 'name' => $name, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('security_duty_privileges')->where('duty_code', $code)->delete();
            DB::table('security_duty_privileges')->insert(['duty_code' => $code, 'privilege_code' => $privilege]);
        }

        // Owner memegang semua duty Core; dua duty baru ini ditambahkan ke setiap role Owner.
        foreach (DB::table('roles')->where('is_owner', true)->pluck('id') as $roleId) {
            DB::table('security_role_duties')->insertOrIgnore(array_map(
                fn (string $duty): array => ['role_id' => $roleId, 'duty_code' => $duty],
                array_keys($duties),
            ));
        }
    }

    public function down(): void
    {
        $p = self::PREFIX;
        $duties = ["{$p}.inquire", "{$p}.manage"];
        $privileges = ["{$p}.view", "{$p}.maintain"];

        DB::table('security_role_duties')->whereIn('duty_code', $duties)->delete();
        DB::table('security_duty_privileges')->whereIn('duty_code', $duties)->delete();
        DB::table('security_duties')->whereIn('code', $duties)->delete();
        DB::table('security_privilege_permissions')->whereIn('privilege_code', $privileges)->delete();
        DB::table('security_privileges')->whereIn('code', $privileges)->delete();
        DB::table('permissions')->whereIn('code', ["{$p}.read", "{$p}.update"])->delete();
        DB::table('app_entry_points')->where('code', "{$p}.form")->delete();
    }
};
