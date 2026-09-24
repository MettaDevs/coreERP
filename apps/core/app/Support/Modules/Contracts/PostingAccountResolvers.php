<?php

declare(strict_types=1);

namespace App\Support\Modules\Contracts;

/**
 * Daftar pemeta akun module, diisi penyedia layanan tiap module saat boot.
 *
 * Arahnya sama dengan `DaftarLaporan`: module yang melayani Core. Yang dipanggil ditentukan
 * `source_document.module` posting, jadi daftarnya satu benda untuk seluruh proses, bukan binding
 * tunggal. Module tanpa pemeta bukan kesalahan: posting-nya dibentuk ulang dari akun yang tersimpan.
 */
interface PostingAccountResolvers
{
    public function register(PostingAccountResolver $resolver): void;

    public function for(string $moduleId): ?PostingAccountResolver;
}
