<?php

namespace App\Platform\Modules\Contracts;

/**
 * Penerjemah nilai log perubahan satu tabel menjadi teks yang dikenali pengguna.
 *
 * Log menyimpan nilai mentah — ULID lokasi, kode status — karena itulah yang tersimpan di barisnya. Yang
 * dibaca orang adalah nama lokasi dan label status, dan hanya pemilik tabel yang tahu cara menerjemahkannya.
 * Module mendaftarkan penerjemahnya ke {@see ChangeLogValueResolvers} saat boot, seperti penyedia laporan dan
 * pemeta akunnya.
 */
interface ChangeLogValueResolver
{
    /** Nama tabel yang nilainya diterjemahkan. */
    public function table(): string;

    /**
     * Nilai mentah satu field menjadi teks tampilan. Nilai yang tidak dikenali boleh dilewati; layar lalu
     * menampilkan nilai mentahnya.
     *
     * @param  list<string>  $values
     * @return array<string, string>
     */
    public function display(string $tenantId, string $field, array $values): array;
}
