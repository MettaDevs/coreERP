<?php

declare(strict_types=1);

namespace Modules\Apperp\ContohA;

use Illuminate\Support\ServiceProvider;

/**
 * Penyedia layanan module. Ia yang memutuskan apa yang dimuat, bukan Core.
 *
 * Sampai F2-10 berkas rute module tidak dimuat siapa pun. Yang memuatnya sekarang adalah
 * berkas ini, dan itu bukan sekadar pemindahan tanggung jawab: middleware konteks module
 * menerima id module sebagai parameter, sehingga hanya module itu sendiri yang tahu nilai
 * yang benar. Kalau Core yang memuat semua rute module, Core harus menyimpan daftar id
 * module beserta rutenya — persis daftar terpusat yang dihindari registry pemindai folder.
 */
final class ModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // `loadRoutesFrom` menghormati cache rute; memanggil Route::group di sini tidak.
        $this->loadRoutesFrom(dirname(__DIR__).'/routes/web.php');
    }
}
