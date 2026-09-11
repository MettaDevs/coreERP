<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\SecurityDuty;
use App\Models\SecurityPrivilege;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Membuat role pemantau read-only untuk setiap tenant.
 *
 * Aplikasi membundel `read` bersama `create` dan `update` di dalam satu tugas
 * akses, sehingga role "hanya lihat" tidak dapat dirakit dari objek bawaan.
 * Seeder ini menempuh jalan yang sama dengan yang dilakukan admin lewat layar
 * Konfigurasi keamanan: satu tugas akses berisi seluruh izin `read`, satu
 * tanggung jawab, lalu satu role.
 *
 * Idempoten — dijalankan berulang tidak menggandakan objek.
 *
 * Jalankan: php artisan db:seed --class=ReadOnlyRoleSeeder
 */
class ReadOnlyRoleSeeder extends Seeder
{
    private const ROLE_NAME = 'Manager Aset';

    private const PRIVILEGE_NAME = 'Lihat seluruh data aset';

    private const DUTY_NAME = 'Pantau data aset';

    public function run(): void
    {
        Tenant::query()->with('entitlements')->each(function (Tenant $tenant): void {
            $appIds = $tenant->entitlements
                ->where('status', 'active')
                ->pluck('app_id')
                ->all();
            $readCodes = Permission::query()
                ->whereIn('app_id', $appIds)
                ->where('access_level', 'read')
                ->pluck('code')
                ->all();

            if ($readCodes === []) {
                $this->command?->warn("Tenant {$tenant->id} tidak punya izin read; dilewati.");

                return;
            }

            $privilege = $this->upsertPrivilege($tenant->id);
            $privilege->permissions()->sync($readCodes);

            $duty = $this->upsertDuty($tenant->id);
            $duty->privileges()->sync([$privilege->code]);

            $role = Role::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'name' => self::ROLE_NAME],
                ['is_active' => true],
            );
            $role->duties()->sync([$duty->code]);

            $this->command?->info(sprintf(
                'Tenant %s: role "%s" memuat %d izin read.',
                $tenant->id, self::ROLE_NAME, count($readCodes),
            ));
        });
    }

    private function upsertPrivilege(string $tenantId): SecurityPrivilege
    {
        return SecurityPrivilege::query()->firstOrCreate(
            ['tenant_id' => $tenantId, 'name' => self::PRIVILEGE_NAME, 'source' => 'custom'],
            [
                'code' => 'custom.'.$tenantId.'.'.Str::ulid(),
                'status' => 'active',
                'published_at' => now(),
            ],
        );
    }

    private function upsertDuty(string $tenantId): SecurityDuty
    {
        return SecurityDuty::query()->firstOrCreate(
            ['tenant_id' => $tenantId, 'name' => self::DUTY_NAME, 'source' => 'custom'],
            [
                'code' => 'custom.'.$tenantId.'.'.Str::ulid(),
                'status' => 'active',
                'published_at' => now(),
            ],
        );
    }
}
