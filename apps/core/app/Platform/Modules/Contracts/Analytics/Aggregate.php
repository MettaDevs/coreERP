<?php

declare(strict_types=1);

namespace App\Platform\Modules\Contracts\Analytics;

/**
 * Cara sebuah measure dihitung. Daftar yang sama dengan pilihan agregasi Generic Chart BC.
 *
 * Nilai string-nya tersimpan di widget dan query tenant; menggantinya memutus data yang sudah ada.
 */
enum Aggregate: string
{
    /** Jumlah baris; dengan field, jumlah baris yang field-nya terisi. */
    case Count = 'count';
    case CountDistinct = 'count_distinct';
    case Sum = 'sum';
    case Average = 'avg';
    case Minimum = 'min';
    case Maximum = 'max';

    /**
     * Dapat dihitung ulang dari ringkasan yang lebih halus (fase 3). Rata-rata dan jumlah unik
     * tidak: rata-rata dari rata-rata dan jumlah dari jumlah unik keduanya salah.
     */
    public function rollsUp(): bool
    {
        return in_array($this, [self::Count, self::Sum, self::Minimum, self::Maximum], true);
    }
}
