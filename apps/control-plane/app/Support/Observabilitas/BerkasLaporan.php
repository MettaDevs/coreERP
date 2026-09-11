<?php

declare(strict_types=1);

namespace App\Support\Observabilitas;

use Throwable;

/**
 * Menulis laporan kesalahan ke berkas, sengaja **tanpa** melewati sistem log Laravel.
 *
 * **Kenapa tidak `Log::channel(...)`.** Paket `opentelemetry-auto-laravel` memasang
 * `LogWatcher` yang meneruskan setiap panggilan `Log::` menjadi catatan OTLP. Menulis laporan
 * lewat sana membuatnya tiba di SigNoz dua kali: sekali sebagai kiriman yang disengaja
 * beserta atribut yang sudah tersusun, dan sekali lagi sebagai blok teks mentah tanpa atribut
 * apa pun. Terukur pada 11 September 2026 — satu kesalahan menghasilkan tiga catatan.
 *
 * Menulis langsung juga memberi sifat yang pantas dimiliki alat diagnostik: ia tetap bekerja
 * ketika konfigurasi log sendiri yang rusak. Laporan yang hilang justru karena sistem log
 * bermasalah adalah laporan yang absen persis pada kejadian yang paling perlu dicatat.
 *
 * Rotasinya per hari dan per peran, meniru driver `daily`. Per peran karena ketiga container
 * — web, worker, penjadwal — menjalankan image yang sama di atas volume yang sama; kalau
 * ketiganya menulis ke satu berkas, tulisan mereka berselang-seling dan satu blok laporan
 * yang terdiri dari belasan baris tercabik di tengah.
 */
final class BerkasLaporan
{
    public static function tulis(string $isi): void
    {
        try {
            $berkas = self::jalur();

            self::siapkanFolder(dirname($berkas));

            // `FILE_APPEND | LOCK_EX` bukan hiasan: tiga proses menulis ke satu volume, dan
            // tanpa kunci sebuah blok panjang bisa disisipi blok lain di tengah kalimat.
            @file_put_contents($berkas, $isi."\n\n", FILE_APPEND | LOCK_EX);

            self::sapuBerkasLama(dirname($berkas));
        } catch (Throwable) {
            // Lihat catatan pada PelaporKesalahan: pelaporan tidak pernah menjadi sebab gagal.
        }
    }

    public static function jalur(?string $tanggal = null): string
    {
        $peran = (string) (getenv('CONTAINER_ROLE') ?: 'web');

        return storage_path(sprintf(
            'logs/kesalahan-internal-%s-%s.log',
            preg_replace('/[^a-z0-9_-]/i', '', $peran) ?: 'web',
            $tanggal ?? date('Y-m-d'),
        ));
    }

    private static function siapkanFolder(string $folder): void
    {
        if (! is_dir($folder)) {
            @mkdir($folder, 0o775, true);
        }
    }

    /**
     * Membuang berkas yang lebih tua dari batas simpan.
     *
     * Dijalankan setiap penulisan, bukan lewat penjadwal: laporan ditulis jarang, jadi
     * biayanya tidak berarti — dan menggantungkannya pada penjadwal berarti berkas menumpuk
     * tanpa batas pada pemasangan yang penjadwalnya mati, yaitu pemasangan yang justru paling
     * mungkin bermasalah.
     */
    private static function sapuBerkasLama(string $folder): void
    {
        $hari = (int) (getenv('COREERP_LAPORAN_KESALAHAN_HARI') ?: 30);

        if ($hari < 1) {
            return;
        }

        $batas = time() - ($hari * 86400);

        foreach (glob($folder.'/kesalahan-internal-*.log') ?: [] as $berkas) {
            if (@filemtime($berkas) < $batas) {
                @unlink($berkas);
            }
        }
    }
}
