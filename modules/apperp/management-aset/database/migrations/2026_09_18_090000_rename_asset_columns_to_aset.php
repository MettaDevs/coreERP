<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menyeragamkan ejaan: `asset` menjadi `aset`, sesuai KBBI.
 *
 * **Migration lama tidak disunting.** Semuanya tetap membuat kolom bernama lama, lalu
 * berkas ini yang mengganti namanya - aturan yang sama yang dipegang
 * `2026_09_08_130000_prefix_tabel_modul.php`. Menyunting migration lama akan membuat
 * database yang dipasang dari nol menempuh jalur berbeda dari database yang sudah
 * berjalan, padahal keduanya harus berakhir pada bentuk yang sama.
 *
 * **Kenapa tiap langkah tetap dijaga `hasColumn`.** Perintah pemasangan harus aman
 * diulang. Penjaga membuat berkas ini tidak apa-apa dijalankan dua kali, dan tidak gagal
 * pada tabel yang belum pernah dibuat karena modulnya baru dipasang sebagian.
 *
 * Nama berkas migration lama juga tidak diubah: nama berkas adalah identitas perubahan
 * yang sudah diterapkan, bukan nama yang dibaca siapa pun di produk. Mengubahnya membuat
 * Laravel menganggapnya migrasi baru dan menjalankannya ulang di setiap database yang
 * sudah ada.
 *
 * @kontrak Modul ini belum terpasang di satu pun klien on-prem, jadi tidak ada rilis
 * sebelumnya yang membaca nama kolom lama di server mana pun. Itu alasan yang sama yang
 * membuat daftar beku pada `MigrasiKompatibelMundurTest` ada. Begitu modul ini terpasang
 * di klien pertama, penggantian nama berikutnya wajib menempuh expand/contract.
 */
return new class extends Migration
{
    /** @var array<string, array<string, string>> tabel => (kolom lama => kolom baru) */
    private const KOLOM = [
        'aset_m_group_aset' => ['asset_location_id' => 'lokasi_aset_id'],
        'aset_m_maintenance_job_type_default' => ['asset_id' => 'aset_id'],
        'aset_tr_aset_atribut' => ['asset_id' => 'aset_id'],
        'aset_tr_buku_aset' => ['asset_id' => 'aset_id'],
        'aset_tr_dokumen_siklus_aset' => ['asset_id' => 'aset_id'],
        'aset_tr_mutasi_aset_details' => ['asset_id' => 'aset_id'],
        'aset_tr_pemeliharaan_aset_details' => [
            'asset_id' => 'aset_id',
            'asset_location_id' => 'lokasi_aset_id',
        ],
        'aset_tr_penempatan_aset' => [
            'asset_id' => 'aset_id',
            'asset_location_id' => 'lokasi_aset_id',
        ],
        'aset_tr_penerimaan_aset' => [
            'asset_location_id' => 'lokasi_aset_id',
            'parent_asset_id' => 'induk_aset_id',
        ],
        'aset_tr_penyusutan_aset' => ['asset_book_id' => 'buku_aset_id'],
        'aset_tr_perencanaan_aset_details' => ['asset_name' => 'nama_aset'],
        'aset_tr_permintaan_pengadaan_aset_details' => ['asset_name' => 'nama_aset'],
    ];

    private const TABEL = [
        'aset_m_maintenance_job_type_asset_type' => 'aset_m_maintenance_job_type_jenis_aset',
    ];

    public function up(): void
    {
        foreach (self::TABEL as $lama => $baru) {
            if (Schema::hasTable($lama) && ! Schema::hasTable($baru)) {
                Schema::rename($lama, $baru);
            }
        }

        foreach (self::KOLOM as $tabel => $kolom) {
            if (! Schema::hasTable($tabel)) {
                continue;
            }

            Schema::table($tabel, function (Blueprint $table) use ($tabel, $kolom): void {
                foreach ($kolom as $lama => $baru) {
                    if (Schema::hasColumn($tabel, $lama) && ! Schema::hasColumn($tabel, $baru)) {
                        $table->renameColumn($lama, $baru);
                    }
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::KOLOM as $tabel => $kolom) {
            if (! Schema::hasTable($tabel)) {
                continue;
            }

            Schema::table($tabel, function (Blueprint $table) use ($tabel, $kolom): void {
                foreach ($kolom as $lama => $baru) {
                    if (Schema::hasColumn($tabel, $baru) && ! Schema::hasColumn($tabel, $lama)) {
                        $table->renameColumn($baru, $lama);
                    }
                }
            });
        }

        foreach (self::TABEL as $lama => $baru) {
            if (Schema::hasTable($baru) && ! Schema::hasTable($lama)) {
                Schema::rename($baru, $lama);
            }
        }
    }
};
