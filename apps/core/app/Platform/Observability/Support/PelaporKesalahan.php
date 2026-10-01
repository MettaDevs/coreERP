<?php

declare(strict_types=1);

namespace App\Platform\Observability\Support;

use App\Platform\Observability\Http\Middleware\LampirkanKonteksJejak;
use Illuminate\Http\Request;
use OpenTelemetry\API\Globals;
use Throwable;

/**
 * Menyusun laporan kesalahan lalu menaruhnya di tiga tempat: berkas log di mesin, SigNoz, dan
 * — kalau webhooknya diisi — satu channel Discord.
 *
 * **Kenapa lebih dari satu.** Berkas selalu bisa ditulis: ia tidak butuh jaringan, tidak butuh
 * collector yang hidup, dan tidak butuh izin keluar dari mesin pelanggan. SigNoz yang membuat
 * laporan itu bisa dicari, disaring per tenant, dan disambungkan ke jejak permintaannya.
 * Discord melakukan yang tidak dilakukan keduanya: **mendatangi orang.** Berkas dan SigNoz
 * hanya menjawab pertanyaan yang sudah diajukan; keduanya diam sempurna selama belum ada yang
 * curiga dan membuka.
 *
 * Satu menjamin laporan selalu ada, satu membuatnya berguna, satu memberitahu. Kegagalan satu
 * tujuan tidak boleh menghapus yang lain, jadi ketiganya punya penjaga sendiri-sendiri.
 *
 * **Tidak ada jalur yang boleh melempar.** Aturan yang sama seperti {@see JejakAktif} dan
 * {@see LaporanKesalahan}, dan di sini paling keras: kelas ini dipanggil dari dalam penangan
 * kesalahan Laravel. Lemparan dari sini menimpa kesalahan asli dengan kesalahan tentang
 * pelaporan kesalahan — dan yang hilang justru satu-satunya keterangan tentang apa yang
 * sebenarnya terjadi.
 */
final class PelaporKesalahan
{
    /**
     * Penjaga masuk-ulang.
     *
     * Tanpa ini ada lingkaran yang nyata: pelapor gagal menulis, lemparannya tertangkap
     * penangan kesalahan Laravel, penangan memanggil pelapor lagi, dan seterusnya sampai
     * memori habis. Kemungkinan terbesarnya justru pada keadaan yang paling butuh laporan —
     * database mati, disk penuh — jadi penjaga ini bukan kehati-hatian teoretis.
     */
    private static bool $reporting = false;

    public static function report(Throwable $error, ?Request $request = null): void
    {
        if (self::$reporting) {
            return;
        }

        self::$reporting = true;

        try {
            if (! LaporanKesalahan::isReportable($error)) {
                return;
            }

            $report = LaporanKesalahan::from($error, $request);

            self::toFile($report);
            self::toSigNoz($report);
            PengirimDiscord::send($report);
        } catch (Throwable) {
            // Sengaja dibiarkan. Lihat catatan kelas.
        } finally {
            self::$reporting = false;
        }
    }

    /**
     * Penanda bahwa sebuah permintaan benar-benar melewati pipeline HTTP.
     *
     * Dipasang {@see LampirkanKonteksJejak}, yang terdaftar global dan
     * karena itu dilewati setiap permintaan HTTP — dan hanya permintaan HTTP.
     */
    public const HTTP_MARKER = 'observabilitas.permintaan_http';

    /**
     * Membentuk permintaan yang pantas dilampirkan pada laporan.
     *
     * Di dalam pekerja antrean dan perintah artisan, `request()` tetap mengembalikan sebuah
     * objek — permintaan tiruan hasil `Request::capture()` dari argumen baris perintah, yang
     * `method()`-nya `GET` dan `fullUrl()`-nya diambil dari `APP_URL`. Melaporkannya
     * seolah-olah permintaan sungguhan menghasilkan baris yang terlihat berisi tetapi
     * menyesatkan: sebuah job antrean dilaporkan sebagai `GET http://localhost:8000`.
     *
     * **Yang ditanyakan adalah penanda, bukan `runningInConsole()`.** SAPI tidak menjawab
     * pertanyaan yang tepat: ia menjawab "apakah proses ini dijalankan dari baris perintah",
     * padahal yang perlu diketahui adalah "apakah kesalahan ini terjadi saat melayani sebuah
     * permintaan HTTP". Keduanya berbeda persis di tempat yang penting — di dalam test,
     * permintaan yang menembus seluruh middleware tetap berjalan pada SAPI `cli`, sehingga
     * `runningInConsole()` melaporkannya sebagai konsol dan setiap penjaga tentang identitas
     * permintaan berhenti bisa dibuktikan. Ditemukan tepat begitu penjaga itu ditulis.
     *
     * Penanda dipasang pada objek permintaannya sendiri, bukan pada keadaan statis, supaya
     * ia ikut mati bersama permintaan itu dan tidak pernah bocor ke pekerjaan berikutnya.
     */
    public static function currentRequest(): ?Request
    {
        try {
            $request = request();

            return $request->attributes->get(self::HTTP_MARKER) === true ? $request : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function toFile(LaporanKesalahan $report): void
    {
        try {
            BerkasLaporan::write($report->toText());
        } catch (Throwable) {
            // Collector yang mati tidak boleh ikut menghapus berkasnya, dan sebaliknya.
        }
    }

    private static function toSigNoz(LaporanKesalahan $report): void
    {
        try {
            if (! class_exists(Globals::class)) {
                return;
            }

            $attributes = array_filter(
                $report->toAttributes(),
                static fn (mixed $value): bool => $value !== null && $value !== '',
            );

            // Badan catatan sengaja satu baris; strukturnya ada di atribut, karena itu yang
            // bisa disaring. Blok utuhnya ikut sebagai satu atribut supaya panel detail di
            // SigNoz menampilkan persis yang tertulis di berkas — dua tujuan, satu isi, tidak
            // ada versi yang berbeda untuk dibandingkan saat insiden.
            $attributes['coreerp.laporan'] = $report->toText();

            Globals::loggerProvider()
                ->getLogger('coreerp.backend')
                ->logRecordBuilder()
                ->setTimestamp((int) (microtime(true) * 1_000_000_000))
                ->setSeverityNumber(17)
                ->setSeverityText('ERROR')
                ->setBody($report->summary())
                ->setAttributes($attributes)
                ->emit();
        } catch (Throwable) {
            // Ketika SDK mati, `loggerProvider()` mengembalikan penyedia tanpa-operasi dan
            // baris di atas tidak melakukan apa pun — itu jalur normal on-prem, bukan
            // kegagalan. Yang tertangkap di sini adalah hal-hal di luar itu.
        }
    }
}
