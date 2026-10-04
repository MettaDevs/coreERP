<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query;

/**
 * Perbandingan periode (`compare` di query, fase 2): rentang waktu yang sama digeser sepanjang rentang itu
 * sendiri, atau digeser satu tahun. Aturan pergeserannya di {@see Comparison}.
 */
enum CompareMode: string
{
    case PreviousPeriod = 'previous_period';
    case PreviousYear = 'previous_year';

    /** Nama periode pembanding di judul kolom hasil. */
    public function caption(): string
    {
        return match ($this) {
            self::PreviousPeriod => 'periode sebelumnya',
            self::PreviousYear => 'tahun lalu',
        };
    }
}
