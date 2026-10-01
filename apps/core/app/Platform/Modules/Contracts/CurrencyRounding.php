<?php

declare(strict_types=1);

namespace App\Platform\Modules\Contracts;

/**
 * Presisi uang per mata uang, dan pembulatan yang harus dipakai module sebelum menerbitkan posting.
 *
 * Aturannya K-20: nilai setiap baris dibulatkan di sumber, satu kali, lalu jurnal disusun dari nilai
 * yang sudah bulat. Module tidak boleh membulatkan dengan `round()` PHP sendiri: float kehilangan
 * sen pada angka besar, dan dua cara membulatkan untuk satu jurnal adalah asal selisih yang tidak
 * pernah bisa ditelusuri.
 *
 * Mata uang yang belum disetel dan tidak punya bawaan dilempar sebagai `RuntimeException`. Fase ini
 * hanya IDR (K-19).
 */
interface CurrencyRounding
{
    /** Jumlah desimal untuk nilai (baris jurnal, total). */
    public function amountDecimals(string $tenantId, string $currencyCode): int;

    /** Jumlah desimal untuk harga satuan. Hanya untuk rincian, tidak pernah untuk baris jurnal. */
    public function unitAmountDecimals(string $tenantId, string $currencyCode): int;

    /**
     * Nilai yang dibulatkan ke presisi nilai mata uang itu, sebagai string desimal berskala pasti.
     *
     * Terdekat, dan yang tepat di tengah menjauhi nol: `"0.005"` → `"0.01"`, `"-0.005"` → `"-0.01"`.
     */
    public function roundAmount(string $tenantId, string|int|float $value, string $currencyCode): string;
}
