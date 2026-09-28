<?php

declare(strict_types=1);

namespace App\Support\ControlPlane;

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
 * | `production` | `<tenant>.erp.contoh.co.id` |
 * | `demo`, `sandbox` | `<tenant>.<jenis>.erp.contoh.co.id` |
 *
 * `erp.contoh.co.id` di atas adalah domain dasar (`COREERP_BASE_DOMAIN`) — nama aplikasi beserta
 * domain perusahaannya. Produksi tanpa label jenis, karena itu alamat yang dipakai pelanggan
 * sehari-hari dan ia tidak perlu mengumumkan dirinya. Yang selain produksi justru harus — label
 * jenisnya terbaca dari bilah alamat, dan peramban memisahkan cookie antar host secara alami,
 * sehingga sesi demo tidak pernah dapat membaca sesi produksi.
 *
 * ## Satu tenant, satu lingkungan per jenis
 *
 * Alamat hanya memuat tenant dan jenis, jadi satu tenant hanya dapat punya satu lingkungan hidup
 * per jenis. Produksi sudah dijaga `environments_satu_produksi`; demo dan sandbox dijaga
 * `environments_satu_per_jenis`. Keduanya di database pusat — tanpa itu sebuah alamat dapat
 * menunjuk dua lingkungan, dan yang terpilih ditentukan urutan baris.
 *
 * Bentuk sebelumnya, `<tenant>--<lingkungan>.<jenis>.…`, memuat slug lingkungan supaya satu tenant
 * dapat punya banyak demo. Pemilik produk memilih alamat yang terbaca manusia di atas kemampuan itu
 * pada 14 September 2026. Pemisah dua tanda hubung yang dibutuhkan bentuk lama ikut hilang.
 *
 * ## Ongkos TLS yang menentukan bentuk ini
 *
 * Sebuah wildcard hanya mencakup **satu label** (RFC 6125 §6.4.3), dan ia tidak mencakup domain
 * induknya sendiri. Karena itu satu sertifikat memuat empat nama: `*.erp.contoh.co.id`,
 * `*.demo.erp.contoh.co.id`, `*.sandbox.erp.contoh.co.id`, dan `erp.contoh.co.id`. Label jenis
 * yang tetap — bukan slug lingkungan yang dikarang operator — itulah yang membuat satu sertifikat
 * cukup untuk seluruh tenant. `<tenant>.<slug-lingkungan>.…` akan menuntut sertifikat wildcard baru
 * untuk setiap nama lingkungan yang pernah dibuat.
 */
final class EnvironmentAddress
{
    /** Jenis yang membawa label sendiri di alamatnya. Produksi tidak. */
    public const LABELLED_KINDS = ['demo', 'sandbox'];

    /**
     * @param  string  $tenant  slug tenant, unik di seluruh sistem
     */
    private function __construct(
        public readonly string $tenant,
        public readonly string $kind,
    ) {}

    /**
     * Domain dasar yang berlaku, atau kosong bila penempatan ini memang satu alamat untuk semua.
     *
     * Kosong adalah keadaan yang sah dan sekaligus bawaannya: on-prem melayani satu pelanggan dari
     * satu alamat, dan lingkungan pengembangan sebelum DNS disiapkan juga begitu. Selama ia kosong,
     * seluruh mekanisme di kelas ini tidak pernah menyala — bukan gagal, tidak menyala.
     */
    public static function baseDomain(): string
    {
        $domain = config('coreerp.base_domain');

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
    public static function reservedLabels(): array
    {
        $list = config('coreerp.reserved_labels', ['admin', 'www', 'api', 'registry']);

        return is_array($list) ? array_values(array_map(strval(...), $list)) : [];
    }

    /**
     * Apakah host ini berada di bawah domain kita, di luar alamat pangkalnya sendiri.
     *
     * Dipisah dari `fromHost()` karena keduanya menjawab pertanyaan yang berbeda, dan bedanya
     * menentukan apa yang terjadi pada alamat yang salah ketik. Host di luar domain kita bukan
     * urusan kita — ia lewat. Host **di bawah** domain kita yang tidak menunjuk lingkungan mana pun
     * adalah keadaan lain: dengan DNS wildcard, setiap label yang pernah diketik siapa pun sampai
     * ke sini, dan menyajikan aplikasi pangkal di sana berarti aplikasi kita dapat disajikan dari
     * alamat mana saja yang dikarang orang.
     */
    public static function isUnderBaseDomain(string $host): bool
    {
        $domain = self::baseDomain();

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
        $prefix = substr($host, 0, -strlen('.'.$domain));

        return ! in_array($prefix, self::reservedLabels(), true);
    }

    /** Alamat lengkap lingkungan milik tenant ini dengan jenis ini, tanpa skema dan tanpa porta. */
    public static function forEnvironment(string $tenant, string $kind): ?string
    {
        $domain = self::baseDomain();

        if ($domain === '' || $tenant === '') {
            return null;
        }

        if ($kind === 'production') {
            return $tenant.'.'.$domain;
        }

        return $tenant.'.'.$kind.'.'.$domain;
    }

    /**
     * Membaca host sebuah permintaan menjadi pasangan tenant + jenis, atau null.
     *
     * Null berarti "alamat ini bukan alamat lingkungan" — dan itu bukan kesalahan. Alamat pangkal
     * (`contoh.co.id`), alamat konsol operator, dan `localhost` polos semuanya jatuh ke sana, dan
     * semuanya memang harus tetap bekerja seperti sebelum mekanisme ini ada.
     *
     * Porta dibuang lebih dulu: `percobaan.demo.erp.localhost:8000` adalah alamat yang sah selama
     * pengembangan, dan peramban modern menyelesaikan setiap `*.localhost` ke mesin sendiri
     * sehingga jalur ini dapat dicoba tanpa menyentuh DNS sama sekali.
     */
    public static function fromHost(string $host): ?self
    {
        $domain = self::baseDomain();

        if ($domain === '') {
            return null;
        }

        $host = mb_strtolower((string) preg_replace('/:\d+$/', '', trim($host)));
        $suffix = '.'.$domain;

        if (! str_ends_with($host, $suffix)) {
            return null;
        }

        $prefix = substr($host, 0, -strlen($suffix));

        if ($prefix === '') {
            return null;
        }

        $label = explode('.', $prefix);

        // Satu label: produksi. `<tenant>.erp.contoh.co.id`
        if (count($label) === 1) {
            if ($label[0] === '' || in_array($label[0], self::reservedLabels(), true)) {
                return null;
            }

            return new self($label[0], 'production');
        }

        // Dua label: `<tenant>.<jenis>.erp.contoh.co.id`. Lebih dari dua bukan bentuk yang pernah
        // kita cetak, dan menerimanya berarti menebak maksud orang yang mengetiknya.
        if (count($label) !== 2) {
            return null;
        }

        [$tenant, $kind] = $label;

        // Jenisnya harus salah satu yang memang berlabel. `ivs.production.…` bukan alamat yang
        // pernah kita cetak — produksi tidak berlabel — dan label karangan seperti `ivs.uat.…`
        // tidak boleh diteruskan sebagai jenis yang dicari ke database.
        if ($tenant === '' || ! in_array($kind, self::LABELLED_KINDS, true)) {
            return null;
        }

        return new self($tenant, $kind);
    }
}
