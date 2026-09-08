<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seluruh tabel modul diberi awalan `aset_`.
 *
 * Inilah yang memisahkan data modul ini dari data Core dan modul lain. Tanpa awalan,
 * `m_lokasi_aset`, `m_trade`, `m_tingkat_layanan`, dan sembilan tabel pemeliharaan akan
 * bertabrakan dengan modul lain yang wajar memakai nama yang sama. Satu tabel bahkan tidak
 * punya penanda kepemilikan sama sekali — `processed_core_events` — dan itu hampir pasti
 * bentrok dengan modul lain yang menyaring kejadian ganda.
 *
 * **Daftar tabelnya dibaca dari katalog PostgreSQL, bukan ditulis tangan.** Daftar yang ditulis
 * tangan akan tertinggal satu tabel pada hari seseorang menambah migration baru sebelum
 * migration ini dijalankan di suatu lingkungan, dan tabel yang tertinggal itu tidak gagal
 * dengan sendirinya — ia hanya diam sampai bertabrakan dengan modul lain.
 *
 * **Migration lama sengaja tidak disunting.** Semuanya tetap membuat tabel bernama lama, lalu
 * migration ini yang mengganti namanya. Menyunting migration lama akan mengubah riwayat yang
 * sudah dijalankan tenant yang ada, dan itu melanggar aturan bahwa perintah pemasangan harus
 * aman diulang.
 */
return new class extends Migration
{
    /**
     * Pola nama yang dipakai modul ini sebelum berawalan.
     *
     * `m_` master, `tr_` transaksi, `t_` sisa penamaan lama yang belum diseragamkan, ditambah
     * satu tabel tanpa penanda apa pun. Diperiksa pada 8 September 2026: tidak satu pun dari
     * 93 tabel Core yang cocok dengan pola ini, jadi pemindaian tidak bisa salah menyeret tabel
     * milik Core.
     *
     * @var list<string>
     */
    private const POLA = ['m\_%', 'tr\_%', 't\_%'];

    /** @var list<string> */
    private const TANPA_PENANDA = ['processed_core_events'];

    private const AWALAN = 'aset_';

    public function up(): void
    {
        foreach ($this->tabelModul() as $lama) {
            $baru = self::AWALAN.$lama;

            if (Schema::hasTable($baru)) {
                throw new RuntimeException(sprintf(
                    'Tabel %s sudah ada, jadi %s tidak bisa diganti namanya. Jalankan migration ini '.
                    'pada schema yang belum pernah menerimanya, atau selesaikan penggantian yang setengah jalan.',
                    $baru,
                    $lama,
                ));
            }

            Schema::rename($lama, $baru);
        }
    }

    public function down(): void
    {
        foreach ($this->tabelBerawalan() as $baru) {
            Schema::rename($baru, substr($baru, strlen(self::AWALAN)));
        }
    }

    /**
     * Tabel modul yang belum berawalan, dibaca dari katalog.
     *
     * @return list<string>
     */
    private function tabelModul(): array
    {
        $kondisi = [];
        $ikatan = [];

        foreach (self::POLA as $pola) {
            $kondisi[] = 'tablename LIKE ?';
            $ikatan[] = $pola;
        }

        foreach (self::TANPA_PENANDA as $nama) {
            $kondisi[] = 'tablename = ?';
            $ikatan[] = $nama;
        }

        $baris = DB::select(
            'SELECT tablename FROM pg_tables WHERE schemaname = current_schema() AND ('.implode(' OR ', $kondisi).') ORDER BY tablename',
            $ikatan,
        );

        return array_values(array_map(static fn ($b): string => $b->tablename, $baris));
    }

    /** @return list<string> */
    private function tabelBerawalan(): array
    {
        $baris = DB::select(
            'SELECT tablename FROM pg_tables WHERE schemaname = current_schema() AND tablename LIKE ? ORDER BY tablename',
            [self::AWALAN.'%'],
        );

        return array_values(array_map(static fn ($b): string => $b->tablename, $baris));
    }
};
