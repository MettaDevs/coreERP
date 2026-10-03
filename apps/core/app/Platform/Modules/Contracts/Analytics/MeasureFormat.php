<?php

declare(strict_types=1);

namespace App\Platform\Modules\Contracts\Analytics;

/** Cara nilai measure ditampilkan. Nama nilainya sama dengan tipe nilai laporan (`ValueFormat`). */
enum MeasureFormat: string
{
    case Number = 'number';
    /** Wajib membawa kolom mata uang; lihat {@see DatasetDefinition::measure()}. */
    case Money = 'money';
    case Percent = 'percent';
    /** Wajib membawa kolom satuan. */
    case Quantity = 'quantity';
    /** Dalam jam, seperti KPI pemeliharaan. */
    case Hours = 'hours';
}
