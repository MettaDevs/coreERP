<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query;

/**
 * Ukuran ember waktu untuk dimensi waktu (`{"field": …, "granularity": "month"}`). Minggu mulai Senin.
 *
 * Bagian dari bentuk query yang dibekukan area 0; pengelompokan waktunya sendiri dikerjakan area 2
 * (pembacaan) dan area 3 (SQL per jenis kolom waktu).
 */
enum TimeGranularity: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Quarter = 'quarter';
    case Year = 'year';
}
