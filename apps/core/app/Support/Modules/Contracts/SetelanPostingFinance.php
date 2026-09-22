<?php

declare(strict_types=1);

namespace App\Support\Modules\Contracts;

/**
 * Setelan feed posting finance satu entitas legal, untuk module yang menerbitkan posting.
 *
 * Module membutuhkan dua jawaban sebelum menyusun jurnal: kebijakan penyelesaian perolehan pada
 * tanggal dokumen (untuk memilih akun lawan dan mewajibkan vendor), dan tanggal cutover (saldo awal
 * tidak boleh bertanggal sesudahnya). Keputusan "posting ini dikirim atau tidak" tetap milik
 * penerbit posting di Core, bukan module.
 *
 * Entitas legal yang **tidak ada** adalah kesalahan pemanggil dan dilempar sebagai
 * `RuntimeException`. Entitas yang ada tetapi belum disetel adalah keadaan wajar: mode
 * `direct_payable` dan cutover `null`.
 */
interface SetelanPostingFinance
{
    /** `direct_payable` atau `clearing` yang berlaku pada `$tanggal` (`Y-m-d`). */
    public function modePenyelesaian(string $legalEntityId, string $tanggal): string;

    /** Tanggal cutover (`Y-m-d`), atau `null` bila belum disetel. */
    public function cutover(string $legalEntityId): ?string;
}
