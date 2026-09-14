<?php

declare(strict_types=1);

namespace App\Support\License;

/**
 * Keadaan lisensi situs sebagaimana dibaca pada satu permintaan.
 *
 * Isinya sengaja hanya dua: keadaan dan tanggal berakhir. Selebihnya isi lisensi — id tenant, id
 * situs, edisi — tidak dibutuhkan siapa pun di Core hari ini, dan nilai yang dibawa tanpa pembaca
 * adalah nilai yang kelak dipakai orang untuk memutuskan sesuatu yang tidak pernah dirancang untuk
 * diputuskan dari sini.
 *
 * `validUntil` hanya terisi ketika tanda tangannya terbukti. Tanggal dari berkas yang tidak dapat
 * diverifikasi adalah tanggal karangan siapa pun yang menyuntingnya.
 */
final readonly class SiteLicenseState
{
    /** Pemasangan ini tidak menyetel lisensi sama sekali — SaaS dan lingkungan lokal. */
    public const NOT_REQUIRED = 'not_required';

    /** Disetel, tetapi berkas lisensi, tanda tangan, atau kunci publiknya tidak ada atau tidak terbaca. */
    public const MISSING = 'missing';

    /** Ada, tetapi tanda tangannya gagal atau isinya tidak berbentuk lisensi yang dikenal. */
    public const INVALID = 'invalid';

    public const VALID = 'valid';

    /** Masih berlaku, dan tanggal berakhirnya jatuh dalam jendela peringatan — hari ini termasuk. */
    public const EXPIRING = 'expiring';

    /** Tanggal berakhirnya sudah lewat. Aplikasi tetap berjalan penuh. */
    public const EXPIRED = 'expired';

    /**
     * @param  self::NOT_REQUIRED|self::MISSING|self::INVALID|self::VALID|self::EXPIRING|self::EXPIRED  $status
     */
    public function __construct(
        public string $status,
        public ?string $validUntil = null,
    ) {}

    /** @return array{status: string, validUntil: string|null} */
    public function toArray(): array
    {
        return ['status' => $this->status, 'validUntil' => $this->validUntil];
    }
}
