<?php

declare(strict_types=1);

namespace Modules\Apperp\ContohB;

use Illuminate\Support\ServiceProvider;

/**
 * Penyedia layanan module. Ia yang memutuskan apa yang dimuat, bukan Core.
 *
 * Module kedua ini ada supaya penjaga batas punya dua module untuk dibandingkan. Ia sengaja
 * dibuat sama bentuknya dengan module pertama: bila memuat rute module memerlukan sesuatu
 * yang khas per module, bentuk yang sama pada dua module akan langsung memperlihatkannya.
 */
final class ModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // `loadRoutesFrom` menghormati cache rute; memanggil Route::group di sini tidak.
        $this->loadRoutesFrom(dirname(__DIR__).'/routes/web.php');
    }
}
