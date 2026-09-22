<?php

declare(strict_types=1);

namespace App\Support\Finance;

use App\Models\FinancePostingSetting;
use App\Models\FinanceSettlementMode;

/**
 * Setelan feed posting finance per entitas legal: aktif atau tidak, cutover, dan mode penyelesaian.
 *
 * Dibaca penerbit posting di Core dan, lewat kontrak `SetelanPostingFinance`, oleh module. Entitas
 * legal yang belum pernah disetel dianggap feed tidak aktif, tanpa cutover, dan memakai mode
 * `direct_payable` (K-10).
 */
final class PostingSettings
{
    /**
     * Mode yang berlaku pada tanggal itu: baris dengan `effective_from` terbesar yang tidak
     * melewati tanggal tersebut.
     *
     * Koreksi atas perolehan lama tidak memanggil ini untuk tanggal koreksinya; ia mewarisi mode
     * yang tercatat di posting aslinya (K-10). Metode ini menjawab "mode apa untuk transaksi baru
     * bertanggal sekian".
     */
    public function settlementMode(string $legalEntityId, string $date): string
    {
        $mode = FinanceSettlementMode::query()
            ->where('legal_entity_id', $legalEntityId)
            ->whereDate('effective_from', '<=', $date)
            ->orderByDesc('effective_from')
            ->value('mode');

        return is_string($mode) ? $mode : FinanceSettlementMode::DEFAULT;
    }

    public function setting(string $legalEntityId): ?FinancePostingSetting
    {
        return FinancePostingSetting::query()->find($legalEntityId);
    }

    /** Tanggal cutover dalam bentuk `Y-m-d`, atau `null` bila belum disetel. */
    public function cutover(string $legalEntityId): ?string
    {
        return $this->setting($legalEntityId)?->cutover_date?->toDateString();
    }

    public function enabled(string $legalEntityId): bool
    {
        return $this->setting($legalEntityId)->enabled ?? false;
    }
}
