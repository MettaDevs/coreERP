<?php

declare(strict_types=1);

namespace App\Support\Pusat;

/**
 * Kata sandi sementara yang dibuatkan operator untuk admin pertama sebuah tenant.
 *
 * Ia dibuat **server**, tidak pernah dikirim pemanggil. Kata sandi yang boleh ditentukan pemanggil
 * adalah kata sandi yang dapat ditebak pemanggil, dan pemanggil di sini adalah konsol operator —
 * bukan pemilik akunnya.
 *
 * ## Kenapa panjangnya dari alfabet, bukan dari aturan
 *
 * Aturan kata sandi repo ini baru menyala di produksi: minimal 12 karakter, huruf besar dan kecil,
 * angka, dan tanda baca. Yang dibuat di sini jauh melewatinya dengan sengaja — sandi ini melewati
 * tangan orang lain dan hidup di chat atau catatan telepon sampai pemiliknya masuk, jadi yang
 * menentukan panjangnya bukan ambang aturan melainkan berapa lama ia beredar di luar.
 *
 * ## Karakter yang dibuang, dan kenapa membuangnya bukan kelemahan
 *
 * `l`, `I`, `1`, `O`, dan `0` dibuang karena sandi ini **dibacakan dan diketik ulang manusia**.
 * Satu huruf yang salah dibaca berarti satu telepon lagi, dan kelas kesalahan itu justru yang
 * membuat orang menempelkan sandinya ke tempat yang lebih tidak aman. Alfabetnya tinggal 54
 * karakter, dan pada 20 karakter itu masih di atas seratus bit — tidak ada yang dikorbankan.
 */
final class SandiSementara
{
    private const HURUF_KECIL = 'abcdefghijkmnopqrstuvwxyz';

    private const HURUF_BESAR = 'ABCDEFGHJKLMNPQRSTUVWXYZ';

    private const ANGKA = '23456789';

    /** Tanda baca yang selamat melewati shell, URL, dan kolom teks mana pun. */
    private const TANDA = '!@#$%*-_=+?';

    private const PANJANG = 20;

    public static function buat(): string
    {
        // Satu karakter dijamin dari tiap golongan lebih dulu. Mengacak dari alfabet gabungan
        // lalu berharap keempatnya muncul adalah cara membuat sandi yang sesekali ditolak aturan
        // produksi — dan kegagalan itu akan muncul pada pelanggan, bukan pada test.
        $karakter = [
            self::ambil(self::HURUF_KECIL),
            self::ambil(self::HURUF_BESAR),
            self::ambil(self::ANGKA),
            self::ambil(self::TANDA),
        ];

        $alfabet = self::HURUF_KECIL.self::HURUF_BESAR.self::ANGKA.self::TANDA;
        for ($i = count($karakter); $i < self::PANJANG; $i++) {
            $karakter[] = self::ambil($alfabet);
        }

        // Tanpa pengacakan ini, empat karakter pertama selalu berasal dari golongan yang sama
        // berurutan — bentuk yang dapat ditebak, dan yang mempersempit ruang pencarian.
        for ($i = count($karakter) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$karakter[$i], $karakter[$j]] = [$karakter[$j], $karakter[$i]];
        }

        return implode('', $karakter);
    }

    /** `random_int` dan bukan `rand`: yang ini saja yang dijamin aman secara kriptografis. */
    private static function ambil(string $alfabet): string
    {
        return $alfabet[random_int(0, strlen($alfabet) - 1)];
    }
}
