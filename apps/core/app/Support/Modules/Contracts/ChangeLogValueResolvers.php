<?php

namespace App\Support\Modules\Contracts;

/**
 * Daftar penerjemah nilai log perubahan, diisi penyedia layanan tiap module saat boot.
 *
 * Satu benda untuk seluruh proses (`CoreServices::PEMETAAN_TUNGGAL`), supaya pendaftaran module dan
 * pembacaan riwayat memegang daftar yang sama. Tabel tanpa penerjemah bukan kesalahan: nilainya tampil apa
 * adanya.
 */
interface ChangeLogValueResolvers
{
    public function register(ChangeLogValueResolver $resolver): void;

    public function for(string $table): ?ChangeLogValueResolver;
}
