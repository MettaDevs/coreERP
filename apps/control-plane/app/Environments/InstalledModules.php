<?php

declare(strict_types=1);

namespace ControlPlane\Environments;

use ControlPlane\Models\Environment;
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
final class InstalledModules
{
    /**
     * @return list<array{id: string, name: string, version: string, status: string, seeded: bool}>
     */
    public function __invoke(Environment $environment): array
    {
        $database = $environment->database_name;

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
            if ($environment->kind !== 'production') {
                return [];
            }

            $connection = (string) config('database.default');
        } else {
            $connection = $this->register($database);
        }

        try {
            $rows = DB::connection($connection)
                ->table('core_module_installations')
                ->where('tenant_id', $environment->tenant_id)
                ->orderBy('module_id')
                ->get(['module_id', 'version', 'status', 'seeded_at']);
        } catch (Throwable) {
            return [];
        }

        if ($rows->isEmpty()) {
            return [];
        }

        $names = $this->names($rows->pluck('module_id')->all());
        $result = [];

        foreach ($rows as $item) {
            $id = (string) $item->module_id;

            $result[] = [
                'id' => $id,
                // Jatuh ke id-nya sendiri, bukan ke tanda hubung. Sebuah module yang terpasang
                // tetapi tidak ada di katalog adalah keadaan yang ingin terlihat, bukan
                // disembunyikan di balik baris kosong.
                'name' => $names[$id] ?? $id,
                'version' => (string) $item->version,
                'status' => (string) $item->status,
                'seeded' => $item->seeded_at !== null,
            ];
        }

        return $result;
    }

    /**
     * Nama terbaca tiap module, dari katalog `apps` di database pusat.
     *
     * Query kedua, dan memang harus terpisah: katalognya di database pusat sementara catatan
     * pemasangannya di database lingkungan. Tidak ada `JOIN` yang menyeberangi keduanya.
     *
     * @param  array<array-key, mixed>  $ids
     * @return array<string, string>
     */
    private function names(array $ids): array
    {
        $clean = array_values(array_filter(array_map(strval(...), $ids), fn (string $s): bool => $s !== ''));

        if ($clean === []) {
            return [];
        }

        try {
            /** @var array<string, string> $map */
            $map = DB::table('apps')->whereIn('id', $clean)->pluck('name', 'id')->all();

            return $map;
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
    private function register(string $database): string
    {
        $default = (string) config('database.default');
        $name = 'environment_'.md5($database);

        if (config('database.connections.'.$name.'.database') === $database) {
            return $name;
        }

        $config = config('database.connections.'.$default);
        $config = is_array($config) ? $config : [];
        $config['database'] = $database;
        $config['url'] = null;

        config(['database.connections.'.$name => $config]);
        DB::purge($name);

        return $name;
    }
}
