<?php

declare(strict_types=1);

namespace App\Platform\Observability\Support;

use App\Platform\Observability\Http\Middleware\AttachTraceContext;
use Illuminate\Http\Request;
use OpenTelemetry\API\Globals;
use Sentry\SentrySdk;
use Sentry\State\Scope;
use Throwable;

use function Sentry\captureException;
use function Sentry\withScope;

/**
 * Menyusun laporan kesalahan lalu menaruhnya di berkas log di mesin, Sentry (bila DSN diisi), SigNoz
 * (bila OpenTelemetry dinyalakan), dan — kalau webhooknya diisi — satu channel Discord.
 *
 * Sejak 3 Oktober 2026 Sentry swakelola menggantikan SigNoz sebagai tempat laporan dicari; SigNoz
 * dimatikan dan jalur OpenTelemetry-nya dibiarkan diam (mati secara bawaan) supaya dapat dinyalakan lagi.
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
 * **Tidak ada jalur yang boleh melempar.** Aturan yang sama seperti {@see ActiveSpan} dan
 * {@see ErrorReport}, dan di sini paling keras: kelas ini dipanggil dari dalam penangan
 * kesalahan Laravel. Lemparan dari sini menimpa kesalahan asli dengan kesalahan tentang
 * pelaporan kesalahan — dan yang hilang justru satu-satunya keterangan tentang apa yang
 * sebenarnya terjadi.
 */
final class ErrorReporter
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
            if (! ErrorReport::isReportable($error)) {
                return;
            }

            $report = ErrorReport::from($error, $request);

            self::toFile($report);
            $sentryEventId = self::toSentry($report, $error);
            self::toSigNoz($report);
            DiscordNotifier::send($report, $sentryEventId);
        } catch (Throwable) {
            // Sengaja dibiarkan. Lihat catatan kelas.
        } finally {
            self::$reporting = false;
        }
    }

    /**
     * Penanda bahwa sebuah permintaan benar-benar melewati pipeline HTTP.
     *
     * Dipasang {@see AttachTraceContext}, yang terdaftar global dan
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

    private static function toFile(ErrorReport $report): void
    {
        try {
            ErrorReportFile::write($report->toText());
        } catch (Throwable) {
            // Collector yang mati tidak boleh ikut menghapus berkasnya, dan sebaliknya.
        }
    }

    /**
     * Kunci laporan yang menjadi tag Sentry: nilainya sedikit dan dipakai untuk menyaring — tenant,
     * organisasi, module, rute, dan sumber kesalahan. Sisanya masuk konteks `coreerp`, yang terbaca
     * pada halaman kejadian tetapi tidak dijadikan indeks.
     */
    private const SENTRY_TAGS = [
        'coreerp.tenant_slug', 'coreerp.tenant_id', 'coreerp.legal_entity_id', 'coreerp.org_unit_id',
        'coreerp.module_id', 'coreerp.app_id', 'coreerp.sumber_kesalahan', 'http.route',
        'db.response.status_code',
    ];

    /**
     * Data pribadi tidak dikirim ke Sentry, sejalan dengan `send_default_pii` yang mati: alamat klien
     * dan peramban pengguna tetap tercatat di berkas laporan, yang tinggal di mesin itu sendiri.
     */
    private const SENTRY_EXCLUDED = ['client.address', 'user_agent.original', 'exception.stacktrace'];

    /**
     * Mengirim kesalahan yang sama ke Sentry, lengkap dengan tenant dan organisasi tempat ia terjadi.
     *
     * Hanya lewat sini: penangan bawaan Sentry (`Integration::handles`) sengaja tidak dipasang, supaya
     * saringan {@see ErrorReport::isReportable()} berlaku sama untuk Sentry, berkas, dan Discord, dan
     * satu kesalahan tidak tiba dua kali. Tanpa DSN klien Sentry tidak terpasang dan cabang ini diam.
     *
     * Tag dipasang di dalam `withScope`, bukan pada scope bersama: di worker Octane scope yang sama
     * melayani permintaan berikutnya, dan tenant permintaan ini tidak boleh menempel di sana.
     *
     * Id kejadiannya dipulangkan untuk tautan di Discord. Bukan dibaca dari `lastEventId` hub: di worker
     * yang hidup lama nilai itu bisa milik kesalahan permintaan lain bila kiriman ini gagal.
     */
    private static function toSentry(ErrorReport $report, Throwable $error): ?string
    {
        try {
            if (SentrySdk::getCurrentHub()->getClient() === null) {
                return null;
            }

            $attributes = array_diff_key(
                array_filter($report->toAttributes(), static fn (mixed $value): bool => $value !== null && $value !== ''),
                array_flip(self::SENTRY_EXCLUDED),
            );

            return withScope(static function (Scope $scope) use ($report, $error, $attributes): ?string {
                foreach (self::SENTRY_TAGS as $key) {
                    if (isset($attributes[$key])) {
                        $scope->setTag($key, mb_substr((string) $attributes[$key], 0, 200));
                    }
                }
                if (isset($attributes['coreerp.user_id'])) {
                    $scope->setUser(['id' => (string) $attributes['coreerp.user_id']]);
                }
                // Baris kepala laporan memuat alamat klien; disamarkan di sini, bukan di laporannya,
                // karena berkas laporan di mesin itu sendiri memang perlu menyimpannya.
                $address = (string) ($report->toAttributes()['client.address'] ?? '');
                $text = $address === '' ? $report->toText() : str_replace($address, '[alamat klien]', $report->toText());
                $scope->setContext('coreerp', [...$attributes, 'laporan' => $text]);

                return captureException($error)?->__toString();
            });
        } catch (Throwable) {
            // Sentry yang tidak terjangkau tidak boleh menahan berkas dan Discord.
            return null;
        }
    }

    private static function toSigNoz(ErrorReport $report): void
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
