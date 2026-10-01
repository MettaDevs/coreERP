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
    public function __construct(private readonly MoneyPrecision $precision) {}

    public function amountDecimals(string $tenantId, string $currencyCode): int
    {
        return $this->precision->amountDecimals($tenantId, $currencyCode);
    }

    public function unitAmountDecimals(string $tenantId, string $currencyCode): int
    {
        return $this->precision->unitAmountDecimals($tenantId, $currencyCode);
    }

    public function roundAmount(string $tenantId, string|int|float $value, string $currencyCode): string
    {
        return $this->precision->roundAmount($tenantId, $value, $currencyCode);
    }
}
