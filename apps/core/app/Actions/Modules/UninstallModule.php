<?php

declare(strict_types=1);

namespace App\Actions\Modules;

use App\Models\Environment;
use App\Models\ModuleInstallation;
use App\Support\Modules\ModuleRegistry;
use App\Support\Pusat\KoneksiLingkungan;
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

    public function handle(string $moduleId, string $tenantId, ?Environment $lingkungan = null): ModuleInstallation
    {
        $tujuan = $lingkungan ?? $this->produksi($tenantId);

        if (! $tujuan instanceof Environment) {
            return $this->kerjakan($moduleId, $tenantId);
        }

        return app(KoneksiLingkungan::class)->jalankanDi(
            $tujuan,
            fn (): ModuleInstallation => $this->kerjakan($moduleId, $tenantId),
        );
    }

    private function produksi(string $tenantId): ?Environment
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
    private function kerjakan(string $moduleId, string $tenantId): ModuleInstallation
    {
        $pemasangan = ModuleInstallation::query()
            ->where('tenant_id', $tenantId)
            ->where('module_id', $moduleId)
            ->first();

        if ($pemasangan === null) {
            throw new RuntimeException(sprintf('Module "%s" tidak terpasang pada tenant ini.', $moduleId));
        }

        $this->pastikanTidakDibutuhkanModuleLain($moduleId, $tenantId);

        DB::table('core_module_installations')
            ->where('tenant_id', $tenantId)
            ->where('module_id', $moduleId)
            ->update([
                'status' => ModuleInstallation::STATUS_UNINSTALLED,
                'uninstalled_at' => now(),
                'updated_at' => now(),
            ]);

        // Bukan `$pemasangan->fresh()`. Model ini berkunci gabungan dan karena itu tidak
        // punya primary key tunggal; `fresh()` membangun query dari primary key dan
        // mengembalikan baris yang salah tanpa satu pun peringatan.
        return $this->baca($moduleId, $tenantId);
    }

    /**
     * Penelusuran balik yang dipotong dengan catatan pemasangan tenant ini.
     *
     * Katalog tahu module mana bergantung pada module mana, tapi pertanyaannya bukan itu.
     * Pertanyaannya: adakah module yang **tenant ini** pakai dan akan rusak bila module ini
     * dicabut. Module yang bergantung tapi tidak dimiliki tenant ini tidak menghalangi apa
     * pun.
     */
    private function pastikanTidakDibutuhkanModuleLain(string $moduleId, string $tenantId): void
    {
        $terpasang = ModuleInstallation::query()
            ->where('tenant_id', $tenantId)
            ->where('status', ModuleInstallation::STATUS_INSTALLED)
            ->pluck('module_id')
            ->all();

        $penghalang = [];

        foreach ($terpasang as $lain) {
            if ($lain === $moduleId) {
                continue;
            }

            $manifest = $this->registry->cari((string) $lain);

            if ($manifest !== null && in_array($moduleId, $manifest->dependency, true)) {
                $penghalang[] = (string) $lain;
            }
        }

        if ($penghalang !== []) {
            sort($penghalang);

            throw new RuntimeException(sprintf(
                'Module "%s" masih dibutuhkan %s yang terpasang pada tenant ini. Cabut module itu lebih dulu.',
                $moduleId,
                implode(', ', $penghalang),
            ));
        }
    }

    private function baca(string $moduleId, string $tenantId): ModuleInstallation
    {
        /** @var ModuleInstallation $pemasangan */
        $pemasangan = ModuleInstallation::query()
            ->where('tenant_id', $tenantId)
            ->where('module_id', $moduleId)
            ->firstOrFail();

        return $pemasangan;
    }
}
