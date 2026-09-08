<?php

declare(strict_types=1);

namespace App\Support\Modules\Contracts;

/**
 * Menjawab periode fiskal sebuah tanggal.
 *
 * Layanan Core menerima objek entitas legal. Antarmuka ini menerima id-nya, dan pembungkusnya
 * yang menerjemahkan. Bedanya bukan gaya: module yang harus mengambil objek `LegalEntity`
 * lebih dulu sudah menyentuh model Core, dan batasnya kembali kabur.
 */
interface KalenderFiskal
{
    /** @return array<string, mixed> */
    public function periode(string $legalEntityId, string $tanggal): array;
}
