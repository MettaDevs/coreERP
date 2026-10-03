<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query;

use App\Platform\Modules\Contracts\Analytics\Aggregate;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;

/**
 * Ekspresi SQL satu measure, disusun grammar saat query dikompilasi: kolomnya dibungkus `wrap()`,
 * bukan disambung ke string mentah.
 *
 * Kenapa ekspresi dan bukan `selectRaw()`: metode SQL mentah Laravel hanya menerima `literal-string`,
 * dan analisa tipe menolak string yang memuat nama kolom dari definisi dataset. Nama kolomnya sudah
 * lolos pemeriksaan pengenal di registry, tetapi penjaganya tetap berarti: tidak ada potongan SQL dari
 * data yang masuk lewat pintu mentah. Ekspresi ini dipasang lewat `selectExpression()`.
 */
final readonly class MeasureExpression implements Expression
{
    /** `$column` berkualifikasi nama tabel dasar, atau null untuk menghitung baris. */
    public function __construct(
        private Aggregate $aggregate,
        private ?string $column,
    ) {}

    public function getValue(Grammar $grammar): string
    {
        $column = $this->column === null ? '*' : $grammar->wrap($this->column);

        return match ($this->aggregate) {
            Aggregate::Count => "count({$column})",
            Aggregate::CountDistinct => "count(distinct {$column})",
            // Kelompok tanpa baris bernilai nol, bukan kosong; rata-rata, terkecil, dan terbesar tetap kosong.
            Aggregate::Sum => "coalesce(sum({$column}), 0)",
            Aggregate::Average => "avg({$column})",
            Aggregate::Minimum => "min({$column})",
            Aggregate::Maximum => "max({$column})",
        };
    }
}
