<?php

declare(strict_types=1);

namespace App\Platform\Modules\Support;

use App\Platform\Modules\Models\ModuleInstallation;
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
    public function __construct(private readonly Container $container) {}

    /**
     * @return bool true bila data awal benar-benar diisi pada pemanggilan ini
     */
    public function jalankan(ModuleManifest $module, string $tenantId): bool
    {
        $installation = ModuleInstallation::query()
            ->where('tenant_id', $tenantId)
            ->where('module_id', $module->id)
            ->first();

        if ($installation === null || $installation->isSeeded()) {
            return false;
        }

        $class = $this->seederClass($module);

        // Tenant aktif dipasang selama seed berjalan supaya model module tersaring seperti
        // biasa. Tanpa ini, TenantScope membatalkan setiap query — dan memang harus begitu.
        $previous = $this->container->bound(TenantScope::KEY) ? $this->container->make(TenantScope::KEY) : null;
        $this->container->instance(TenantScope::KEY, $tenantId);

        try {
            foreach ($class as $name) {
                /** @var Seeder $seeder */
                $seeder = $this->container->make($name);
                $seeder->setContainer($this->container)->__invoke();
            }
        } finally {
            if (is_string($previous)) {
                $this->container->instance(TenantScope::KEY, $previous);
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
    public function seederClass(ModuleManifest $module): array
    {
        $folder = $module->folder.'/database/seeders';

        if (! is_dir($folder)) {
            return [];
        }

        $class = [];

        foreach (glob($folder.'/*.php') ?: [] as $file) {
            $name = sprintf(
                'Modules\\%s\\%s\\Database\\Seeders\\%s',
                $this->studly($module->penerbit),
                $this->studly(basename($module->folder)),
                pathinfo($file, PATHINFO_FILENAME),
            );

            if (class_exists($name) && is_subclass_of($name, Seeder::class)) {
                /** @var class-string<Seeder> $name */
                $class[] = $name;
            }
        }

        sort($class);

        return $class;
    }

    private function studly(string $name): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $name)));
    }
}
