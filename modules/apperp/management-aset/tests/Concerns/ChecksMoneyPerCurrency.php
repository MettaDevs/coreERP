<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Tests\Concerns;

/**
 * Uang tidak pernah dijumlah lintas mata uang (KA-22): measure uang tanpa dimensi mata uang menghasilkan
 * satu baris per mata uang, bukan satu jumlah campuran. Dipakai test dataset yang punya measure uang,
 * bersama {@see ProbesAssetDatasets}.
 */
trait ChecksMoneyPerCurrency
{
    /** Kunci measure uang yang diperiksa. */
    abstract protected function moneyMeasure(): string;

    /**
     * Jumlah yang diharapkan untuk tenant A, per kode mata uang.
     *
     * @return array<string, string>
     */
    abstract protected function expectedMoney(): array;

    public function test_money_is_never_summed_across_currencies(): void
    {
        $measure = $this->moneyMeasure();
        $rows = $this->analyze($this->owner, ['dataset' => $this->datasetCode(), 'measures' => [$measure]])
            ->assertOk()
            ->assertJsonPath('columns.0', ['key' => 'currency_code', 'kind' => 'dimension', 'caption' => 'Mata uang', 'type' => 'text', 'implicit' => true])
            ->json('rows');

        $actual = [];
        foreach ($rows as $row) {
            $actual[(string) $row['currency_code']] = (string) $row[$measure];
        }
        ksort($actual);
        $expected = $this->expectedMoney();
        ksort($expected);

        $this->assertSame(array_keys($expected), array_keys($actual), 'Satu baris per mata uang, bukan satu jumlah campuran.');
        foreach ($expected as $currency => $total) {
            $this->assertDecimal($total, $actual[$currency], "Jumlah {$currency} seharusnya {$total}, bukan {$actual[$currency]}.");
        }
    }
}
