<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Daftar parameter workflow yang bisa diatur tenant: kode, tipe, bawaan, dan kalimatnya.
 *
 * **Ini satu-satunya tempat sebuah parameter dinyatakan ada.** Penyimpanannya baris per kode,
 * pembacanya generik, dan layar settings merender dirinya dari daftar ini — jadi menambah
 * parameter berarti menambah satu entri di sini, lalu memasang satu titik penegakan di tempat
 * yang memang terpengaruh.
 *
 * Bentuk sebelumnya menuntut tiga suntingan untuk setiap parameter: satu kolom pada skema, satu
 * method pembaca, dan satu blok pada layar. Untuk satu parameter itu tidak terasa; untuk empat
 * puluh — jumlah yang dimiliki D365 F&O hari ini — itu empat puluh migration yang harus berhasil
 * di server setiap pelanggan.
 *
 * **Yang sengaja tidak digeneralisir: penegakannya.** Sebuah parameter berarti sesuatu yang
 * spesifik pada titik yang spesifik di dalam mesin, dan menyatukan seluruhnya di bawah satu
 * "penerap parameter" berarti menanam mesin aturan di dalam mesin workflow. Daftar ini
 * menghapus biaya pengelolaan parameter; ia tidak berpura-pura menghapus biaya memikirkan
 * artinya.
 *
 * Menghapus entri dari daftar ini tidak menghapus barisnya di database. Itu disengaja: baris
 * yatim tidak mengubah perilaku apa pun — pembacanya hanya mengenal kode yang terdaftar —
 * sementara menghapusnya berarti membuang pilihan yang pernah diambil pelanggan tanpa bisa
 * dikembalikan bila parameternya ternyata masih dipakai.
 */
final class DefinisiParameterWorkflow
{
    public const LARANG_PERSETUJUAN_PENGAJU = 'disallow_approval_by_submitter';

    /**
     * @var array<string, array{tipe: 'boolean', bawaan: bool, label: string, penjelasan: string}>
     *
     * `tipe` baru mengenal `boolean` karena baru itu yang dibutuhkan. Menambah tipe lain adalah
     * pekerjaan tersendiri: ia menyentuh pembacaan, validasi, dan kendali layarnya sekaligus.
     */
    public const DAFTAR = [
        self::LARANG_PERSETUJUAN_PENGAJU => [
            'tipe' => 'boolean',

            // Bawaannya sama dengan D365: pengaju **boleh** menyetujui kecuali tenant melarang.
            // Ini melonggarkan perilaku yang berlaku sebelumnya, dan itu keputusan pemilik
            // produk yang diambil sadar — D365 dipakai sebagai sumber rancangan sejak awal, dan
            // menyimpang dari bawaannya berarti dua sistem yang mirip menjawab berbeda untuk
            // pertanyaan yang sama.
            'bawaan' => false,

            'label' => 'Larang pengaju menyetujui dokumennya sendiri',
            'penjelasan' => 'Bawaannya mati: pengaju boleh menyetujui dokumennya sendiri selama ia memang termasuk penerima tugas langkah persetujuan. Nyalakan bila pemisahan tugas dituntut. Ketika menyala, pengaju dikeluarkan dari daftar penerima tugas, dan langkah yang penerimanya tinggal dia sendiri akan ditolak beserta alasannya.',
        ],
    ];

    public static function dikenal(string $kode): bool
    {
        return array_key_exists($kode, self::DAFTAR);
    }

    /** @return array<string, bool> */
    public static function bawaan(): array
    {
        return array_map(
            static fn (array $definisi): bool => $definisi['bawaan'],
            self::DAFTAR,
        );
    }
}
