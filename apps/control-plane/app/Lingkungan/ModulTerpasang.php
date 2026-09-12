<?php

declare(strict_types=1);

namespace ControlPlane\Lingkungan;

use ControlPlane\Models\Lingkungan;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Membaca module apa yang benar-benar terpasang di sebuah lingkungan.
 *
 * ## Kenapa ia harus membuka database lain
 *
 * Karena catatan pemasangan tidak tinggal di database pusat. `core_module_installations` hidup di
 * dalam database lingkungan itu sendiri, berdampingan dengan riwayat migration yang ia gambarkan —
 * alasan lengkapnya di `App\Actions\Modules\InstallModule` milik Core. Jadi pertanyaan "HR terpasang
 * di demo ini?" hanya bisa dijawab dari sana, dan menyimpulkannya dari entitlement tenant akan
 * menjawab pertanyaan yang berbeda: apa yang **boleh** ada, bukan apa yang **ada**.
 *
 * Itu juga yang membuat layar ini berguna. Perbedaan antara keduanya persis yang ingin dilihat
 * operator sesudah penyiapan berjalan — dan perbedaan itu menghilang kalau kita membaca daftar yang
 * sama dua kali.
 *
 * ## Kenapa kegagalan membacanya bukan galat
 *
 * Lingkungan bisa saja belum punya database, databasenya sedang tidak dapat dihubungi, atau
 * migrationnya belum pernah berjalan sehingga tabelnya belum ada. Ketiganya keadaan yang sah, dan
 * ketiganya artinya sama bagi layar ini: belum ada yang bisa ditampilkan. Menjatuhkan seluruh
 * halaman rincian karena satu daftar tambahan berarti operator kehilangan justru riwayat operasi
 * yang menjelaskan kenapa daftarnya kosong.
 */
final class ModulTerpasang
{
    /**
     * @return list<array{id: string, nama: string, versi: string, status: string, disemai: bool}>
     */
    public function __invoke(Lingkungan $lingkungan): array
    {
        $database = $lingkungan->database_name;

        if (! is_string($database) || $database === '') {
            /*
             * Tidak punya database sendiri, dan artinya bercabang dua.
             *
             * Lingkungan **produksi** yang `database_name`-nya kosong memang tinggal di database
             * bawaan — itu keadaan produksi hari ini, keadaan on-prem, dan keadaan pooled. Tabelnya
             * ada di tempat kita sudah berdiri.
             *
             * Lingkungan lain yang `database_name`-nya kosong **belum punya database sama sekali**,
             * jadi ia belum memuat satu pun module. Membacanya dari database bawaan akan memulangkan
             * pemasangan milik produksi tenant yang sama — dan layar ini akan mengaku sebuah demo
             * yang belum disiapkan sudah berisi produk yang dibeli pelanggannya.
             *
             * Ditemukan dengan menjalankannya, bukan oleh test: demo yang baru dibuat menampilkan
             * "Human Resources — Terpasang" tepat di bawah kalimat yang menyatakan ia belum memuat
             * apa pun.
             */
            if ($lingkungan->kind !== 'production') {
                return [];
            }

            $koneksi = (string) config('database.default');
        } else {
            $koneksi = $this->daftarkan($database);
        }

        try {
            $baris = DB::connection($koneksi)
                ->table('core_module_installations')
                ->where('tenant_id', $lingkungan->tenant_id)
                ->orderBy('module_id')
                ->get(['module_id', 'version', 'status', 'seeded_at']);
        } catch (Throwable) {
            return [];
        }

        if ($baris->isEmpty()) {
            return [];
        }

        $nama = $this->nama($baris->pluck('module_id')->all());
        $hasil = [];

        foreach ($baris as $satu) {
            $id = (string) $satu->module_id;

            $hasil[] = [
                'id' => $id,
                // Jatuh ke id-nya sendiri, bukan ke tanda hubung. Sebuah module yang terpasang
                // tetapi tidak ada di katalog adalah keadaan yang ingin terlihat, bukan
                // disembunyikan di balik baris kosong.
                'nama' => $nama[$id] ?? $id,
                'versi' => (string) $satu->version,
                'status' => (string) $satu->status,
                'disemai' => $satu->seeded_at !== null,
            ];
        }

        return $hasil;
    }

    /**
     * Nama terbaca tiap module, dari katalog `apps` di database pusat.
     *
     * Query kedua, dan memang harus terpisah: katalognya di database pusat sementara catatan
     * pemasangannya di database lingkungan. Tidak ada `JOIN` yang menyeberangi keduanya.
     *
     * @param  array<array-key, mixed>  $id
     * @return array<string, string>
     */
    private function nama(array $id): array
    {
        $bersih = array_values(array_filter(array_map(strval(...), $id), fn (string $s): bool => $s !== ''));

        if ($bersih === []) {
            return [];
        }

        try {
            /** @var array<string, string> $peta */
            $peta = DB::table('apps')->whereIn('id', $bersih)->pluck('name', 'id')->all();

            return $peta;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Koneksi ke database lingkungan, disalin dari koneksi bawaan.
     *
     * Host, kredensial, dan `search_path` diambil dari koneksi bawaan supaya tidak pernah
     * menyimpang darinya. `url` dikosongkan karena Laravel mendahulukannya di atas `database` bila
     * ia terisi — dan bila itu terjadi, layar ini akan membaca database pusat sambil mengaku
     * membaca database lingkungan.
     */
    private function daftarkan(string $database): string
    {
        $bawaan = (string) config('database.default');
        $nama = 'lingkungan_'.md5($database);

        if (config('database.connections.'.$nama.'.database') === $database) {
            return $nama;
        }

        $konfigurasi = config('database.connections.'.$bawaan);
        $konfigurasi = is_array($konfigurasi) ? $konfigurasi : [];
        $konfigurasi['database'] = $database;
        $konfigurasi['url'] = null;

        config(['database.connections.'.$nama => $konfigurasi]);
        DB::purge($nama);

        return $nama;
    }
}
