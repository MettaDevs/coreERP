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
 * | selain itu | `<tenant>--<lingkungan>.<jenis>.contoh.co.id` |
 *
 * Produksi tanpa label jenis, karena itu alamat yang dipakai pelanggan sehari-hari dan ia tidak
 * perlu mengumumkan dirinya. Yang selain produksi justru harus — label jenisnya terbaca dari bilah
 * alamat, dan peramban memisahkan cookie antar label itu secara alami, sehingga sesi sandbox tidak
 * pernah dapat membaca sesi produksi.
 *
 * ## Kenapa label pertamanya `<tenant>--<lingkungan>`, bukan salah satunya saja
 *
 * `environments.slug` hanya unik **per tenant** — dua pelanggan boleh sama-sama punya lingkungan
 * bernama `uat`. Slug tenant unik di seluruh sistem. Menggabungkan keduanya menghasilkan label yang
 * unik global tanpa kolom baru dan tanpa indeks baru.
 *
 * Bentuk gabungan itu juga yang dipakai Dynamics: `ivs-uat.sandbox.…`, dengan `ivs` pelanggannya
 * dan `uat` lingkungannya.
 *
 * ## Kenapa pemisahnya DUA tanda hubung
 *
 * Percobaan pertama memakai satu, dengan alasan "slug tenant tidak pernah memuat tanda hubung".
 * Alasan itu salah, dan salahnya baru terlihat pada pelanggan sungguhan: `uniqueSlug()`
 * meng-slugify nama badan hukum, jadi "PT Sinar Abadi" menjadi `pt-sinar-abadi`. Slug lingkungan
 * juga sering bertanda hubung — "peragaan-penjualan". Dengan satu tanda hubung, `pt-sinar-abadi`
 * ditambah `peragaan` tidak dapat dibedakan dari `pt` ditambah `sinar-abadi-peragaan`: ambigu dari
 * kedua arah, dan tidak ada aturan potong yang benar.
 *
 * `Str::slug()` tidak pernah menghasilkan dua tanda hubung berurutan, jadi `--` adalah pemisah yang
 * tidak dapat muncul di dalam kedua sisinya. DNS mengizinkannya; satu-satunya yang dikhususkan
 * adalah awalan `xn--` pada posisi pertama label, dan slug tenant tidak pernah diawali `xn`.
 *
 * Yang menemukan ini bukan test melainkan pemeriksaan langsung ke alamat sungguhan — testnya
 * memakai `ivs`, slug tanpa tanda hubung, sehingga ia setuju dengan asumsi yang keliru.
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

    /**
     * Label yang tidak pernah menjadi lingkungan, meski berada di bawah domain yang sama.
     *
     * Konsol operator dan alamat pemasaran hidup di bawah domain yang sama, dan keduanya berbentuk
     * satu label — persis bentuk alamat produksi. Tanpa daftar ini, `admin.contoh.co.id` akan
     * dicari sebagai tenant bernama "admin", tidak ditemukan, lalu dijawab 404 — mematikan konsol
     * operator dengan cara yang tidak menyebut sebabnya sama sekali.
     *
     * @return list<string>
     */
    public static function labelDikecualikan(): array
    {
        $daftar = config('coreerp.label_bukan_lingkungan', ['admin', 'www', 'api']);

        return is_array($daftar) ? array_values(array_map(strval(...), $daftar)) : [];
    }

    /**
     * Apakah host ini berada di bawah domain kita, di luar alamat pangkalnya sendiri.
     *
     * Dipisah dari `dariHost()` karena keduanya menjawab pertanyaan yang berbeda, dan bedanya
     * menentukan apa yang terjadi pada alamat yang salah ketik. Host di luar domain kita bukan
     * urusan kita — ia lewat. Host **di bawah** domain kita yang tidak menunjuk lingkungan mana pun
     * adalah keadaan lain: dengan DNS wildcard, setiap label yang pernah diketik siapa pun sampai
     * ke sini, dan menyajikan aplikasi pangkal di sana berarti aplikasi kita dapat disajikan dari
     * alamat mana saja yang dikarang orang.
     */
    public static function dibawahDomain(string $host): bool
    {
        $domain = self::domainDasar();

        if ($domain === '') {
            return false;
        }

        $host = mb_strtolower((string) preg_replace('/:\d+$/', '', trim($host)));

        if (! str_ends_with($host, '.'.$domain)) {
            return false;
        }

        // Label yang memang bukan lingkungan — konsol operator, alamat pemasaran — berada di bawah
        // domain yang sama tetapi tidak boleh dituntut menunjuk lingkungan. Diperiksa di sini dan
        // bukan di pemanggilnya, supaya kedua pertanyaan itu tidak pernah dijawab berbeda oleh dua
        // tempat.
        $depan = substr($host, 0, -strlen('.'.$domain));

        return ! in_array($depan, self::labelDikecualikan(), true);
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

        return $tenant.'--'.$lingkungan.'.'.$jenis.'.'.$domain;
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
            if ($label[0] === '' || in_array($label[0], self::labelDikecualikan(), true)) {
                return null;
            }

            return new self($label[0], $label[0], 'production');
        }

        // Dua label: `<tenant>-<lingkungan>.<jenis>.contoh.co.id`. Lebih dari dua bukan bentuk yang
        // pernah kita cetak, dan menerimanya berarti menebak maksud orang yang mengetiknya.
        if (count($label) !== 2) {
            return null;
        }

        [$gabungan, $jenis] = $label;

        // Dua tanda hubung, dan tepat satu kemunculan. Keduanya diperiksa: `Str::slug()` tidak
        // pernah menghasilkan `--`, jadi label yang memuatnya lebih dari sekali bukan alamat yang
        // pernah kita cetak — dan menebak maksud orang yang mengetiknya lebih berbahaya daripada
        // menolaknya.
        $bagian = explode('--', $gabungan);

        if (count($bagian) !== 2 || $bagian[0] === '' || $bagian[1] === '') {
            return null;
        }

        return new self($bagian[0], $bagian[1], $jenis);
    }
}
