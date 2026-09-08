<?php

declare(strict_types=1);

namespace App\Support\Modules;

use App\Models\ModuleInstallation;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Seeder;

/**
 * Mengisi data awal sebuah module untuk satu tenant, tepat sekali seumur pemasangan.
 *
 * Dua aturan yang menopang seluruh model berlangganan:
 *
 * 1. **Seed module hanya dipanggil pemasangan module,** tidak pernah oleh `db:seed` global.
 *    Kalau ia ikut `db:seed`, memasang satu module akan mengisi data module lain yang belum
 *    dibeli siapa pun, dan pelanggan melihat master milik produk yang tidak ia bayar.
 * 2. **Kolom `seeded_at` yang memutuskan,** bukan ada tidaknya baris. Pelanggan yang
 *    menonaktifkan module lalu mengaktifkannya lagi sudah punya data; mengisi ulang berarti
 *    master bawaan menjadi dobel, dan yang menghapus dobelnya harus menebak mana yang asli.
 */
final class ModuleSeeder
{
    public function __construct(private readonly Container $wadah) {}

    /**
     * @return bool true bila data awal benar-benar diisi pada pemanggilan ini
     */
    public function jalankan(ModuleManifest $module, string $tenantId): bool
    {
        $pemasangan = ModuleInstallation::query()
            ->where('tenant_id', $tenantId)
            ->where('module_id', $module->id)
            ->first();

        if ($pemasangan === null || $pemasangan->sudahDiisiDataAwal()) {
            return false;
        }

        $kelas = $this->kelasSeeder($module);

        // Tenant aktif dipasang selama seed berjalan supaya model module tersaring seperti
        // biasa. Tanpa ini, TenantScope membatalkan setiap query — dan memang harus begitu.
        $sebelumnya = $this->wadah->bound(TenantScope::KUNCI) ? $this->wadah->make(TenantScope::KUNCI) : null;
        $this->wadah->instance(TenantScope::KUNCI, $tenantId);

        try {
            foreach ($kelas as $nama) {
                /** @var Seeder $seeder */
                $seeder = $this->wadah->make($nama);
                $seeder->setContainer($this->wadah)->__invoke();
            }
        } finally {
            if (is_string($sebelumnya)) {
                $this->wadah->instance(TenantScope::KUNCI, $sebelumnya);
            }
        }

        ModuleInstallation::query()
            ->where('tenant_id', $tenantId)
            ->where('module_id', $module->id)
            ->update(['seeded_at' => now(), 'updated_at' => now()]);

        return true;
    }

    /**
     * Kelas seeder milik satu module, ditemukan dari foldernya.
     *
     * @return list<class-string<Seeder>>
     */
    public function kelasSeeder(ModuleManifest $module): array
    {
        $folder = $module->folder.'/database/seeders';

        if (! is_dir($folder)) {
            return [];
        }

        $kelas = [];

        foreach (glob($folder.'/*.php') ?: [] as $berkas) {
            $nama = sprintf(
                'Modules\\%s\\%s\\Database\\Seeders\\%s',
                $this->studly($module->penerbit),
                $this->studly(basename($module->folder)),
                pathinfo($berkas, PATHINFO_FILENAME),
            );

            if (class_exists($nama) && is_subclass_of($nama, Seeder::class)) {
                /** @var class-string<Seeder> $nama */
                $kelas[] = $nama;
            }
        }

        sort($kelas);

        return $kelas;
    }

    private function studly(string $nama): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $nama)));
    }
}
