<?php

declare(strict_types=1);

namespace App\Platform\Modules\Contracts;

/**
 * Tempat module mendaftarkan daftar layar yang boleh diekspor (K-27), sekali saat boot, dari penyedia
 * layanannya:
 *
 *     $this->app->make(ListExportSources::class)->register($this->app->make(AssetRegisterList::class));
 *
 * Arahnya sama dengan {@see DaftarLaporan}: module yang melayani Core.
 */
interface ListExportSources
{
    public function register(ListExportSource $source): void;
}
