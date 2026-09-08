<?php

declare(strict_types=1);

namespace App\Actions\Modules;

use App\Models\ModuleInstallation;
use App\Support\Modules\ModuleManifest;
use App\Support\Modules\ModuleMigrator;
use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\ModuleSeeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Memasang sebuah module untuk satu tenant.
 *
 * Urutannya tidak boleh ditukar. Migration lebih dulu, karena seed menulis ke tabel yang
 * dibuatnya. Catatan pemasangan sebelum seed, karena seed membaca `seeded_at` dari catatan
 * itu untuk memutuskan apakah ia perlu berjalan.
 *
 * Memasang module yang sudah terpasang **bukan kesalahan**. Ia mengembalikan status ke
 * terpasang tanpa menyentuh data dan tanpa mengisi ulang data awal, sehingga perintah ini
 * aman dijalankan dua kali — sifat yang dibutuhkan admin pelanggan yang menjalankan
 * pembaruan on-prem dengan tangan.
 */
final class InstallModule
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly ModuleMigrator $migrator,
        private readonly ModuleSeeder $seeder,
    ) {}

    public function handle(string $moduleId, string $tenantId): ModuleInstallation
    {
        $module = $this->registry->cari($moduleId);

        if ($module === null) {
            throw new RuntimeException(sprintf('Module "%s" tidak ditemukan di folder modules/.', $moduleId));
        }

        $this->pastikanDependencyTerpasang($module, $tenantId);

        $this->migrator->naik($module);

        DB::table('core_module_installations')->upsert([[
            'tenant_id' => $tenantId,
            'module_id' => $module->id,
            'version' => $module->versi,
            'status' => ModuleInstallation::STATUS_INSTALLED,
            'installed_at' => now(),
            'disabled_at' => null,
            'uninstalled_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]], ['tenant_id', 'module_id'], ['version', 'status', 'disabled_at', 'uninstalled_at', 'updated_at']);

        $this->seeder->jalankan($module, $tenantId);

        /** @var ModuleInstallation $pemasangan */
        $pemasangan = ModuleInstallation::query()
            ->where('tenant_id', $tenantId)
            ->where('module_id', $module->id)
            ->firstOrFail();

        return $pemasangan;
    }

    /**
     * Dependency diperiksa terhadap **apa yang terpasang pada tenant ini**, bukan terhadap
     * katalog. Sebuah module boleh saja dikenal platform dan tetap tidak dimiliki tenant
     * yang sedang dipasangi.
     */
    private function pastikanDependencyTerpasang(ModuleManifest $module, string $tenantId): void
    {
        $kurang = [];

        foreach ($module->dependency as $butuh) {
            $ada = ModuleInstallation::query()
                ->where('tenant_id', $tenantId)
                ->where('module_id', $butuh)
                ->where('status', ModuleInstallation::STATUS_INSTALLED)
                ->exists();

            if (! $ada) {
                $kurang[] = $butuh;
            }
        }

        if ($kurang !== []) {
            throw new RuntimeException(sprintf(
                'Module "%s" membutuhkan %s, yang belum terpasang pada tenant ini.',
                $module->id,
                implode(', ', $kurang),
            ));
        }
    }
}
