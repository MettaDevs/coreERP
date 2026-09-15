<?php

declare(strict_types=1);

namespace ControlPlane\Sites;

/**
 * Kata sandi sementara owner tenant di server klien, dibuat konsol ini untuk operasi `install`.
 *
 * Berbeda dari pembuatan tenant SaaS, Core yang memakai kata sandi ini tidak ada di sini — ia belum
 * terpasang. Yang dikirim ke agen hanya hash bcrypt-nya; kata sandinya ditunjukkan sekali kepada
 * operator dan dibacakan atau ditempel ke admin klien.
 *
 * ## Alfabet
 *
 * - `l`, `I`, `1`, `O`, `0` dibuang: sandi ini diketik ulang manusia dari telepon atau chat, sama
 *   dengan alasan `TemporaryPassword` milik Core.
 * - Tanda baca hanya `_`. `$`, `!`, `*`, `?`, kutip, dan spasi berubah arti di shell; `-`, `@`, `=`,
 *   `+`, `%` memotong pilihan klik-ganda di peramban dan terminal, sehingga sandi yang disalin dengan
 *   klik-ganda tertinggal separuh. `_` tetap dihitung tanda baca oleh aturan kata sandi Laravel
 *   (`Password::symbols()`), jadi sandi ini lolos aturan produksi Core bila kelak diperiksa di sana.
 * - Alfabet 58 karakter pada 20 karakter masih di atas seratus bit.
 */
final class TemporaryPassword
{
    private const LOWER = 'abcdefghijkmnopqrstuvwxyz';

    private const UPPER = 'ABCDEFGHJKLMNPQRSTUVWXYZ';

    private const DIGITS = '23456789';

    private const SYMBOLS = '_';

    private const LENGTH = 20;

    public static function generate(): string
    {
        // Satu dari tiap golongan dijamin lebih dulu: sandi acak dari alfabet gabungan sesekali tidak
        // memuat angka atau tanda baca, dan penolakan aturan itu akan muncul pada admin klien.
        $characters = [
            self::pick(self::LOWER),
            self::pick(self::UPPER),
            self::pick(self::DIGITS),
            self::pick(self::SYMBOLS),
        ];

        $alphabet = self::LOWER.self::UPPER.self::DIGITS.self::SYMBOLS;

        for ($i = count($characters); $i < self::LENGTH; $i++) {
            $characters[] = self::pick($alphabet);
        }

        // Tanpa pengacakan, empat karakter pertama selalu mengikuti urutan golongan di atas.
        for ($i = count($characters) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$characters[$i], $characters[$j]] = [$characters[$j], $characters[$i]];
        }

        return implode('', $characters);
    }

    private static function pick(string $alphabet): string
    {
        return $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
}
