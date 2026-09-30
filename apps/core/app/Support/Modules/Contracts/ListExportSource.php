<?php

declare(strict_types=1);

namespace App\Support\Modules\Contracts;

/**
 * Satu daftar di layar module yang dapat diekspor lewat antrean ekspor Core (K-27), padanan "Open in
 * Excel" pada list page Business Central: baris dan kolom yang tampil, dengan filter halaman itu, tanpa
 * layout.
 *
 * Core tidak pernah membaca tabel module. Ia hanya menyimpan permintaannya, memeriksa hak menjalankannya,
 * lalu meminta baris kepada pemilik daftar di worker antrean dan menuliskannya ke berkas. Module yang
 * menegakkan permission baca dan kebijakan data organisasi, **persis seperti daftar di layarnya**: ekspor
 * tidak boleh memuat baris yang tidak dapat dilihat pengguna itu di layar.
 *
 * `$context` berbentuk sama dengan konteks laporan ({@see PenyediaLaporanModul}): `tenant_id`,
 * `legal_entity_id`, `org_unit_id`, `user_id`, `permissions`, `data_policies`, dan `timezone`. Ia dibawa
 * sebagai argumen karena ekspor berjalan di worker tanpa permintaan HTTP.
 *
 * Kegagalan disampaikan sebagai `RuntimeException` dengan pesan siap-baca; Core yang menerjemahkannya
 * menjadi ekspor gagal.
 */
interface ListExportSource
{
    /** Id module pemilik daftar, sama dengan `id` pada `app.yaml`. */
    public function moduleId(): string;

    /** Kode daftar di dalam module, misalnya `aset`. Kode ini tersimpan di riwayat ekspor. */
    public function listCode(): string;

    /** Nama daftar untuk pengguna, misalnya "Register aset"; menjadi nama ekspor di tray. */
    public function name(): string;

    /** Permission yang menjaga daftar ini di layar. Mengekspor menuntut hak yang sama dengan melihat. */
    public function permission(): string;

    /**
     * Kolom yang dapat diekspor, dengan kunci yang sama dengan id kolom tabel di layar. `type` memakai tipe
     * nilai laporan (`money`, `number`, `percent`, `date`, `month`, `datetime`) supaya Excel menerima angka
     * dan tanggal asli; kolom tanpa tipe dikirim sebagai teks siap tampil, misalnya nama, bukan id.
     *
     * @return list<array{key: string, label: string, type?: string}>
     */
    public function columns(): array;

    /**
     * Aturan validasi Laravel untuk filter daftar, sama dengan yang diterima endpoint daftarnya.
     *
     * @return array<string, list<mixed>>
     */
    public function filterRules(): array;

    /**
     * Jumlah baris yang cocok, untuk memilih xlsx atau CSV sebelum menulis.
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $filters
     */
    public function count(array $context, array $filters): int;

    /**
     * Baris yang cocok dengan filter, dalam urutan `$sort`, dibaca bertahap supaya memori tetap datar
     * berapa pun jumlahnya. Setiap baris berkunci kolom dari {@see columns()}.
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $filters
     * @param  array{column: string, direction: string}|null  $sort  `direction` bernilai `asc` atau `desc`.
     * @return iterable<array<string, string|int|float|null>>
     */
    public function rows(array $context, array $filters, ?array $sort): iterable;
}
