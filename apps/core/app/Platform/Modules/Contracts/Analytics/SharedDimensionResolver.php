<?php

declare(strict_types=1);

namespace App\Platform\Modules\Contracts\Analytics;

use App\Platform\Modules\Contracts\DataClass;

/**
 * Penerjemah nilai satu dimensi bersama menjadi label, dipanggil engine sesudah agregasi, sekali per
 * himpunan id. Pemilik datanya yang menulis resolver: Platform untuk entitas legal, unit kerja, dan
 * pengguna; fitur Foundation untuk vendor dan mata uang, didaftarkan dari penyedia layanannya sendiri
 * ke {@see SharedDimensions}, karena Platform tidak boleh menyebut Foundation.
 */
interface SharedDimensionResolver
{
    public function dimension(): SharedDimension;

    /**
     * Klasifikasi label yang dipulangkan. Label `EndUserIdentifiableInformation` (nama orang) hanya
     * diberikan kepada principal yang berhak membaca data pribadi; engine yang menegakkannya, bukan
     * resolver.
     */
    public function labelClassification(): DataClass;

    /**
     * Label untuk id yang ditanyakan, hanya dari tenant itu, termasuk record yang sudah tidak aktif
     * atau diarsipkan: dokumen lama tidak boleh kehilangan namanya. Id yang tidak dikenal dilewati,
     * dan layar menampilkan nilai mentahnya.
     *
     * @param  list<string>  $ids
     * @return array<int|string, string> id => label; id berbentuk angka (id pengguna) menjadi kunci bilangan
     *                                   bulat PHP, jadi bacalah dengan `$labels[$id] ?? null`, bukan dengan
     *                                   membandingkan kuncinya sebagai teks
     */
    public function labels(string $tenantId, array $ids): array;
}
