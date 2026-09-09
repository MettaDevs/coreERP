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
 * Yang tersisa hanya satu jenis kegagalan: permintaannya sendiri tidak bisa dipenuhi,
 * misalnya reference yang belum terdaftar untuk tenant ini. Itu 422, dan hanya 422. Status
 * per kejadian dibuang bersama sebab-sebab yang tidak mungkin lagi terjadi, sehingga 503
 * tidak sekadar berhenti dipakai — ia menjadi tidak bisa ditulis.
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
