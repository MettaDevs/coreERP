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
 *         'timezone' => string,
 *     ]
 *
 * `timezone` adalah zona waktu pengguna yang meminta (nama IANA), dihitung Core dari setelan My Profile
 * atau entitas legalnya. Module memakainya untuk "hari ini" — periode bawaan, nama berkas — dan tidak
 * pernah memakai jam server atau zona aplikasi untuk itu.
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
     * Katalog laporan module, dibaca `app:register-manifest`: kode lengkap berawalan id module,
     * nama, keterangan, permission data, nama parameter, dan layout bawaannya.
     *
     * Ini satu-satunya sumber katalog laporan module. Sampai 28 September 2026 isi yang sama
     * juga ditulis di blok `reports` manifest, dan keduanya menyimpang: laporan yang terlewat
     * di manifest tampil di pratinjau, lalu tombol Cetak-nya menjawab 404. Di Business Central
     * objek report adalah satu-satunya sumbernya, dan di sini definisi laporan module yang
     * mengambil peran itu.
     *
     * Tanpa konteks pengguna: katalog berlaku untuk semua tenant, dan izin ditegakkan saat
     * laporan dijalankan, bukan saat didaftarkan.
     *
     * @return list<array{code: string, name: string, description: ?string, permission: string, parameters: list<string>, builtin_layouts: list<array{key: string, name: string, description: ?string, format: string}>}>
     */
    public function catalog(): array;

    /**
     * Placeholder dan nama parameter satu laporan.
     *
     * Placeholder boleh menyatakan `type` — `money`, `number`, `percent`, `date`, `month`,
     * atau `datetime` — dan nilainya di dataset lalu dikirim mentah: uang dan angka sebagai angka,
     * persen sebagai angka (`12.5` untuk 12,5%), tanggal `Y-m-d`, bulan `Y-m`, dan waktu dalam UTC
     * (`Y-m-d H:i:s` atau ISO 8601). Core yang memformatnya per keluaran; waktu ditulis menurut zona
     * pengguna beserta nama zonanya. Placeholder tanpa `type` dianggap sudah siap tampil.
     *
     * `data_items` padanan `dataitem` BC (K-30): tabel yang boleh diberi filter tambahan pengguna, dengan
     * katalog kolomnya dari {@see TableFields} dan kolom bawaan yang langsung tampil (`RequestFilterFields`).
     * Filter tambahan dikirim kembali ke `dataset()` sebagai parameter `filters[<data item>][<kolom>]`, dan
     * module yang menerapkannya lewat {@see FieldFilterExpression}. Laporan tanpa data item memulangkan
     * daftar kosong.
     *
     * @param  array<string, mixed>  $konteks
     * @return array{fields: list<array{key: string, label: string, table: ?string, type?: string}>, parameters: list<string>, data_items: list<array{key: string, caption: string, default_fields: list<string>, fields: list<array{key: string, caption: string, type: string, options?: list<array{value: string, label: string}>, lookup?: string}>}>}
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
