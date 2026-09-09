<?php

declare(strict_types=1);

namespace App\Support\Modules\Contracts;

/**
 * Laporan yang dimiliki sebuah module, dibaca Core langsung di dalam proses.
 *
 * Sebelum ini mesin laporan Core memanggil endpoint HTTP module dengan token konteks
 * pengguna, dan module memeriksa ulang izin yang barusan diperiksa Core. Dua proses
 * memang menuntut itu. Satu proses tidak: yang tersisa hanyalah biaya jaringan, satu
 * lapis serialisasi, dan sebuah alamat yang harus benar sebelum laporan bisa dicetak.
 *
 * **Konteks dibawa sebagai argumen, bukan dibaca dari permintaan.** Ekspor laporan
 * berjalan di worker antrean, tempat tidak ada `Request` maupun sesi. Pada jalur HTTP
 * lama konteks itu ikut sebagai token; di sini ia harus ikut sebagai parameter, karena
 * satu-satunya alternatifnya adalah keadaan global yang benar pada permintaan biasa dan
 * kosong pada worker — persis kegagalan yang paling sulit ditemukan.
 *
 * Bentuk `$konteks` sama dengan yang dulu dibawa token, dan kuncinya sengaja tidak
 * berubah supaya kode module yang membacanya tidak perlu diubah:
 *
 *     [
 *         'tenant_id' => string,
 *         'legal_entity_id' => ?string,
 *         'org_unit_id' => ?string,
 *         'user_id' => string,
 *         'permissions' => list<string>,
 *         'data_policies' => array<string, mixed>,
 *     ]
 *
 * Module tetap memeriksa izinnya sendiri di sini. Itu bukan pemeriksaan ganda yang
 * mubazir: Core memeriksa "boleh menjalankan laporan ini", module memeriksa "boleh
 * membaca data yang dilaporkan", dan yang kedua tetap perlu ada walau pemanggilnya
 * berpindah dari jaringan ke pemanggilan fungsi.
 *
 * Kegagalan disampaikan sebagai `RuntimeException` dengan pesan siap-baca. Module tidak
 * boleh menyebut kelas pengecualian Core — ia hanya boleh menyebut kontrak ini — jadi
 * penerjemahannya menjadi kegagalan laporan dikerjakan Core di sisi pemanggil.
 */
interface PenyediaLaporanModul
{
    /** Id module pemilik laporan, sama dengan `id` pada `app.yaml`. */
    public function idModule(): string;

    public function punya(string $kodeLaporan): bool;

    /**
     * Placeholder dan nama parameter satu laporan.
     *
     * @param  array<string, mixed>  $konteks
     * @return array{fields: list<array{key: string, label: string, table: ?string}>, parameters: list<string>}
     */
    public function definisi(string $kodeLaporan, array $konteks): array;

    /**
     * Isi berkas layout bawaan yang ikut module.
     *
     * @param  array<string, mixed>  $konteks
     */
    public function layoutBawaan(string $kodeLaporan, string $kunci, array $konteks): string;

    /**
     * Dataset laporan: nilai tunggal pada `fields`, baris berulang pada `tables`.
     *
     * @param  array<string, mixed>  $konteks
     * @param  array<string, mixed>  $parameter
     * @return array{fields: array<string, mixed>, tables: array<string, mixed>, file_name: string}
     */
    public function dataset(string $kodeLaporan, array $konteks, array $parameter): array;
}
