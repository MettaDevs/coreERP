<?php

namespace App\Services;

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
 */
class NumberSequenceException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 503,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
