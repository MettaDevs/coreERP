<?php

declare(strict_types=1);

namespace App\Platform\Modules\Contracts\Analytics;

/**
 * Daftar penerjemah label dimensi bersama, satu per {@see SharedDimension}. Fitur Foundation pemilik
 * datanya mendaftar sekali saat boot dari penyedia layanannya:
 *
 *     $this->app->make(SharedDimensions::class)->register(new VendorLabels);
 *
 * Diikat sebagai singleton di `CoreServices::SINGLETON_BINDINGS`, dengan alasan yang sama seperti
 * {@see Datasets}: diikat biasa, setiap pendaftaran masuk ke salinan yang langsung dibuang. Dimensi
 * tanpa resolver bukan kesalahan; nilainya tampil apa adanya.
 */
interface SharedDimensions
{
    public function register(SharedDimensionResolver $resolver): void;

    public function for(SharedDimension $dimension): ?SharedDimensionResolver;
}
