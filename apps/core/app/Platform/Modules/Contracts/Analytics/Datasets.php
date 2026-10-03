<?php

declare(strict_types=1);

namespace App\Platform\Modules\Contracts\Analytics;

/**
 * Tempat module mendaftarkan datasetnya, sekali saat boot dari penyedia layanannya:
 *
 *     $this->app->make(Datasets::class)->register($this->app->make(AssetRegisterDataset::class));
 *
 * Diikat sebagai singleton di `CoreServices::SINGLETON_BINDINGS`, sama seperti
 * `ModuleReportProviders`: diikat dengan `bind`, setiap pendaftaran masuk ke salinan yang langsung
 * dibuang dan Core melihat daftar kosong tanpa satu pun kesalahan.
 */
interface Datasets
{
    public function register(Dataset $dataset): void;
}
