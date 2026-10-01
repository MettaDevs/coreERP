<?php

declare(strict_types=1);

namespace App\Platform\Observability\Support;

use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\StatusCode;
use Throwable;

/**
 * Satu-satunya tempat kode Core menyentuh span OpenTelemetry yang sedang aktif.
 *
 * Dipusatkan di sini karena penjaganya yang penting, bukan pemakaiannya. Instrumentasi
 * adalah hal yang *boleh tidak ada*: pemasangan on-prem tidak wajib memasang ekstensi
 * `opentelemetry`, tidak wajib menyalakan SDK, dan tidak wajib punya collector yang
 * mendengarkan. Kalau salah satu penjaga itu tersebar di beberapa berkas, satu berkas
 * yang lupa memeriksa cukup untuk mematikan permintaan yang sebenarnya sehat — dan
 * kegagalannya baru muncul di pemasangan pelanggan, bukan di sini.
 *
 * Aturannya satu kalimat: **tidak ada jalur di kelas ini yang boleh melempar.** Jejak
 * yang hilang adalah kehilangan pengamatan; permintaan yang mati karena jejaknya hilang
 * adalah kehilangan pelanggan.
 *
 * Yang sengaja **tidak** dijadikan penjaga adalah `extension_loaded('opentelemetry')`.
 * Ekstensi itu syarat bagi auto-instrumentation (ia yang memasang kait ke fungsi
 * framework), bukan syarat bagi API span. SDK yang dinyalakan secara manual — misalnya
 * dari sebuah perintah artisan atau pekerja antrean — menghasilkan span yang merekam
 * tanpa ekstensi apa pun, dan memeriksa ekstensi akan membuang atribut dari span yang
 * sebetulnya hidup. Yang benar-benar menjawab "apakah ada yang mendengarkan" adalah
 * `isRecording()`, dan itu yang dipakai.
 */
final class ActiveSpan
{
    /**
     * Menempelkan atribut ke span yang sedang aktif, kalau memang ada yang merekam.
     *
     * Nilai `null` dan string kosong dibuang, bukan dikirim. Atribut bernilai kosong
     * tetap memakan tempat di setiap span yang diekspor, dan pada backend jejak ia
     * tampil sebagai kolom yang ada tetapi tidak berarti apa-apa — lebih menyesatkan
     * daripada kolom yang tidak ada sama sekali.
     *
     * @param  array<string, scalar|null>  $attributes
     */
    public static function setAttributes(array $attributes): void
    {
        try {
            if (! class_exists(Span::class)) {
                return;
            }

            $span = Span::getCurrent();

            if (! $span->isRecording()) {
                return;
            }

            foreach ($attributes as $key => $value) {
                if ($key === '' || $value === null || $value === '') {
                    continue;
                }

                $span->setAttribute($key, is_bool($value) || is_int($value) || is_float($value)
                    ? $value
                    : (string) $value);
            }
        } catch (Throwable) {
            // Sengaja dibiarkan. Lihat catatan kelas: instrumentasi tidak pernah menjadi
            // alasan sebuah permintaan gagal.
        }
    }

    /**
     * Id jejak milik span yang sedang aktif, kalau memang ada jejak yang sah.
     *
     * Dipakai laporan kesalahan sebagai penghubung: id yang sama muncul pada laporan dan pada
     * jejaknya, sehingga satu klik di SigNoz membawa dari "apa yang gagal" ke "apa saja yang
     * terjadi sebelum ia gagal".
     *
     * Pemeriksaan `isValid()` bukan formalitas. Ketika SDK mati, `Span::getCurrent()` tetap
     * mengembalikan sebuah span — sebuah `NonRecordingSpan` yang konteksnya berisi nol semua.
     * Menuliskan `00000000000000000000000000000000` ke setiap laporan lebih buruk daripada
     * tidak menulis apa-apa: ia tampak seperti id sungguhan, dan orang yang mencarinya akan
     * menemukan setiap laporan sekaligus, yang artinya tidak menemukan apa pun.
     */
    public static function traceId(): ?string
    {
        return self::context(fn (SpanContextInterface $context): string => $context->getTraceId());
    }

    /**
     * Id span yang sedang aktif. Aturan kesahihannya sama seperti {@see self::idJejak()}.
     */
    public static function spanId(): ?string
    {
        return self::context(fn (SpanContextInterface $context): string => $context->getSpanId());
    }

    /**
     * @param  callable(SpanContextInterface): string  $fetch
     */
    private static function context(callable $fetch): ?string
    {
        try {
            if (! class_exists(Span::class)) {
                return null;
            }

            $context = Span::getCurrent()->getContext();

            return $context->isValid() ? $fetch($context) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Merekam sebuah kesalahan pada span yang sedang aktif dan menandai span itu gagal.
     *
     * Dua-duanya diperlukan, dan sering salah satunya lupa: `recordException` menaruh
     * jejak tumpukan sebagai event, tetapi span-nya tetap berstatus "Unset" dan tidak
     * ikut terhitung pada grafik tingkat kesalahan. `setStatus` yang membuatnya terhitung.
     */
    public static function recordException(Throwable $error): void
    {
        try {
            if (! class_exists(Span::class)) {
                return;
            }

            $span = Span::getCurrent();

            if (! $span->isRecording()) {
                return;
            }

            $span->recordException($error);
            $span->setStatus(StatusCode::STATUS_ERROR, $error->getMessage());
        } catch (Throwable) {
            // Sengaja dibiarkan. Lihat catatan kelas.
        }
    }
}
