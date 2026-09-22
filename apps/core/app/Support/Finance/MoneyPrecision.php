<?php

declare(strict_types=1);

namespace App\Support\Finance;

use App\Models\CurrencyPrecision;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;
use RuntimeException;

/**
 * Presisi uang per mata uang, dan satu-satunya cara membulatkan nilai yang akan dijurnal.
 *
 * Aturannya K-20: nilai tiap baris dibulatkan ke presisi nilai **di sumber**, lalu jurnal disusun
 * dari nilai yang sudah bulat. Keseimbangan jurnal terjadi dengan sendirinya, tanpa baris
 * penyeimbang yang menyembunyikan selisih. Sistem lama tidak pernah seimbang justru karena front
 * office menyimpan lebih banyak desimal daripada finance.
 *
 * Pembulatannya ke nilai terdekat, dan nilai tepat di tengah menjauhi nol (HALF_UP) — sama dengan
 * *Nearest* bawaan Business Central, dan sama dengan cast `decimal` Eloquent. Hitungannya memakai
 * desimal pasti (`brick/math`), tidak pernah float.
 */
final class MoneyPrecision
{
    /**
     * Presisi mata uang yang belum disetel tenant.
     *
     * Hanya IDR, karena fase ini hanya IDR (K-19). Nilai 2 dipakai sampai konsultan memutuskan
     * 0 atau 2; harga satuan 3, seperti data demo Business Central. Mata uang lain tanpa baris
     * ditolak, bukan ditebak — menebak 2 desimal untuk JPY berarti jurnal yang tidak cocok dengan
     * pembacanya.
     */
    public const DEFAULTS = [
        'IDR' => ['amount_decimals' => 2, 'unit_amount_decimals' => 3],
    ];

    public const MAX_AMOUNT_DECIMALS = 4;

    public const MAX_UNIT_AMOUNT_DECIMALS = 6;

    /** @var array<string, array{amount_decimals: int, unit_amount_decimals: int, is_default: bool}> */
    private array $ingatan = [];

    /**
     * @return array{amount_decimals: int, unit_amount_decimals: int, is_default: bool}
     *
     * @throws RuntimeException Mata uang belum disetel dan tidak punya bawaan.
     */
    public function forCurrency(string $tenantId, string $currencyCode): array
    {
        $kode = strtoupper($currencyCode);
        $kunci = $tenantId.'|'.$kode;
        if (isset($this->ingatan[$kunci])) {
            return $this->ingatan[$kunci];
        }

        $baris = CurrencyPrecision::query()
            ->where('tenant_id', $tenantId)
            ->where('currency_code', $kode)
            ->first(['amount_decimals', 'unit_amount_decimals']);

        if ($baris !== null) {
            return $this->ingatan[$kunci] = [
                'amount_decimals' => $baris->amount_decimals,
                'unit_amount_decimals' => $baris->unit_amount_decimals,
                'is_default' => false,
            ];
        }

        $bawaan = self::DEFAULTS[$kode] ?? throw new RuntimeException(sprintf(
            'Presisi mata uang %s belum disetel. Atur di Data referensi › Mata uang.',
            $kode,
        ));

        return $this->ingatan[$kunci] = [...$bawaan, 'is_default' => true];
    }

    public function amountDecimals(string $tenantId, string $currencyCode): int
    {
        return $this->forCurrency($tenantId, $currencyCode)['amount_decimals'];
    }

    public function unitAmountDecimals(string $tenantId, string $currencyCode): int
    {
        return $this->forCurrency($tenantId, $currencyCode)['unit_amount_decimals'];
    }

    /** Membulatkan ke presisi nilai mata uang itu, sebagai string desimal berskala pasti. */
    public function roundAmount(string $tenantId, string|int|float $value, string $currencyCode): string
    {
        return self::round($value, $this->amountDecimals($tenantId, $currencyCode));
    }

    /** Melupakan presisi yang sudah dibaca, setelah tenant mengubahnya dalam proses yang sama. */
    public function forget(): void
    {
        $this->ingatan = [];
    }

    /**
     * Membulatkan ke `$decimals` desimal: terdekat, dan yang tepat di tengah menjauhi nol.
     *
     * Hasilnya selalu berskala persis `$decimals` — `"500000000.00"`, bukan `"500000000"` — karena
     * kontrak feed mengirim nilai uang sebagai string dengan jumlah desimal yang sama dengan
     * `currency.decimals`.
     *
     * Float diterima karena penghitung penyusutan menghitung dalam float, tetapi diubah dulu ke
     * representasi desimal terpendeknya. Lebih baik kirim string.
     */
    public static function round(string|int|float $value, int $decimals): string
    {
        if ($decimals < 0) {
            throw new InvalidArgumentException('Jumlah desimal tidak boleh negatif.');
        }

        return (string) self::decimal($value)->toScale($decimals, RoundingMode::HalfUp);
    }

    /**
     * Jumlah desimal yang benar-benar dibawa sebuah string nilai: `"10.50"` → 2, `"10"` → 0.
     *
     * Nol di belakang koma ikut dihitung. Dipakai untuk menolak nilai yang lebih halus dari presisi
     * mata uangnya, dan untuk itu `"10.50"` pada presisi 1 memang harus ditolak: pengirimnya
     * menyimpan dua desimal.
     */
    public static function scale(string $value): int
    {
        return self::decimal($value)->getScale();
    }

    /** Penjumlahan desimal pasti. */
    public static function sum(string ...$values): string
    {
        $total = BigDecimal::zero();
        foreach ($values as $value) {
            $total = $total->plus(self::decimal($value));
        }

        return (string) $total;
    }

    public static function decimal(string|int|float $value): BigDecimal
    {
        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException('Nilai uang harus berhingga.');
            }
            // Representasi terpendek yang kembali ke float yang sama (`serialize_precision = -1`):
            // 987654321.985 menjadi "987654321.985", bukan deret biner "…984999895" yang membulat
            // ke bawah. `(string)` tidak dipakai karena memotong di 14 digit.
            $value = var_export($value, true);
        }

        return BigDecimal::of(is_string($value) ? trim($value) : $value);
    }
}
