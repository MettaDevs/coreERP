<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Konversi demo menjadi produksi memperoleh nama operasinya sendiri: `convert`.
 *
 * ## Kenapa constraint ini disentuh sama sekali
 *
 * `environment_operations_jenis_dikenal` menyebut sepuluh jenis operasi, dan tidak satu pun
 * menggambarkan apa yang dikerjakan `environment:konversi`. Pilihannya karena itu dua: menumpang
 * salah satu nama yang sudah ada, atau menambah satu.
 *
 * Yang paling dekat bentuknya adalah `migrate`, dan ia sempat dipertimbangkan justru karena
 * menambah nilai ke sebuah CHECK berarti menyentuh skema — pekerjaan yang seharusnya dihindari
 * bila tidak perlu. Ia **ditolak**, dan alasannya bukan selera.
 *
 * Di registry ini `migrate` sudah punya arti yang sangat spesifik dan sudah dipakai: menjalankan
 * migration ke dalam sebuah database environment, lalu menulis `schema_migrated_at` dan
 * `schema_fingerprint` pada barisnya. Seluruh rencana penyebaran migration ke banyak environment
 * berdiri di atas arti itu — "environment mana yang tertinggal" dijawab dengan membandingkan sidik
 * skema, dan riwayat operasi `migrate` adalah tempat orang mencari kenapa sebuah environment
 * tertinggal.
 *
 * Kalau konversi ikut bernama `migrate`, pertanyaan itu berhenti dapat dijawab dari tabel ini: dua
 * hal yang tidak berhubungan berbagi satu nama, dan yang satu tidak menyentuh skema sama sekali.
 * `environment_operations` ada persis supaya seorang operator dapat membaca apa yang terjadi tanpa
 * membaca kode — cacat berulang repo ini adalah kegagalan yang tidak dapat dibaca siapa pun
 * keesokan harinya. Nama yang menyesatkan adalah bentuk lain dari cacat yang sama.
 *
 * ## Kenapa hanya ini yang diubah
 *
 * Tidak satu pun constraint pada `environments` disentuh, dan itu disengaja. Semuanya memang
 * menghalangi konversi yang naif — dan justru karena itu mereka benar:
 *
 * - `environments_keluar_ikut_jenis` memaksa jenis dan bendera sambungan keluar berubah dalam satu
 *   pernyataan. Yang dihalanginya adalah produksi yang sambungan keluarnya masih mati, atau demo
 *   yang sudah boleh menghubungi pelanggan sungguhan. Keduanya keadaan yang tidak boleh ada.
 * - `environments_satu_produksi` menolak konversi ketika tenant itu sudah punya produksi hidup.
 *   Itu bukan halangan melainkan jawabannya: "dua produksi" bukan keadaan yang sistem ini kenal,
 *   dan perintahnya menerjemahkan penolakan itu menjadi kalimat yang terbaca.
 * - `environments_demo_berakhir` hanya mewajibkan **demo** punya tanggal berakhir. Produksi boleh
 *   tidak punya, jadi melepas tanggalnya sah — dan perintahnya melepasnya karena tanggal berakhir
 *   yang tertinggal pada baris produksi adalah pelanggan membayar yang dihapus sapuan kedaluwarsa.
 *
 * Satu-satunya yang ternyata **tidak** menggigit adalah `environments_sumber_hanya_sandbox`: ia
 * melarang baris berjenis apa pun selain `sandbox` membawa `source_environment_id`, sehingga sebuah
 * demo tidak pernah bisa lahir dengan kolom itu terisi sejak awal. Baris yang dikonversi karena itu
 * selalu membawanya kosong, dan naik menjadi produksi tidak pernah melanggarnya. Ia dibiarkan utuh.
 */
return new class extends Migration
{
    /** Jenis operasi yang dikenal, sesudah `convert` bergabung. */
    private const JENIS = [
        'provision', 'copy', 'migrate', 'convert', 'disarm', 'suspend',
        'resume', 'soft_delete', 'restore', 'purge', 'expire',
    ];

    public function up(): void
    {
        $this->pasang(self::JENIS);
    }

    public function down(): void
    {
        // Barisnya ikut turun, kalau tidak constraint lamanya menolak dipasang kembali. Ini
        // satu-satunya tempat di repo ini yang menghapus riwayat operasi, dan ia hanya berjalan
        // pada jalur mundur sebuah rilis — bukan pada jalur mana pun yang dijalankan operator.
        DB::table('environment_operations')->where('operation', 'convert')->delete();

        $this->pasang(array_values(array_diff(self::JENIS, ['convert'])));
    }

    /**
     * Menukar isi CHECK-nya, dan namanya sengaja tidak berubah.
     *
     * PostgreSQL tidak mengenal "ubah CHECK"; yang ada hanya buang lalu pasang. Nama yang tetap
     * membuat pesan galatnya tetap sama dengan yang sudah dikenali kode dan test — sebuah nama baru
     * akan membuat setiap penangkap yang mencocokkan nama constraint berhenti bekerja diam-diam.
     *
     * @param  list<string>  $jenis
     */
    private function pasang(array $jenis): void
    {
        $daftar = implode(', ', array_map(static fn (string $nama): string => "'".$nama."'", $jenis));

        DB::statement('ALTER TABLE environment_operations DROP CONSTRAINT IF EXISTS environment_operations_jenis_dikenal');
        DB::statement(
            'ALTER TABLE environment_operations ADD CONSTRAINT environment_operations_jenis_dikenal '
            .'CHECK (operation IN ('.$daftar.'))'
        );
    }
};
