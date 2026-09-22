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

    /**
     * Seluruh operating unit tenant, termasuk yang sudah tidak aktif.
     *
     * Yang tidak aktif ikut dipulangkan karena pemakai utamanya menerjemahkan id yang sudah
     * tersimpan di dokumen lama menjadi nama; unit yang dinonaktifkan tidak boleh membuat
     * dokumen itu kehilangan namanya.
     *
     * `tipe` adalah tipe operating unit (`business_unit`, `department`, dan seterusnya).
     * `nomor` adalah nomor unit — kode stabil yang dipakai sebagai nilai dimensi keuangan —
     * dan `null` selama belum diisi di layar organisasi.
     *
     * @return list<array{id: string, nama: string, klasifikasi: string, tipe: ?string, nomor: ?string}>
     */
    public function unitOperasi(string $tenantId): array;

    /**
     * Business unit induk dari tiap operating unit, pada hierarki manajemen yang berlaku di tanggal itu.
     *
     * Padanan *derived dimension* Dynamics 365 F&O: department membawa business unit-nya sendiri
     * lewat struktur organisasi, tanpa tabel aturan tambahan. Yang dicari adalah leluhur terdekat
     * bertipe `business_unit` — termasuk unit itu sendiri, jadi business unit memulangkan dirinya.
     *
     * Hierarkinya adalah versi `published` yang berlaku pada `$tanggal` dari setiap hierarki aktif
     * bertujuan `management`. Unit yang tidak ada di hierarki mana pun, atau yang di dua hierarki
     * manajemen menunjuk business unit berbeda, memulangkan `null`: menebak di sini berarti jurnal
     * masuk ke klinik yang salah tanpa satu pun kesalahan terlihat.
     *
     * @param  list<string>  $orgUnitIds
     * @return array<string, array{id: string, nama: string, nomor: ?string}|null> Berkunci id operating unit yang ditanyakan.
     */
    public function unitBisnisInduk(string $tenantId, array $orgUnitIds, string $tanggal): array;
}
