<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Modules\CoreServices;
use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\ModulSedangDipindah;
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

        // Penyedia layanan didaftarkan untuk **semua** module, termasuk yang sedang dipindah
        // masuk. "Belum boleh dipasang untuk tenant" tidak sama dengan "kodenya tidak boleh
        // dimuat": module yang kodenya tidak dimuat tidak punya satu pun test yang bisa
        // berjalan, dan pemindahannya jadi dikerjakan tanpa jaring pengaman sampai hari
        // terakhir. Katalog, pemasangan, dan segala yang menyentuh data tenant tetap memakai
        // `semua()`, yang melewatkan module yang sedang dipindah.
        foreach ($this->app->make(ModuleRegistry::class)->semuaTermasukYangSedangDipindah() as $module) {
            $penyedia = $module->penyediaLayanan();

            if (class_exists($penyedia)) {
                $this->app->register($penyedia);
            }
        }
    }

    public function boot(): void
    {
        // Migration module yang sedang dipindah dijalankan bersama migration Core.
        //
        // Module yang sudah jadi tidak begini: migrationnya dijalankan `ModuleMigrator` saat
        // module dipasang untuk sebuah tenant, dan dicatat per module supaya pencabutan bisa
        // dilacak. Module yang sedang dipindah belum boleh dipasang untuk tenant mana pun,
        // jadi tabelnya tidak punya cara lain untuk ada — dan tanpa tabel, tidak satu pun
        // testnya bisa berjalan.
        //
        // Ini berakhir sendiri: begitu module keluar dari daftar `ModulSedangDipindah`, ia
        // dipasang lewat jalur yang sama seperti module lain dan baris ini berhenti berlaku
        // untuknya.
        $dipindah = ModulSedangDipindah::bawaan();

        foreach ($this->app->make(ModuleRegistry::class)->semuaTermasukYangSedangDipindah() as $module) {
            if ($dipindah->menandai(basename($module->folder))) {
                $this->loadMigrationsFrom($module->folderMigrasi());
            }
        }
    }
}
