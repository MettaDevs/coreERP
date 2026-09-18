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
    /**
     * `id` adalah id keanggotaan, `user_id` id penggunanya.
     *
     * Keduanya dipulangkan karena module memakai keduanya untuk hal berbeda: keanggotaan
     * adalah yang ditautkan dan divalidasi, sedangkan `coreerp.user_id` pada konteks
     * permintaan — yang tersimpan di kolom "dibuat oleh" dan "diserahkan oleh" milik
     * module — adalah id pengguna. Tanpa keduanya, module tidak dapat menerjemahkan id
     * yang ia simpan sendiri menjadi nama yang dikenali orang.
     *
     * @return list<array{id: string, user_id: string, nama: string, email: string}>
     */
    public function anggota(string $tenantId): array;

    /** @return array{id: string, user_id: string, nama: string, email: string}|null */
    public function anggotaSatu(string $tenantId, string $membershipId): ?array;

    /** @return list<array{id: string, nama: string, klasifikasi: string}> */
    public function unitOperasi(string $tenantId): array;
}
