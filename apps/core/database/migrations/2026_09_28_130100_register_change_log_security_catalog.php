<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Katalog keamanan log perubahan (area 2 TODO analisa gap BC fase 1): melihat riwayat perubahan record
 * (`core.change-log.read`) dan mengatur tabel serta field yang dicatat (`core.change-log.update`). Di BC,
 * membaca Change Log Entries dan mengubah Change Log Setup juga dua izin terpisah dari izin atas record-nya.
 *
 * Bentuknya mengikuti `2026_09_25_120100_register_core_security_catalog`: satu entry point, dua permission,
 * privilege view dan maintain, duty inquire dan manage, lalu role Owner diselaraskan. Tanpa kelas `App\`
 * karena admin.erp ikut menjalankan migration Core. `up()` idempoten.
 */
return new class extends Migration
{
    private const PREFIX = 'core.change-log';

    public function up(): void
    {
        $now = now();
        $p = self::PREFIX;

        DB::table('app_entry_points')->updateOrInsert(['code' => "{$p}.form"], [
            'app_id' => 'core', 'name' => 'Log perubahan', 'type' => 'form', 'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach ([['read', 'Lihat riwayat perubahan'], ['update', 'Ubah setelan log perubahan']] as [$access, $name]) {
            DB::table('permissions')->updateOrInsert(['code' => "{$p}.{$access}"], [
                'app_id' => 'core', 'entry_point_code' => "{$p}.form", 'access_level' => $access,
                'name' => $name, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $privileges = [
            "{$p}.view" => ['Melihat riwayat perubahan', ["{$p}.read"]],
            "{$p}.maintain" => ['Memelihara setelan log perubahan', ["{$p}.read", "{$p}.update"]],
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
            "{$p}.inquire" => ['Lihat riwayat perubahan', "{$p}.view"],
            "{$p}.manage" => ['Kelola log perubahan', "{$p}.maintain"],
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
