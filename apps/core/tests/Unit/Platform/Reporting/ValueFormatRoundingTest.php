<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Reporting;

use App\Foundation\Currency\Support\MoneyPrecision;
use App\Platform\Reporting\Support\ValueFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Pembulatan tampilan laporan sama persis dengan pembulatan jurnal.
 *
 * Reporting di lapis Platform tidak boleh memanggil `MoneyPrecision` milik Foundation, jadi
 * `ValueFormat` membawa salinan aturannya sendiri. Test ini yang memastikan kedua salinan tidak
 * pernah berselisih: kalau satu berubah, yang lain wajib ikut.
 */
class ValueFormatRoundingTest extends TestCase
{
    /** @return iterable<string, array{string|int|float, int}> */
    public static function values(): iterable
    {
        yield 'tengah ke atas' => ['0.005', 2];
        yield 'tengah negatif menjauhi nol' => ['-0.005', 2];
        yield 'bulat' => [1234567, 2];
        yield 'tanpa desimal' => ['1234567.50', 0];
        yield 'spasi di tepi' => [' 10.125 ', 2];
        yield 'float besar' => [987654321.985, 2];
        yield 'float kecil' => [0.1 + 0.2, 2];
        yield 'float negatif' => [-2.675, 2];
        yield 'presisi empat' => ['1.23455', 4];
        yield 'skala lebih kecil' => ['5', 3];
    }

    #[DataProvider('values')]
    public function test_display_rounding_matches_money_precision(string|int|float $value, int $decimals): void
    {
        $round = new ReflectionMethod(ValueFormat::class, 'round');

        $this->assertSame(MoneyPrecision::round($value, $decimals), $round->invoke(null, $value, $decimals));
    }
}
