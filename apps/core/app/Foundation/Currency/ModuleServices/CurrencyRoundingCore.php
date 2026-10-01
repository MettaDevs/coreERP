<?php

declare(strict_types=1);

namespace App\Foundation\Currency\ModuleServices;

use App\Foundation\Currency\Support\MoneyPrecision;
use App\Platform\Modules\Contracts\CurrencyRounding;

/**
 * Meneruskan presisi dan pembulatan uang dari module ke Core, di proses yang sama.
 *
 * Pembungkusnya ada supaya module bergantung pada antarmuka, bukan pada kelas Core yang bebas
 * berubah bentuk; hitungannya tetap satu, di `MoneyPrecision`.
 */
final class CurrencyRoundingCore implements CurrencyRounding
{
    public function __construct(private readonly MoneyPrecision $presisi) {}

    public function amountDecimals(string $tenantId, string $kodeMataUang): int
    {
        return $this->presisi->amountDecimals($tenantId, $kodeMataUang);
    }

    public function unitAmountDecimals(string $tenantId, string $kodeMataUang): int
    {
        return $this->presisi->unitAmountDecimals($tenantId, $kodeMataUang);
    }

    public function roundAmount(string $tenantId, string|int|float $nilai, string $kodeMataUang): string
    {
        return $this->presisi->roundAmount($tenantId, $nilai, $kodeMataUang);
    }
}
