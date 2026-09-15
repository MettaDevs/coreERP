<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pemasangan pertama sebuah server klien memperoleh nama operasinya sendiri: `install`.
 *
 * ## Kenapa bukan `upgrade`
 *
 * Bentuk langkahnya memang mirip — unduh berkas rilis, periksa tanda tangan, jalankan `update.sh` —
 * dan menumpang `upgrade` terasa lebih hemat. Ditolak karena dua aturannya berlawanan:
 *
 * - `upgrade` menunggu **jendela pembaruan** yang disepakati dengan klien, karena ia menghentikan
 *   aplikasi yang sedang dipakai orang. Pemasangan pertama tidak menghentikan apa pun — belum ada yang
 *   memakainya — dan operator yang baru saja menempelkan perintah pasang sedang menunggu di depan
 *   layar. Menahannya sampai jendela malam hari berarti perintah pasang yang "menggantung" berjam-jam.
 * - `install` melahirkan tenant beserta owner-nya lewat `tenant:bootstrap-site`, dan membawa hash kata
 *   sandi sementaranya. `upgrade` tidak pernah boleh membawa itu.
 *
 * Dua aturan berbeda di bawah satu nama berarti setiap pembaca harus menebak operasi mana yang sedang
 * dibacanya dari isi parameternya. `site_operations` ada supaya riwayat server klien terbaca tanpa
 * membuka kode; nama yang menyesatkan adalah bentuk lain dari riwayat yang tidak terbaca.
 *
 * ## Kenapa constraint-nya dibuang lalu dipasang, dengan nama yang sama
 *
 * PostgreSQL tidak mengenal "ubah CHECK". Namanya sengaja tetap supaya pesan galat yang sudah dikenali
 * kode dan test konsol tidak berubah — alasan yang sama dengan
 * `2026_09_12_110000_add_convert_to_environment_operation_kinds`.
 *
 * Indeks `site_operations_satu_permintaan_per_jenis` tidak disentuh dan langsung berlaku untuk jenis
 * baru ini: satu permintaan pasang per situs, jadi "Buat perintah pasang" yang ditekan dua kali tidak
 * melahirkan dua pemasangan yang saling menunggu.
 */
return new class extends Migration
{
    /** Jenis operasi situs yang dikenal, sesudah `install` bergabung. */
    private const JENIS = ['install', 'upgrade', 'backup', 'install_license', 'rotate_key', 'send_diagnostics'];

    public function up(): void
    {
        $this->pasang(self::JENIS);
    }

    public function down(): void
    {
        // Barisnya ikut turun, kalau tidak constraint lamanya menolak dipasang kembali. Sama seperti
        // pasangannya di registry lingkungan, ini hanya berjalan pada jalur mundur sebuah rilis —
        // bukan pada jalur mana pun yang dijalankan operator.
        DB::table('site_operations')->where('operation', 'install')->delete();

        $this->pasang(array_values(array_diff(self::JENIS, ['install'])));
    }

    /** @param  list<string>  $jenis */
    private function pasang(array $jenis): void
    {
        $daftar = implode(', ', array_map(static fn (string $nama): string => "'".$nama."'", $jenis));

        DB::statement('ALTER TABLE site_operations DROP CONSTRAINT IF EXISTS site_operations_jenis_dikenal');
        DB::statement(
            'ALTER TABLE site_operations ADD CONSTRAINT site_operations_jenis_dikenal '
            .'CHECK (operation IN ('.$daftar.'))'
        );
    }
};
