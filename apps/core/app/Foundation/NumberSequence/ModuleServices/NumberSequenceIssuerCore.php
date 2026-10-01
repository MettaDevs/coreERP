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
    public function __construct(private readonly NumberSequenceService $service) {}

    public function issue(array $context, string $referenceCode, string $idempotencyKey, ?string $manualValue = null): array
    {
        return $this->service->issue($context, $referenceCode, $idempotencyKey, $manualValue);
    }

    public function reserve(array $context, string $referenceCode, string $idempotencyKey): array
    {
        return $this->service->reserve($context, $referenceCode, $idempotencyKey);
    }
}
