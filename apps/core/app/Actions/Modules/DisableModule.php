<?php

declare(strict_types=1);

namespace App\Actions\Modules;

use App\Models\Environment;
use App\Models\ModuleInstallation;
use App\Support\ControlPlane\EnvironmentConnection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Menonaktifkan module untuk satu tenant.
 *
 * Yang berubah hanya status dan `disabled_at`. **Data tidak disentuh sama sekali** — tidak
 * dihapus, tidak dipindah, tidak ditandai. Menu module hilang karena shell hanya membaca
 * module berstatus terpasang, bukan karena datanya hilang.
 *
 * Ini bentuk yang dipakai pelanggan yang berhenti sementara: berhenti membayar bulan ini,
 * kembali bulan depan, dan menemukan datanya persis seperti ditinggalkan.
 */
final class DisableModule
{
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
     * menonaktifkan module menuntut koneksi yang benar lebih dulu. Alasan lengkapnya di
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

        DB::table('core_module_installations')
            ->where('tenant_id', $tenantId)
            ->where('module_id', $moduleId)
            ->update([
                'status' => ModuleInstallation::STATUS_DISABLED,
                'disabled_at' => now(),
                'updated_at' => now(),
            ]);

        // Bukan `$installation->fresh()`. Model ini berkunci gabungan dan karena itu tidak
        // punya primary key tunggal; `fresh()` membangun query dari primary key dan
        // mengembalikan baris yang salah tanpa satu pun peringatan.
        return $this->read($moduleId, $tenantId);
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
