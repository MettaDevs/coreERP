<?php

declare(strict_types=1);

namespace App\Support\Observabilitas;

use Throwable;

/**
 * Menahan kiriman berulang untuk kesalahan yang sama, supaya satu kegagalan tidak berubah
 * menjadi ratusan notifikasi.
 *
 * **Kenapa ini wajib ada, bukan penyempurnaan.** Kesalahan tidak datang satu-satu. Satu kali
 * membuka halaman daftar menghasilkan dua permintaan yang gagal dengan sebab yang sama persis,
 * dan database yang mati membuat **setiap** permintaan gagal selama ia mati — termasuk polling
 * dari tab yang dibiarkan terbuka. Dengan `@everyone`, tanpa penjeda, yang sampai ke tim bukan
 * peringatan melainkan alasan untuk mematikan notifikasi channel itu. Peringatan yang
 * dimatikan tidak lebih berguna daripada peringatan yang tidak pernah dikirim.
 *
 * **Kenapa berkas, bukan `Cache::`.** Penyimpanan cache di sini adalah tabel di database yang
 * sama dengan yang dipakai aplikasi. Keadaan yang paling butuh dijeda justru keadaan ketika
 * database tidak bisa ditanya, dan penjeda yang ikut mati pada saat itu adalah penjeda yang
 * absen tepat pada satu-satunya kejadian yang ia dirancang untuk tangani. Berkas tidak punya
 * ketergantungan itu.
 *
 * Tempatnya menumpang volume `storage/logs` karena itulah satu-satunya volume yang dibagi
 * ketiga container — web, worker, penjadwal. Kalau masing-masing memegang penjedanya sendiri,
 * satu kesalahan yang terjadi di ketiganya tetap berbunyi tiga kali.
 */
final class PenjedaKiriman
{
    /**
     * Apakah laporan ini boleh dikirim sekarang.
     *
     * Gagal-membuka: kalau penjedanya sendiri bermasalah — folder tidak bisa dibuat, disk
     * penuh — jawabannya `true`. Peringatan yang dobel masih jauh lebih baik daripada
     * peringatan yang hilang karena mekanisme peredamnya rusak.
     */
    public static function boleh(LaporanKesalahan $laporan): bool
    {
        try {
            $jeda = self::jedaDetik();

            if ($jeda < 1) {
                return true;
            }

            $berkas = self::folder().'/'.self::sidikJari($laporan);

            $terakhir = @filemtime($berkas);

            if ($terakhir !== false && (time() - $terakhir) < $jeda) {
                return false;
            }

            self::siapkanFolder(self::folder());
            @touch($berkas);
            self::sapuBerkasLama(self::folder());

            return true;
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Menandai kesalahan yang "sama".
     *
     * Jenis exception beserta baris tempat ia dilempar, **bukan** pesannya. Pesan sebuah
     * `QueryException` memuat nilai binding, sehingga dua kegagalan dari satu bug yang sama
     * akan terlihat berbeda hanya karena barisnya berbeda — dan penjeda yang memakai pesan
     * tidak pernah menjeda apa pun. Yang dipakai di sini sengaja lebih tumpul: kalau tempatnya
     * sama, anggap itu bug yang sama.
     */
    private static function sidikJari(LaporanKesalahan $laporan): string
    {
        $atribut = $laporan->keAtribut();

        return sha1(implode('|', [
            (string) ($atribut['exception.type'] ?? ''),
            (string) ($atribut['code.filepath'] ?? ''),
            (string) ($atribut['code.lineno'] ?? ''),
        ]));
    }

    private static function jedaDetik(): int
    {
        return (int) config('coreerp.discord.jeda_detik', 60);
    }

    private static function folder(): string
    {
        return storage_path('logs/.penjeda-kiriman');
    }

    private static function siapkanFolder(string $folder): void
    {
        if (! is_dir($folder)) {
            @mkdir($folder, 0o775, true);
        }
    }

    /**
     * Membuang penanda yang sudah tidak mungkin menjeda apa pun.
     *
     * Tanpa ini folder terisi satu berkas kosong per bug selamanya. Batasnya sehari, jauh di
     * atas jeda mana pun yang masuk akal, jadi penyapuan tidak pernah memulihkan sebutan yang
     * mestinya masih tertahan.
     */
    private static function sapuBerkasLama(string $folder): void
    {
        $batas = time() - 86400;

        foreach (glob($folder.'/*') ?: [] as $berkas) {
            if (is_file($berkas) && (@filemtime($berkas) ?: 0) < $batas) {
                @unlink($berkas);
            }
        }
    }
}
