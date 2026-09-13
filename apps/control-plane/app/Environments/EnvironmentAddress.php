<?php

declare(strict_types=1);

namespace ControlPlane\Environments;

/**
 * Menghitung alamat sebuah lingkungan, untuk ditampilkan di layar operator.
 *
 * ## Ini salinan aturan milik Core, dan itu disebut apa adanya
 *
 * Yang berwenang atas bentuk alamat adalah `App\Support\ControlPlane\EnvironmentAddress` di Core —
 * ia yang dipakai middleware untuk **mengurai** alamat masuk, jadi ialah yang menentukan alamat
 * mana yang sungguhan bekerja. Kelas ini hanya membangun, tidak pernah mengurai.
 *
 * Salinan dipilih di atas dua alternatif, dan keduanya lebih buruk:
 *
 * - **Memanggil Core lewat HTTP** untuk sesuatu yang murni perhitungan string berarti satu
 *   permintaan jaringan tiap kali sebuah daftar digambar, dan layar yang mati ketika Core sedang
 *   dimuat ulang.
 * - **Tidak menampilkannya sama sekali** — keadaan sebelum ini — memaksa operator menyusun sendiri
 *   alamatnya dari slug tenant, slug lingkungan, dan jenisnya. Itu cara tercepat melahirkan alamat
 *   salah ketik yang lalu dilaporkan sebagai "tidak bisa dibuka".
 *
 * Ongkos salinan adalah kemungkinan menyimpang, dan yang menahannya `EnvironmentAddressTest` di
 * konsol ini: ia memaku string yang sama persis dengan yang dipaku test milik Core. Kalau salah
 * satu sisi berubah bentuk, salah satu suite merah.
 */
final class EnvironmentAddress
{
    /**
     * Domain dasar yang berlaku, atau kosong bila penempatan ini satu alamat untuk semua.
     *
     * Kosong adalah keadaan yang sah sekaligus bawaannya: on-prem melayani satu pelanggan dari
     * satu alamat, dan pengembangan lokal sebelum DNS disiapkan juga begitu. Di sana tidak ada
     * alamat per lingkungan untuk ditampilkan.
     */
    public static function baseDomain(): string
    {
        $domain = config('core.base_domain');

        return is_string($domain) ? mb_strtolower(trim($domain, ". \t\n\r\0\x0B")) : '';
    }

    /**
     * Alamat lengkap sebuah lingkungan, berikut skemanya. Null berarti tidak ada alamat khusus.
     *
     * | Jenis | Bentuk |
     * | --- | --- |
     * | `production` | `<tenant>.<domain>` |
     * | selain itu | `<tenant>--<lingkungan>.<jenis>.<domain>` |
     *
     * Produksi tanpa label jenis, karena itu alamat yang dipakai pelanggan sehari-hari dan ia tidak
     * perlu mengumumkan dirinya. Pemisahnya DUA tanda hubung: `Str::slug()` tidak pernah
     * menghasilkan dua berurutan, sementara satu tanda hubung membuat `pt-sinar-abadi` + `peragaan`
     * tidak dapat dibedakan dari `pt` + `sinar-abadi-peragaan`.
     */
    public static function forEnvironment(string $tenant, string $environment, string $kind): ?string
    {
        $domain = self::baseDomain();

        if ($domain === '' || $tenant === '' || $environment === '') {
            return null;
        }

        return $kind === 'production'
            ? 'https://'.$tenant.'.'.$domain
            : 'https://'.$tenant.'--'.$environment.'.'.$kind.'.'.$domain;
    }
}
