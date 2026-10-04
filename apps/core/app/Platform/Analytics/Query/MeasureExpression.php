<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query;

use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Analytics\Datasets\CompiledMeasure;
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
 *
 * Saringan tetap measure (`where` di definisi) menjadi `FILTER (WHERE …)` pada panggilan agregatnya.
 * Nilainya placeholder `?`; pemanggil memasang {@see self::bindings()} pada bagian query tempat ekspresi
 * ini dipakai (`select`, atau `order` untuk kunci urutan), dalam urutan yang sama.
 */
final readonly class MeasureExpression implements Expression
{
    /**
     * @param  ?string  $column  berkualifikasi, atau null untuk menghitung baris
     * @param  list<array{0: string, 1: list<string|int|bool|null>}>  $conditions  kolom berkualifikasi dan
     *                                                                             nilai yang sah, digabung DAN
     */
    public function __construct(
        private Aggregate $aggregate,
        private ?string $column,
        private array $conditions = [],
    ) {}

    public static function for(CompiledDataset $dataset, CompiledMeasure $measure): self
    {
        $conditions = [];
        foreach ($measure->where as $key => $values) {
            $conditions[] = [$dataset->qualified($key), is_array($values) ? $values : [$values]];
        }

        return new self($measure->aggregate, $measure->field === null ? null : $dataset->qualified($measure->field), $conditions);
    }

    public function getValue(Grammar $grammar): string
    {
        $column = $this->column === null ? '*' : $grammar->wrap($this->column);

        $call = match ($this->aggregate) {
            Aggregate::Count => "count({$column})",
            Aggregate::CountDistinct => "count(distinct {$column})",
            Aggregate::Sum => "sum({$column})",
            Aggregate::Average => "avg({$column})",
            Aggregate::Minimum => "min({$column})",
            Aggregate::Maximum => "max({$column})",
        };

        if ($this->conditions !== []) {
            $parts = [];
            foreach ($this->conditions as [$condition, $values]) {
                $wrapped = $grammar->wrap($condition);
                $present = count(array_filter($values, static fn (mixed $value): bool => $value !== null));
                $part = [];
                if ($present > 0) {
                    $part[] = $wrapped.' in ('.implode(', ', array_fill(0, $present, '?')).')';
                }
                if ($present < count($values)) {
                    $part[] = $wrapped.' is null';
                }
                $parts[] = count($part) === 1 ? $part[0] : '('.implode(' or ', $part).')';
            }
            // FILTER melekat pada panggilan agregat, jadi ia ditulis sebelum `coalesce` di bawah.
            $call .= ' filter (where '.implode(' and ', $parts).')';
        }

        // Kelompok tanpa baris bernilai nol, bukan kosong; rata-rata, terkecil, dan terbesar tetap kosong.
        return $this->aggregate === Aggregate::Sum ? "coalesce({$call}, 0)" : $call;
    }

    /** Agregat yang dapat bernilai kosong untuk kelompok tanpa baris yang memenuhi. */
    public function nullable(): bool
    {
        return in_array($this->aggregate, [Aggregate::Average, Aggregate::Minimum, Aggregate::Maximum], true);
    }

    /**
     * Nilai saringan tetap, dalam urutan placeholder di {@see self::getValue()}.
     *
     * @return list<string|int|bool>
     */
    public function bindings(): array
    {
        $bindings = [];
        foreach ($this->conditions as [, $values]) {
            foreach ($values as $value) {
                if ($value !== null) {
                    $bindings[] = $value;
                }
            }
        }

        return $bindings;
    }
}
