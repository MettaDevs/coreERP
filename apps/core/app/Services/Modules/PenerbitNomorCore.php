<?php

declare(strict_types=1);

namespace App\Services\Modules;

use App\Actions\NumberSequence\NumberSequenceService;
use App\Support\Modules\Contracts\PenerbitNomor;

/**
 * Meneruskan penerbitan nomor ke layanan Core, di proses yang sama.
 *
 * Tidak ada penerjemahan yang perlu dilakukan di sini: layanan Core memang sudah menerima
 * konteks berupa id, bukan objek. Pembungkusnya tetap ada supaya module bergantung pada
 * antarmuka, bukan pada kelas Core yang bebas berubah bentuk.
 */
final class PenerbitNomorCore implements PenerbitNomor
{
    public function __construct(private readonly NumberSequenceService $layanan) {}

    public function terbitkan(array $konteks, string $kodeReferensi, string $kunciIdempoten, ?string $nilaiManual = null): array
    {
        return $this->layanan->issue($konteks, $kodeReferensi, $kunciIdempoten, $nilaiManual);
    }

    public function cadangkan(array $konteks, string $kodeReferensi, string $kunciIdempoten): array
    {
        return $this->layanan->reserve($konteks, $kodeReferensi, $kunciIdempoten);
    }
}
