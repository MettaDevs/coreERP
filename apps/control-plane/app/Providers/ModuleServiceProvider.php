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
 * Module yang belum memiliki penyedia layanan dilewati tanpa suara. Sejak F2-10 kedua module
 * contoh sudah punya, dan penyedia layanan itulah yang memuat rute module beserta middleware
 * konteksnya. Rute module tidak pernah dimuat dari sini: kalau Core yang memuatnya, Core
 * harus tahu id tiap module untuk memasang middleware konteks yang benar, dan itu kembali
 * menjadi daftar terpusat yang justru dihindari registry.
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
