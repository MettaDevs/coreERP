<?php

declare(strict_types=1);

namespace App\Foundation\NumberSequence\ModuleServices;

use App\Foundation\NumberSequence\Actions\NumberSequenceService;
use App\Platform\Modules\Contracts\NumberSequenceIssuer;

/**
 * Meneruskan penerbitan nomor ke layanan Core, di proses yang sama.
 *
 * Tidak ada penerjemahan yang perlu dilakukan di sini: layanan Core memang sudah menerima
 * konteks berupa id, bukan objek. Pembungkusnya tetap ada supaya module bergantung pada
 * antarmuka, bukan pada kelas Core yang bebas berubah bentuk.
 */
final class NumberSequenceIssuerCore implements NumberSequenceIssuer
{
    public function __construct(private readonly NumberSequenceService $layanan) {}

    public function issue(array $konteks, string $kodeReferensi, string $kunciIdempoten, ?string $nilaiManual = null): array
    {
        return $this->layanan->issue($konteks, $kodeReferensi, $kunciIdempoten, $nilaiManual);
    }

    public function reserve(array $konteks, string $kodeReferensi, string $kunciIdempoten): array
    {
        return $this->layanan->reserve($konteks, $kodeReferensi, $kunciIdempoten);
    }
}
