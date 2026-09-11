<?php

declare(strict_types=1);

namespace App\Support\Modules\Contracts;

/**
 * Membaca anggota tenant dan unit organisasinya.
 *
 * Hari ini module membacanya lewat tiga endpoint HTTP internal. Antarmuka ini menggantikan
 * ketiganya dengan pemanggilan fungsi, dan mengembalikan bentuk yang sama sederhananya:
 * daftar baris, bukan model Core.
 */
interface DirektoriOrganisasi
{
    /** @return list<array{id: string, nama: string, email: string}> */
    public function anggota(string $tenantId): array;

    /** @return array{id: string, nama: string, email: string}|null */
    public function anggotaSatu(string $tenantId, string $membershipId): ?array;

    /** @return list<array{id: string, nama: string, klasifikasi: string}> */
    public function unitOperasi(string $tenantId): array;
}
