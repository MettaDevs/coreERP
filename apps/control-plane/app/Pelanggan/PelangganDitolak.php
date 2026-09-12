<?php

declare(strict_types=1);

namespace ControlPlane\Pelanggan;

use RuntimeException;
use Throwable;

/**
 * Core menjawab, dan jawabannya "tidak".
 *
 * Kelasnya sendiri supaya lapisan HTTP dapat memulangkannya sebagai kesalahan formulir yang
 * terbaca operator — email sudah terdaftar, app tidak tersedia, kunci salah — alih-alih halaman
 * 500 yang menyembunyikan sebabnya. Polanya sama dengan `LingkunganDitolak` di sebelahnya.
 *
 * Yang ditambahkan di sini: penolakan Core sering **milik satu isian tertentu**, dan Core sudah
 * menyebutkannya. Membawa petanya utuh membuat pesan "Email sudah terdaftar" mendarat di kotak
 * email, bukan di bagian atas dialog tempat mata harus mencari isian mana yang dimaksud.
 */
class PelangganDitolak extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $perIsian  Alasan per isian, apa adanya dari Core.
     */
    public function __construct(
        string $pesan,
        private readonly array $perIsian = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($pesan, previous: $previous);
    }

    /** @return array<string, list<string>> */
    public function perIsian(): array
    {
        return $this->perIsian;
    }
}
