<?php

declare(strict_types=1);

namespace App\Services\Modules;

use App\Support\Finance\MoneyPrecision;
use App\Support\Modules\Contracts\PresisiMataUang;

/**
 * Meneruskan presisi dan pembulatan uang dari module ke Core, di proses yang sama.
 *
 * Pembungkusnya ada supaya module bergantung pada antarmuka, bukan pada kelas Core yang bebas
 * berubah bentuk; hitungannya tetap satu, di `MoneyPrecision`.
 */
final class PresisiMataUangCore implements PresisiMataUang
{
    public function __construct(private readonly MoneyPrecision $presisi) {}

    public function nilai(string $tenantId, string $kodeMataUang): int
    {
        return $this->presisi->amountDecimals($tenantId, $kodeMataUang);
    }

    public function hargaSatuan(string $tenantId, string $kodeMataUang): int
    {
        return $this->presisi->unitAmountDecimals($tenantId, $kodeMataUang);
    }

    public function bulatkan(string $tenantId, string|int|float $nilai, string $kodeMataUang): string
    {
        return $this->presisi->roundAmount($tenantId, $nilai, $kodeMataUang);
    }
}
