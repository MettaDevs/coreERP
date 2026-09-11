<?php

declare(strict_types=1);

namespace Modules\PenerbitContoh\ChangeMe;

use Illuminate\Support\ServiceProvider;

/**
 * Penyedia layanan module. Ia yang memutuskan apa yang dimuat, bukan Core.
 *
 * Ini satu-satunya pintu masuk module ke runtime. Ia tidak didaftarkan di `config/app.php` —
 * daftar provider dimiliki Core — melainkan ditemukan Core dari manifest module.
 *
 * Rute dimuat di sini, bukan oleh Core, karena middleware `konteks-module` menerima id module
 * sebagai parameter: hanya module ini sendiri yang tahu nilai yang benar. Kalau Core yang
 * memuat semua rute module, Core harus menyimpan daftar id module beserta rutenya — persis
 * daftar terpusat yang dihindari registry pemindai folder.
 */
final class ModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // `loadRoutesFrom` menghormati cache rute; memanggil Route::group di sini tidak.
        $this->loadRoutesFrom(dirname(__DIR__).'/routes/web.php');
    }
}
