<?php

declare(strict_types=1);

namespace App\Actions\Modules;

use App\Actions\NumberSequence\EnsureNumberSequenceDrafts;
use App\Models\Environment;
use App\Models\ModuleInstallation;
use App\Support\Modules\ModuleManifest;
use App\Support\Modules\ModuleMigrator;
use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\ModuleSeeder;
use App\Support\Pusat\KoneksiLingkungan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Memasang sebuah module untuk satu tenant.
 *
 * Urutannya tidak boleh ditukar. Migration lebih dulu, karena seed menulis ke tabel yang
 * dibuatnya. Catatan pemasangan sebelum seed, karena seed membaca `seeded_at` dari catatan
 * itu untuk memutuskan apakah ia perlu berjalan. Urutan nomor sebelum seed, karena seed
 * menerbitkan nomor sungguhan.
 *
 * Memasang module yang sudah terpasang **bukan kesalahan**. Ia mengembalikan status ke
 * terpasang tanpa menyentuh data dan tanpa mengisi ulang data awal, sehingga perintah ini
 * aman dijalankan dua kali — sifat yang dibutuhkan admin pelanggan yang menjalankan
 * pembaruan on-prem dengan tangan.
 *
 * ## Yang dipasang bukan tenant, melainkan lingkungannya
 *
 * Entitlement milik tenant: ia yang menentukan module apa yang **boleh** ada. Pemasangan milik
 * lingkungan: ia yang menentukan module apa yang **benar-benar** ada di satu tempat kerja. Sebuah
 * tenant dengan produksi dan demo punya dua database, dan pertanyaan "HR terpasang?" punya dua
 * jawaban yang berbeda di sana — karena migrationnya memang berjalan atau tidak berjalan di dua
 * tempat terpisah.
 *
 * Karena itu `core_module_installations` tidak diberi kolom `environment_id`. Ia justru **tinggal
 * di database lingkungan itu sendiri**, berdampingan dengan riwayat migration yang ia gambarkan.
 * Dua alasan, dan yang kedua yang menentukan:
 *
 * 1. `environment:copy` menyalin database secara fisik, sehingga catatan pemasangan ikut apa adanya
 *    tanpa satu baris kode pun — dan ia memang sudah memeriksa jumlahnya sesudah restore.
 * 2. `seeded_at` dan riwayat migration harus sepakat. Kalau catatannya di database pusat sementara
 *    tabelnya di database lingkungan, sebuah restore dari cadangan yang lebih tua membuat keduanya
 *    berselisih diam-diam: catatannya bilang sudah disemai, tabelnya kosong, dan seed tidak pernah
 *    berjalan lagi. Satu database membuat keduanya berhasil atau gagal bersama.
 *
 * Menyebut lingkungan itu **opsional**, dan kosong berarti lingkungan produksi tenant ini. Produksi
 * hari ini `database_name`-nya kosong — yaitu database bawaan — sehingga jalur tanpa penyebutan
 * berjalan persis seperti sebelum pemisahan ini ada.
 */
final class InstallModule
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly ModuleMigrator $migrator,
        private readonly ModuleSeeder $seeder,
        private readonly EnsureNumberSequenceDrafts $urutanNomor,
        private readonly KoneksiLingkungan $koneksi,
    ) {}

    public function handle(string $moduleId, string $tenantId, ?Environment $lingkungan = null): ModuleInstallation
    {
        $tujuan = $lingkungan ?? $this->produksi($tenantId);

        if (! $tujuan instanceof Environment) {
            // Tenant tanpa satu pun baris registry. Itu keadaan pemasangan lama yang belum
            // di-backfill, dan jawabannya database bawaan — sama seperti sebelum lingkungan ada.
            return $this->pasang($moduleId, $tenantId);
        }

        return $this->koneksi->jalankanDi(
            $tujuan,
            fn (): ModuleInstallation => $this->pasang($moduleId, $tenantId),
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

    private function pasang(string $moduleId, string $tenantId): ModuleInstallation
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

        // Urutan nomor tenant untuk module ini, dan ini menutup lubang yang tidak terlihat
        // sampai module pertama benar-benar dipasang dari dalam runtime.
        //
        // Satu-satunya jalur yang membuat urutan nomor sebuah app — `forReadyTenant` —
        // menuntut adanya baris `app_placements` yang berstatus siap. Baris itu milik app
        // berkontainer; module yang berjalan di dalam runtime ini tidak punya penempatan sama
        // sekali, jadi jalur itu diam-diam tidak menemukan apa-apa dan tidak membuat satu pun
        // urutan. Tenant yang membeli module lalu berakhir tanpa nomor dokumen, dan yang
        // pertama menemukannya bukan pemasangan melainkan dokumen pertama yang gagal disimpan
        // dengan "Sequence aktif tidak ditemukan untuk aplikasi dan tenant ini".
        //
        // Letaknya sebelum seed karena seed module menerbitkan nomor sungguhan.
        $this->urutanNomor->forTenantAndApp($tenantId, $module->id);

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
