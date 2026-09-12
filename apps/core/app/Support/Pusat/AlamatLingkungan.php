<?php

declare(strict_types=1);

namespace App\Support\Pusat;

/**
 * Menghitung alamat sebuah lingkungan, dan membaca kembali alamat menjadi lingkungan.
 *
 * Kedua arah tinggal di satu kelas dengan sengaja. Membangun alamat dan mengurainya adalah satu
 * aturan yang dibaca dari dua sisi; menaruhnya di dua tempat berarti dua tempat yang akan
 * menyimpang, dan penyimpangannya berbentuk pelanggan yang tidak dapat masuk ke alamat yang
 * dicetak sistem itu sendiri.
 *
 * ## Bentuknya
 *
 * | Jenis | Alamat |
 * | --- | --- |
 * | `production` | `<tenant>.contoh.co.id` |
 * | selain itu | `<tenant>-<lingkungan>.<jenis>.contoh.co.id` |
 *
 * Produksi tanpa label jenis, karena itu alamat yang dipakai pelanggan sehari-hari dan ia tidak
 * perlu mengumumkan dirinya. Yang selain produksi justru harus — label jenisnya terbaca dari bilah
 * alamat, dan peramban memisahkan cookie antar label itu secara alami, sehingga sesi sandbox tidak
 * pernah dapat membaca sesi produksi.
 *
 * ## Kenapa label pertamanya `<tenant>-<lingkungan>`, bukan salah satunya saja
 *
 * `environments.slug` hanya unik **per tenant** — dua pelanggan boleh sama-sama punya lingkungan
 * bernama `uat`. Slug tenant unik di seluruh sistem. Menggabungkan keduanya menghasilkan label yang
 * unik global tanpa kolom baru dan tanpa indeks baru.
 *
 * Bentuk gabungan itu juga yang dipakai Dynamics: `ivs-uat.sandbox.…`, dengan `ivs` pelanggannya
 * dan `uat` lingkungannya.
 *
 * ## Ongkos TLS yang menentukan bentuk ini
 *
 * Sebuah wildcard hanya mencakup **satu label** (RFC 6125 §6.4.3), dan ia tidak mencakup domain
 * induknya sendiri. Karena itu satu sertifikat memuat empat nama: `*.contoh.co.id`,
 * `*.demo.contoh.co.id`, `*.sandbox.contoh.co.id`, dan `contoh.co.id`. Menambah jenis keempat
 * berarti menambah nama kelima ke sertifikat yang sama — bukan menerbitkan sertifikat baru per
 * pelanggan.
 */
final class AlamatLingkungan
{
    /**
     * @param  string  $tenant  slug tenant, unik di seluruh sistem
     * @param  string  $lingkungan  slug lingkungan, unik di dalam tenantnya
     */
    private function __construct(
        public readonly string $tenant,
        public readonly string $lingkungan,
        public readonly string $jenis,
    ) {}

    /**
     * Domain dasar yang berlaku, atau kosong bila penempatan ini memang satu alamat untuk semua.
     *
     * Kosong adalah keadaan yang sah dan sekaligus bawaannya: on-prem melayani satu pelanggan dari
     * satu alamat, dan lingkungan pengembangan sebelum DNS disiapkan juga begitu. Selama ia kosong,
     * seluruh mekanisme di kelas ini tidak pernah menyala — bukan gagal, tidak menyala.
     */
    public static function domainDasar(): string
    {
        $domain = config('coreerp.domain_dasar');

        return is_string($domain) ? mb_strtolower(trim($domain, ". \t\n\r\0\x0B")) : '';
    }

    /** Alamat lengkap sebuah lingkungan, tanpa skema dan tanpa porta. */
    public static function untuk(string $tenant, string $lingkungan, string $jenis): ?string
    {
        $domain = self::domainDasar();

        if ($domain === '') {
            return null;
        }

        if ($jenis === 'production') {
            return $tenant.'.'.$domain;
        }

        return $tenant.'-'.$lingkungan.'.'.$jenis.'.'.$domain;
    }

    /**
     * Membaca host sebuah permintaan menjadi pasangan tenant + lingkungan, atau null.
     *
     * Null berarti "alamat ini bukan alamat lingkungan" — dan itu bukan kesalahan. Alamat pangkal
     * (`contoh.co.id`), alamat konsol operator, dan `localhost` polos semuanya jatuh ke sana, dan
     * semuanya memang harus tetap bekerja seperti sebelum mekanisme ini ada.
     *
     * Porta dibuang lebih dulu: `percobaan.demo.localhost:8000` adalah alamat yang sah selama
     * pengembangan, dan peramban modern menyelesaikan setiap `*.localhost` ke mesin sendiri
     * sehingga jalur ini dapat dicoba tanpa menyentuh DNS sama sekali.
     */
    public static function dariHost(string $host): ?self
    {
        $domain = self::domainDasar();

        if ($domain === '') {
            return null;
        }

        $host = mb_strtolower((string) preg_replace('/:\d+$/', '', trim($host)));
        $akhiran = '.'.$domain;

        if (! str_ends_with($host, $akhiran)) {
            return null;
        }

        $depan = substr($host, 0, -strlen($akhiran));

        if ($depan === '') {
            return null;
        }

        $label = explode('.', $depan);

        // Satu label: produksi. `<tenant>.contoh.co.id`
        if (count($label) === 1) {
            return $label[0] === '' ? null : new self($label[0], $label[0], 'production');
        }

        // Dua label: `<tenant>-<lingkungan>.<jenis>.contoh.co.id`. Lebih dari dua bukan bentuk yang
        // pernah kita cetak, dan menerimanya berarti menebak maksud orang yang mengetiknya.
        if (count($label) !== 2) {
            return null;
        }

        [$gabungan, $jenis] = $label;

        // Tanda hubung pertama yang memisahkan, bukan yang terakhir: slug tenant tidak pernah
        // memuat tanda hubung dari sisi kanan, sedangkan slug lingkungan justru sering — "uji-coba",
        // "peragaan-penjualan". Memotong dari kanan akan membelah nama lingkungan di tempat yang
        // salah dan menghasilkan tenant yang tidak pernah ada.
        $pisah = strpos($gabungan, '-');

        if ($pisah === false || $pisah === 0 || $pisah === strlen($gabungan) - 1) {
            return null;
        }

        return new self(
            substr($gabungan, 0, $pisah),
            substr($gabungan, $pisah + 1),
            $jenis,
        );
    }
}
