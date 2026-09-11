<?php

declare(strict_types=1);

namespace App\Services\Modules;

use App\Models\FiscalPeriod;
use App\Models\LegalEntity;
use App\Support\Modules\Contracts\KalenderFiskal;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Menerjemahkan id entitas legal menjadi periode fiskal yang bisa dipakai module.
 *
 * Penerjemahan inilah alasan pembungkus ini ada. Tanpanya, module harus mengambil objek
 * `LegalEntity` lebih dulu — dan begitu ia menyentuh model Core, batas yang dibuat kontrak
 * ini kembali kabur.
 *
 * **Bentuk jawabannya sengaja sama dengan yang dulu dikirim endpoint HTTP**, bukan sama dengan
 * yang dipulangkan `FiscalCalendarService::resolve()`. Keduanya berbeda: layanan memulangkan
 * bentuk datar tanpa tanggal (`year_id`, `year_name`, …), sementara yang benar-benar dipakai
 * module adalah tanggal mulai tahun bukunya — dan itu hanya ada pada jawaban endpoint.
 *
 * Kontrak versi pertama memakai bentuk layanan, dan itu keliru: ia dibangun dari apa yang
 * kebetulan tersedia, bukan dari apa yang dibutuhkan pemakainya. Akibatnya baru terlihat pada
 * F3-07, ketika dasar tahun fiskal diam-diam jatuh kembali ke tahun kalender — tanpa satu pun
 * kesalahan, hanya angka yang berbeda.
 */
final class KalenderFiskalCore implements KalenderFiskal
{
    public function periode(string $legalEntityId, string $tanggal): array
    {
        $entitas = LegalEntity::query()
            ->whereKey($legalEntityId)
            ->first(['organization_id', 'fiscal_calendar_id']);

        // Dua keadaan yang sengaja dibedakan, dan bedanya bukan gaya.
        //
        // Entitas legal yang **tidak ada** adalah kesalahan pemanggil: module mengirim id yang
        // tidak pernah sah. Itu `RuntimeException`, dan pembungkus module membiarkannya lewat.
        //
        // Entitas legal yang ada tetapi **belum punya kalender** adalah keadaan data yang wajar
        // pada tenant yang belum selesai disiapkan. Itu `ValidationException`, dan pembungkus
        // module menerjemahkannya menjadi `null` — persis seperti 404 dari endpoint dulu.
        if ($entitas === null) {
            throw new RuntimeException(sprintf('Entitas legal %s tidak ditemukan.', $legalEntityId));
        }

        if ($entitas->fiscal_calendar_id === null) {
            throw ValidationException::withMessages([
                'scope' => 'Entitas legal ini belum memiliki kalender fiskal.',
            ]);
        }

        $periode = FiscalPeriod::query()
            ->whereHas('year', fn ($query) => $query->where('fiscal_calendar_id', $entitas->fiscal_calendar_id))
            ->whereDate('starts_on', '<=', $tanggal)
            ->whereDate('ends_on', '>=', $tanggal)
            ->with('year.calendar:id,code,name')
            ->first();

        if ($periode === null) {
            throw ValidationException::withMessages([
                'scope' => 'Tanggal tersebut belum tercakup pada kalender tahun buku perusahaan ini.',
            ]);
        }

        return [
            'calendar' => [
                'id' => $periode->year->calendar->id,
                'code' => $periode->year->calendar->code,
                'name' => $periode->year->calendar->name,
            ],
            'year' => [
                'id' => $periode->year->id,
                'name' => $periode->year->name,
                'starts_on' => $periode->year->starts_on->toDateString(),
                'ends_on' => $periode->year->ends_on->toDateString(),
            ],
            'period' => [
                'id' => $periode->id,
                'ordinal' => $periode->ordinal,
                'name' => $periode->name,
                'starts_on' => $periode->starts_on->toDateString(),
                'ends_on' => $periode->ends_on->toDateString(),
            ],
        ];
    }
}
