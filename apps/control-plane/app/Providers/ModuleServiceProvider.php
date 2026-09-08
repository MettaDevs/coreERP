<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Modules\CoreServices;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Menyalakan module yang ditemukan registry.
 *
 * Yang didaftarkan di sini adalah **penyedia layanan milik tiap module**, bukan rute dan
 * view-nya secara langsung. Bedanya bukan selera: paket module Laravel yang beredar
 * diketahui melambat justru karena setiap module mendaftarkan rutenya sendiri ke penyedia
 * pusat, sehingga jumlah pekerjaan saat menyalakan aplikasi tumbuh seiring jumlah module.
 * Satu penyedia per module membuat module memutuskan sendiri apa yang perlu dimuat.
 *
 * Module yang belum memiliki penyedia layanan dilewati tanpa suara. Pada fase ini module
 * contoh memang belum punya; yang penting registry sudah menemukannya.
 */
final class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Permukaan Core yang boleh dipanggil module. Daftarnya ada di CoreServices, dan
        // menambah barisnya adalah keputusan arsitektur, bukan kenyamanan.
        CoreServices::daftarkan($this->app);

        $this->app->singleton(ModuleRegistry::class, static fn (): ModuleRegistry => new ModuleRegistry(
            dirname(base_path(), 2).'/modules',
        ));

        foreach ($this->app->make(ModuleRegistry::class)->semua() as $module) {
            $penyedia = $module->penyediaLayanan();

            if (class_exists($penyedia)) {
                $this->app->register($penyedia);
            }
        }
    }
}
