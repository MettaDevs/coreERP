<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Analytics;

use App\Platform\Analytics\External\CsvRows;
use Tests\TestCase;

class CsvRowsTest extends TestCase
{
    public function test_formula_prefixes_are_escaped_without_changing_negative_numbers(): void
    {
        $csv = CsvRows::render(['value'], [
            ['value' => "\t=1+1"],
            ['value' => "\r@SUM(A1)"],
            ['value' => "\n+1"],
            ['value' => '＝1+1'],
            ['value' => '+1'],
            ['value' => '-25.00'],
            ['value' => '-1+1'],
        ]);

        foreach (["'\t=1+1", "'\r@SUM(A1)", "'\n+1", "'＝1+1", "'+1", "'-1+1"] as $escaped) {
            self::assertStringContainsString($escaped, $csv);
        }
        self::assertStringContainsString("-25.00\n", $csv);
        self::assertStringNotContainsString("'-25.00", $csv);
    }
}
