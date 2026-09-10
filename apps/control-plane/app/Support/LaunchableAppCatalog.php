<?php

namespace App\Support;

use App\Models\CoreApp;
use App\Models\ModuleInstallation;
use App\Models\TenantMembership;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class LaunchableAppCatalog
{
    /** @return list<string> */
    public function permissionsFor(TenantMembership $membership, string $appId): array
    {
        return $this->permissionQuery($membership)
            ->where('permissions.app_id', $appId)
            ->distinct()
            ->orderBy('permissions.code')
            ->pluck('permissions.code')
            ->all();
    }

    /**
     * @return list<array{id:string,label:string,href:string,items:list<array{id:string,label:string,href:string}>}>
     */
    public function navigationFor(TenantMembership $membership, CoreApp $app): array
    {
        $allowed = array_flip($this->permissionsFor($membership, $app->id));
        $navigation = $app->navigation ?? [];
        $sidebar = is_array($navigation['sidebar'] ?? null) ? $navigation['sidebar'] : [];

        return array_values(collect($navigation['rail'] ?? [])->map(function (array $rail) use ($allowed, $app, $sidebar): ?array {
            $items = array_values(collect($sidebar[$rail['id']] ?? [])
                ->filter(fn (array $item): bool => isset($allowed[$item['permission']]))
                ->map(fn (array $item): array => [
                    'id' => $item['id'],
                    'label' => $item['label'],
                    'href' => $this->tautanMenu($app->id, (string) $item['id']),
                ])->all());

            return $items === [] ? null : [
                'id' => $rail['id'],
                'label' => $rail['label'],
                'href' => $items[0]['href'],
                'items' => $items,
            ];
        })->filter()->all());
    }

    /**
     * Tujuan sebuah entri menu.
     *
     * Jalurnya diturunkan dengan aturan tetap `/<id module>/<id entri menu>`, bukan dibaca
     * dari kolom manifest tersendiri. Alasannya: sebuah kolom kedua yang berisi jalur akan
     * menyimpang dari berkas rute module cepat atau lambat, dan penyimpangannya tidak
     * terlihat sampai ada yang mengklik menunya. Dengan aturan tetap, berkas rute module
     * adalah satu-satunya sumber kebenaran, dan test membuktikan tiap tautan menu
     * benar-benar mendarat pada rute yang terdaftar.
     */
    private function tautanMenu(string $appId, string $itemId): string
    {
        return '/'.$appId.'/'.$itemId;
    }

    /**
     * Bahan sidebar untuk sebuah halaman module.
     *
     * Halaman module dirender module, tetapi kerangka layarnya tetap milik Core: rail,
     * daftar menu, dan penanda entri yang sedang terbuka. Kalau bahan ini ikut dikirim
     * module lewat props halamannya, setiap module harus mengulang pemanggilan katalog
     * yang sama dan satu module yang lupa akan kehilangan sidebar-nya tanpa error.
     *
     * @return array{id:string,name:string,navigation:array{rails:list<array{id:string,label:string,href:string,items:list<array{id:string,label:string,href:string}>}>,activeItemId:string|null}}|null
     */
    public function kerangkaModule(TenantMembership $membership, string $moduleId, string $path): ?array
    {
        $app = CoreApp::query()->whereKey($moduleId)->first();

        if ($app === null) {
            return null;
        }

        $rails = $this->navigationFor($membership, $app);
        $aktif = collect($rails)
            ->flatMap(fn (array $rail): array => $rail['items'])
            ->firstWhere('href', $path);

        return [
            'id' => $app->id,
            'name' => $app->name,
            'navigation' => [
                'rails' => $rails,
                'activeItemId' => $aktif['id'] ?? null,
            ],
        ];
    }

    /** @return list<array{id:string,name:string,description:string,href:string,version:string}> */
    public function for(TenantMembership $membership): array
    {
        $authorizedAppIds = $this->permissionQuery($membership)
            ->distinct()
            ->pluck('permissions.app_id');

        // Satu penentu kesiapan, karena sekarang hanya ada satu jalur: sebuah app siap
        // diluncurkan bila catatan pemasangan module-nya berstatus terpasang. Tidak ada
        // artifact yang ditempatkan dan tidak ada runtime kedua yang perlu dinyatakan siap.
        $readyAppIds = array_values(array_intersect(
            $this->moduleTerpasang($membership),
            $authorizedAppIds->map(strval(...))->all(),
        ));

        return array_values(
            CoreApp::query()
                ->whereIn('id', $readyAppIds)
                ->orderBy('name')
                ->get(['id', 'name', 'description', 'version'])
                ->map(fn (CoreApp $app): array => [
                    'id' => $app->id,
                    'name' => $app->name,
                    'description' => $app->description ?? '',
                    'href' => '/apps/'.$app->id,
                    'version' => $app->version,
                ])->all(),
        );
    }

    private function permissionQuery(TenantMembership $membership): Builder
    {
        // Rantai kanoniknya adalah role -> duty -> privilege -> permission.
        // Role yang diberikan kepada user diperluas lebih dahulu melalui hierarchy
        // role, karena parent mewarisi duty seluruh turunannya.
        $assignedRoleIds = DB::table('role_assignments as assignments')
            ->join('roles', 'roles.id', '=', 'assignments.role_id')
            ->where('assignments.membership_id', $membership->id)
            ->where('assignments.status', 'active')
            ->where('roles.is_active', true)
            ->where('assignments.valid_from', '<=', now())
            ->where(fn ($query) => $query->whereNull('assignments.valid_until')->orWhere('assignments.valid_until', '>', now()))
            ->pluck('assignments.role_id')
            ->all();

        $effectiveRoleIds = app(RoleHierarchy::class)
            ->effectiveRoleIds($membership->tenant_id, array_map(strval(...), $assignedRoleIds));

        return DB::table('security_role_duties as role_duties')
            ->join('security_duty_privileges as duty_privileges', 'duty_privileges.duty_code', '=', 'role_duties.duty_code')
            ->join('security_privilege_permissions as privilege_permissions', 'privilege_permissions.privilege_code', '=', 'duty_privileges.privilege_code')
            ->join('permissions', 'permissions.code', '=', 'privilege_permissions.permission_code')
            ->whereIn('role_duties.role_id', $effectiveRoleIds);
    }

    /**
     * Module yang terpasang untuk tenant ini.
     *
     * Ini penentu kesiapan bagi module, dan bentuknya sengaja jauh lebih sederhana daripada
     * milik container: tidak ada artifact yang ditempatkan, tidak ada runtime yang perlu
     * dinyatakan siap, dan tidak ada rilis yang dicocokkan versinya. Module berjalan di
     * proses yang sama dengan Core; kalau Core hidup, module-nya hidup.
     *
     * @return list<string>
     */
    public function moduleTerpasang(TenantMembership $membership): array
    {
        $id = DB::table('core_module_installations')
            ->where('tenant_id', $membership->tenant_id)
            ->where('status', ModuleInstallation::STATUS_INSTALLED)
            ->orderBy('module_id')
            ->pluck('module_id')
            ->all();

        return array_values(array_map(strval(...), $id));
    }

    /** Apakah app ini dilayani runtime Core sebagai module, bukan oleh container tersendiri. */
    public function berjalanSebagaiModul(TenantMembership $membership, string $appId): bool
    {
        return in_array($appId, $this->moduleTerpasang($membership), true);
    }
}
