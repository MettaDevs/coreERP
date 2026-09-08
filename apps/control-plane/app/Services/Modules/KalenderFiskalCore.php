<?php

declare(strict_types=1);

namespace App\Services\Modules;

use App\Actions\FiscalCalendar\FiscalCalendarService;
use App\Models\LegalEntity;
use App\Support\Modules\Contracts\KalenderFiskal;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Menerjemahkan id entitas legal menjadi objek yang dituntut layanan Core.
 *
 * Penerjemahan inilah alasan pembungkus ini ada. Tanpanya, module harus mengambil objek
 * `LegalEntity` lebih dulu — dan begitu ia menyentuh model Core, batas yang dibuat kontrak
 * ini kembali kabur.
 */
final class KalenderFiskalCore implements KalenderFiskal
{
    public function __construct(private readonly FiscalCalendarService $layanan) {}

    public function periode(string $legalEntityId, string $tanggal): array
    {
        $legalEntity = LegalEntity::query()->find($legalEntityId);

        if ($legalEntity === null) {
            throw new RuntimeException(sprintf('Entitas legal %s tidak ditemukan.', $legalEntityId));
        }

        return $this->layanan->resolve($legalEntity, Carbon::parse($tanggal));
    }
}
