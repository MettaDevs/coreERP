<?php

declare(strict_types=1);

namespace App\Actions\Modules;

use App\Models\Environment;
use App\Models\ModuleInstallation;
use App\Support\ControlPlane\EnvironmentConnection;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Mencabut module dari satu tenant.
 *
 * **Aksi ini tidak punya opsi penghapusan data, dan tidak boleh diberi satu pun.** Bukan
 * karena lupa: semua penghapusan di platform ini adalah penghapusan lunak, dan sebuah opsi
 * yang ada tetapi kadang ditolak cepat atau lambat akan dipakai pada module yang lupa
 * menyatakan penguncinya. Opsi yang tidak ada tidak bisa salah dipakai. Bentuk ini sama
 * dengan Business Central, yang mencabut ekstensi tanpa menyentuh datanya.
 *
 * Baris pemasangannya juga tidak dihapus. Ia menyimpan `seeded_at`, dan tanpa jejak itu
 * pelanggan yang berlangganan lagi akan mendapat data awal terisi ulang di atas data lama
 * yang tidak pernah dihapus.
 */
final class UninstallModule
{
    public function __construct(private readonly ModuleRegistry $registry) {}

    public function handle(string $moduleId, string $tenantId, ?Environment $environment = null): ModuleInstallation
    {
        $target = $environment ?? $this->productionEnvironment($tenantId);

        if (! $target instanceof Environment) {
            return $this->apply($moduleId, $tenantId);
        }

        return app(EnvironmentConnection::class)->runWithin(
            $target,
            fn (): ModuleInstallation => $this->apply($moduleId, $tenantId),
        );
    }

    private function productionEnvironment(string $tenantId): ?Environment
    {
        return Environment::query()
            ->where('tenant_id', $tenantId)
            ->where('kind', 'production')
            ->whereNull('deleted_at')
            ->first();
    }

    /**
     * Catatan pemasangan tinggal di database lingkungan, bukan di database pusat, jadi
     * mencabut module menuntut koneksi yang benar lebih dulu. Alasan lengkapnya di
     * {@see InstallModule}.
     */
    private function apply(string $moduleId, string $tenantId): ModuleInstallation
    {
        $installation = ModuleInstallation::query()
            ->where('tenant_id', $tenantId)
            ->where('module_id', $moduleId)
            ->first();

        if ($installation === null) {
            throw new RuntimeException(sprintf('Module "%s" tidak terpasang pada tenant ini.', $moduleId));
        }

        $this->ensureNoOtherModuleNeedsIt($moduleId, $tenantId);

        DB::table('core_module_installations')
            ->where('tenant_id', $tenantId)
            ->where('module_id', $moduleId)
            ->update([
                'status' => ModuleInstallation::STATUS_UNINSTALLED,
                'uninstalled_at' => now(),
                'updated_at' => now(),
            ]);

        // Bukan `$installation->fresh()`. Model ini berkunci gabungan dan karena itu tidak
        // punya primary key tunggal; `fresh()` membangun query dari primary key dan
        // mengembalikan baris yang salah tanpa satu pun peringatan.
        return $this->read($moduleId, $tenantId);
    }

    /**
     * Penelusuran balik yang dipotong dengan catatan pemasangan tenant ini.
     *
     * Katalog tahu module mana bergantung pada module mana, tapi pertanyaannya bukan itu.
     * Pertanyaannya: adakah module yang **tenant ini** pakai dan akan rusak bila module ini
     * dicabut. Module yang bergantung tapi tidak dimiliki tenant ini tidak menghalangi apa
     * pun.
     */
    private function ensureNoOtherModuleNeedsIt(string $moduleId, string $tenantId): void
    {
        $installed = ModuleInstallation::query()
            ->where('tenant_id', $tenantId)
            ->where('status', ModuleInstallation::STATUS_INSTALLED)
            ->pluck('module_id')
            ->all();

        $blockers = [];

        foreach ($installed as $other) {
            if ($other === $moduleId) {
                continue;
            }

            $manifest = $this->registry->cari((string) $other);

            if ($manifest !== null && in_array($moduleId, $manifest->dependency, true)) {
                $blockers[] = (string) $other;
            }
        }

        if ($blockers !== []) {
            sort($blockers);

            throw new RuntimeException(sprintf(
                'Module "%s" masih dibutuhkan %s yang terpasang pada tenant ini. Cabut module itu lebih dulu.',
                $moduleId,
                implode(', ', $blockers),
            ));
        }
    }

    private function read(string $moduleId, string $tenantId): ModuleInstallation
    {
        /** @var ModuleInstallation $installation */
        $installation = ModuleInstallation::query()
            ->where('tenant_id', $tenantId)
            ->where('module_id', $moduleId)
            ->firstOrFail();

        return $installation;
    }
}
