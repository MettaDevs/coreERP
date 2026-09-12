<?php

declare(strict_types=1);

namespace ControlPlane\Customers;

use RuntimeException;
use Throwable;

/**
 * Core menjawab, dan jawabannya "tidak".
 *
 * Kelasnya sendiri supaya lapisan HTTP dapat memulangkannya sebagai kesalahan formulir yang
 * terbaca operator — email sudah terdaftar, app tidak tersedia, kunci salah — alih-alih halaman
 * 500 yang menyembunyikan sebabnya. Polanya sama dengan `EnvironmentRejected` di sebelahnya.
 *
 * Yang ditambahkan di sini: penolakan Core sering **milik satu isian tertentu**, dan Core sudah
 * menyebutkannya. Membawa petanya utuh membuat pesan "Email sudah terdaftar" mendarat di kotak
 * email, bukan di bagian atas dialog tempat mata harus mencari isian mana yang dimaksud.
 */
class CustomerRejected extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $fieldErrors  Alasan per isian, apa adanya dari Core.
     */
    public function __construct(
        string $message,
        private readonly array $fieldErrors = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    /** @return array<string, list<string>> */
    public function fieldErrors(): array
    {
        return $this->fieldErrors;
    }
}
