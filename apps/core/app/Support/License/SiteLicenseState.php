<?php

declare(strict_types=1);

namespace App\Support\License;

/**
 * Keadaan lisensi situs sebagaimana dibaca pada satu permintaan, beserta dua jawaban yang
 * diturunkan darinya: apakah pemasangan ini terkunci, dan app mana yang boleh dibuka.
 *
 * Kedua jawaban itu tinggal di sini, bukan di setiap pintu yang menanyakannya. Ada empat pintu —
 * halaman dan API module, peluncur, daftar produk, panggilan antar-app — dan empat salinan aturan
 * yang sama adalah empat tempat untuk lupa bahwa lisensi yang tidak wajib tidak pernah mengunci.
 *
 * Isinya sengaja sedikit. Id tenant dan id situs tidak ikut: yang memeriksa keduanya agen, saat
 * memasang berkasnya, dan tidak ada yang di Core yang perlu memutuskan sesuatu darinya. Nilai yang
 * dibawa tanpa pembaca adalah nilai yang kelak dipakai orang untuk memutuskan sesuatu yang tidak
 * pernah dirancang untuk diputuskan dari sini.
 *
 * `validUntil`, `daysLeft`, dan `apps` hanya terisi ketika tanda tangannya terbukti. Tanggal dan
 * daftar app dari berkas yang tidak dapat diverifikasi adalah karangan siapa pun yang menyuntingnya
 * — dan daftar app karangan itulah persis yang ingin dicegah lisensi ini.
 */
final readonly class SiteLicenseState
{
    /** Lisensi tidak wajib dan jalurnya tidak disetel — SaaS, beli-putus, dan lingkungan lokal. */
    public const NOT_REQUIRED = 'not_required';

    /**
     * Berkas lisensi, tanda tangan, atau kunci publiknya tidak ada atau tidak terbaca — atau lisensi
     * wajib tetapi jalurnya tidak disetel sama sekali.
     */
    public const MISSING = 'missing';

    /** Ada, tetapi tanda tangannya gagal atau isinya tidak berbentuk lisensi yang dikenal. */
    public const INVALID = 'invalid';

    public const VALID = 'valid';

    /** Masih berlaku, dan tanggal berakhirnya jatuh dalam jendela peringatan — hari ini termasuk. */
    public const EXPIRING = 'expiring';

    /** Tanggal berakhirnya sudah lewat. Mengunci bila lisensinya wajib. */
    public const EXPIRED = 'expired';

    /**
     * Keadaan yang mengunci ketika lisensi wajib.
     *
     * Hilang dan tanda tangan salah diperlakukan sama dengan habis. Kalau tidak, menghapus berkasnya
     * atau menyunting satu byte menjadi jalan pintas yang lebih murah daripada membayar.
     */
    private const LOCKING = [self::MISSING, self::INVALID, self::EXPIRED];

    /**
     * @param  self::NOT_REQUIRED|self::MISSING|self::INVALID|self::VALID|self::EXPIRING|self::EXPIRED  $status
     * @param  list<string>  $apps  App yang tercantum di lisensi bertanda tangan sah; kosong bila tidak terbukti.
     * @param  int|null  $daysLeft  Sisa hari sampai tanggal berakhir; negatif bila sudah lewat.
     */
    public function __construct(
        public string $status,
        public ?string $validUntil = null,
        public array $apps = [],
        public bool $required = false,
        public ?int $daysLeft = null,
    ) {}

    /** Terkunci hanya bila lisensinya wajib. Pemasangan yang tidak mewajibkannya tidak pernah terkunci. */
    public function isLocked(): bool
    {
        return $this->required && in_array($this->status, self::LOCKING, true);
    }

    /**
     * Apakah app ini boleh dibuka di pemasangan ini.
     *
     * Urutannya bagian dari aturannya. Tidak wajib didahulukan, supaya SaaS dan beli-putus tidak
     * pernah kehilangan app karena lisensi yang kebetulan tersisa di disk. Terkunci sebelum daftar,
     * supaya lisensi habis yang masih mencantumkan app tidak tetap membukanya.
     */
    public function allowsApp(string $appId): bool
    {
        if (! $this->required) {
            return true;
        }

        if ($this->isLocked()) {
            return false;
        }

        return in_array($appId, $this->apps, true);
    }

    /**
     * Bentuk yang dikirim ke peramban. `apps` sengaja tidak ikut: tidak ada layar yang membacanya,
     * dan yang menyaring app adalah server, bukan halaman.
     *
     * @return array{status: string, validUntil: string|null, daysLeft: int|null, required: bool}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'validUntil' => $this->validUntil,
            'daysLeft' => $this->daysLeft,
            'required' => $this->required,
        ];
    }
}
