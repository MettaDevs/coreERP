<?php

declare(strict_types=1);

namespace App\Support\ControlPlane;

use App\Models\Environment;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Menunjuk database milik sebuah lingkungan, dan menjalankan sesuatu **di dalamnya**.
 *
 * ## Kenapa ini ada
 *
 * Pemasangan module menjalankan tiga hal yang seluruhnya menulis data tenant: migration module,
 * urutan nomor, dan data awal. Ketiganya menulis lewat koneksi bawaan — `ModuleMigrator` memang
 * menerima nama koneksi, tetapi seeder module tidak, dan tidak akan pernah: seeder memakai model
 * Eloquent module, dan memaksa tiap model module menerima nama koneksi berarti setiap module harus
 * ikut tahu soal lingkungan. Yang benar kebalikannya — **lingkungannya yang dipasang, bukan
 * modulenya yang diberi tahu.**
 *
 * Karena itu yang dipindah di sini adalah `database.default` itu sendiri, untuk selama satu
 * blok saja.
 *
 * ## Kenapa `coreerp.control_connection` ikut dipasang
 *
 * Menggeser koneksi bawaan akan menyeret **semua** model, termasuk `users`, `tenants`, `clients`,
 * dan registry `environments` — tabel-tabel yang justru tidak boleh ikut pindah. Sebuah seeder
 * module yang membaca `Tenant` di tengah blok ini akan mencarinya di database sandbox, tidak
 * menemukannya, lalu gagal dengan pesan yang tidak menyebut sebabnya sama sekali.
 *
 * Trait `OwnedByControlPlane` sudah menandai tabel-tabel itu, dan ia membaca `coreerp.control_connection`
 * **setiap kali dipanggil**. Jadi menyetel kunci itu ke koneksi semula, selama blok berjalan,
 * menahan sisi pusat tetap di tempatnya. Ini pemakaian sungguhan pertama trait tersebut; sebelum
 * ini ia memang tidak melakukan apa-apa.
 *
 * ## Yang TIDAK dilakukan kelas ini
 *
 * Ia bukan `PenjagaKoneksi` yang merutekan permintaan HTTP. Yang itu menuntut jalur gagal-tertutup:
 * permintaan yang tidak dapat menentukan lingkungannya harus ditolak, bukan dilayani database
 * bawaan. Kelas ini dipanggil kode yang **sudah memegang** barisnya, jadi tidak ada yang perlu
 * ditebak. Lihat App\Http\Middleware\ResolveEnvironment.
 */
final class EnvironmentConnection
{
    /**
     * Koneksi kedua ke database pusat, dipakai hanya untuk pernyataan tingkat kluster.
     *
     * PostgreSQL menolak `CREATE DATABASE` dan `DROP DATABASE` di dalam blok transaksi, dan koneksi
     * bawaan sangat mungkin sedang berada di dalam satu — di suite test ia selalu begitu.
     */
    public const MAINTENANCE = 'environment_maintenance';

    /** Nama koneksi yang menunjuk database pusat, yaitu koneksi bawaan yang sedang berlaku. */
    public function controlPlane(): string
    {
        return (string) config('database.default');
    }

    /**
     * Nama koneksi untuk sebuah lingkungan.
     *
     * `database_name` kosong berarti lingkungan itu **memang** tinggal di database bawaan, bukan
     * berarti datanya belum diketahui. Itu keadaan lingkungan produksi hari ini, keadaan on-prem,
     * dan keadaan pemasangan pooled — di sana jawabannya koneksi bawaan, dan tidak ada yang perlu
     * digeser sama sekali.
     */
    public function for(Environment $environment): string
    {
        $database = $environment->database_name;

        if (! is_string($database) || $database === '') {
            return $this->controlPlane();
        }

        return $this->register('environment_'.$environment->id, $database);
    }

    /**
     * Mendaftarkan satu koneksi bernama yang menunjuk database lain di server yang sama.
     *
     * Konfigurasinya disalin dari koneksi bawaan supaya host, kredensial, dan search_path tidak
     * pernah menyimpang darinya. `url` dikosongkan karena Laravel mendahulukannya di atas
     * `database` bila ia terisi — dan bila itu terjadi, seluruh pekerjaan berikutnya berjalan ke
     * database pusat tanpa satu pun peringatan.
     *
     * Pendaftaran ulang dengan database yang sama dilewati. Bukan penghematan: `DB::purge`
     * menutup PDO yang sedang dipakai, dan pemanggil yang memutari sepuluh module akan memutus
     * koneksinya sendiri sepuluh kali.
     */
    public function register(string $name, string $database): string
    {
        if (config('database.connections.'.$name.'.database') === $database) {
            return $name;
        }

        $konfigurasi = $this->baseConfig();
        $konfigurasi['database'] = $database;
        $konfigurasi['url'] = null;

        config(['database.connections.'.$name => $konfigurasi]);
        DB::purge($name);

        return $name;
    }

    /**
     * Mendirikan schema yang ditunjuk `search_path` koneksi ini.
     *
     * Dipanggil sekali saat sebuah database baru disiapkan, bukan tiap kali koneksinya dipakai.
     * `public` dilewati: ia sudah ada di tiap database baru, dan `CREATE SCHEMA` atasnya menuntut
     * hak yang belum tentu dimiliki peran aplikasi.
     */
    public function createSchemas(string $koneksi): void
    {
        $konfigurasi = config('database.connections.'.$koneksi);

        if (! is_array($konfigurasi)) {
            throw new RuntimeException(sprintf('Koneksi "%s" tidak terbaca dari config.', $koneksi));
        }

        /** @var array<string, mixed> $konfigurasi */
        $list = $this->schemasFrom($konfigurasi);

        foreach ($list as $schema) {
            DB::connection($koneksi)->statement(sprintf('CREATE SCHEMA IF NOT EXISTS "%s"', $schema));
        }
    }

    /** Koneksi untuk pernyataan tingkat kluster; lihat {@see self::MAINTENANCE}. */
    public function maintenance(): string
    {
        config(['database.connections.'.self::MAINTENANCE => $this->baseConfig()]);
        DB::purge(self::MAINTENANCE);

        return self::MAINTENANCE;
    }

    /**
     * Menjalankan sesuatu dengan koneksi bawaan menunjuk database lingkungan ini.
     *
     * Lingkungan yang memang tinggal di database bawaan dijalankan apa adanya — tanpa menyentuh
     * config sama sekali. Itu jalur yang dilalui seluruh perilaku hari ini, dan ia harus sama
     * persis seperti sebelum kelas ini ada.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runWithin(Environment $environment, Closure $callback): mixed
    {
        $controlPlane = $this->controlPlane();
        $target = $this->for($environment);

        if ($target === $controlPlane) {
            return $callback();
        }

        $previousControl = config('coreerp.control_connection');

        config([
            'database.default' => $target,
            'coreerp.control_connection' => $controlPlane,
        ]);

        try {
            return $callback();
        } finally {
            // `finally`, dan tanpa satu pun cabang di dalamnya. Blok ini yang menentukan apakah
            // sebuah kegagalan di tengah pemasangan module berakhir sebagai kegagalan biasa atau
            // sebagai proses yang sisa hidupnya menulis ke database yang salah.
            config([
                'database.default' => $controlPlane,
                'coreerp.control_connection' => $previousControl,
            ]);
        }
    }

    /** @return array<string, mixed> */
    public function baseConfig(): array
    {
        $default = $this->controlPlane();
        $konfigurasi = config('database.connections.'.$default);

        if (! is_array($konfigurasi)) {
            throw new RuntimeException(sprintf('Koneksi bawaan "%s" tidak terbaca dari config.', $default));
        }

        /** @var array<string, mixed> $konfigurasi */
        return $konfigurasi;
    }

    /**
     * @param  array<string, mixed>  $konfigurasi
     * @return list<string>
     */
    private function schemasFrom(array $konfigurasi): array
    {
        $search = $konfigurasi['search_path'] ?? 'public';
        $list = is_array($search) ? $search : explode(',', (string) $search);

        $result = [];

        foreach ($list as $schema) {
            $clean = trim((string) $schema, " \t\"'");

            if ($clean === '' || $clean === 'public' || preg_match('/^[a-z][a-z0-9_]*$/', $clean) !== 1) {
                continue;
            }

            $result[] = $clean;
        }

        return $result;
    }
}
