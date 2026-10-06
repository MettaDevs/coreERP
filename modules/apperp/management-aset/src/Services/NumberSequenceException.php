<?php

namespace Modules\Apperp\ManagementAset\Services;

use RuntimeException;
use Throwable;

/**
 * Kegagalan penerbitan nomor, beserta sebabnya.
 *
 * Sebelumnya seluruh sebab dilebur menjadi satu pesan "Nomor belum dapat diterbitkan":
 * kredensial yang ditolak Core, reference yang belum terdaftar, dan Core yang benar-benar
 * mati terlihat identik dari respons maupun dari log. Satu insiden karena token service
 * yang tidak sinkron sempat terbaca seolah layanan nomor sedang tumbang.
 *
 * `errorCode` yang membedakannya. Pesannya untuk pengguna; kodenya untuk yang memperbaiki.
 *
 * **Status HTTP-nya sekarang satu, dan itu bukan penyederhanaan melainkan konsekuensi.**
 * Kelas ini dulu menerima status per kejadian dengan 503 sebagai bawaannya, karena sebabnya
 * memang bermacam-macam dan tiap sebab punya jawabannya sendiri: 503 untuk Core yang tidak
 * terjangkau, 429 untuk permintaan yang melebihi batas laju, 403 untuk token service yang
 * ditolak. Ketiganya berasal dari jaringan, dan jaringan itu sudah tidak ada — nomor
 * diterbitkan lewat kontrak di dalam proses yang sama, pada koneksi database yang sama.
 *
 * Kelas ini hanya mewakili penolakan validasi, misalnya reference yang belum terdaftar atau
 * urutan nomor yang belum aktif. Itu 422. Kegagalan database dan bug internal tetap mungkin
 * walaupun tidak ada jaringan; keduanya tidak dibungkus kelas ini dan diteruskan ke handler
 * Laravel sebagai 500 agar laporan exception aslinya sampai ke Sentry.
 */
class NumberSequenceException extends RuntimeException
{
    /**
     * Status HTTP untuk setiap kegagalan penerbitan nomor.
     *
     * Ditulis sebagai konstanta, bukan disalin ke tiap controller, supaya jawabannya tetap
     * satu keputusan di satu tempat ketika suatu saat ada yang perlu mengubahnya.
     */
    public const HTTP_STATUS = 422;

    public function __construct(
        public readonly string $errorCode,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
