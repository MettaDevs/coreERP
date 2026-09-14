<?php

declare(strict_types=1);

namespace ControlPlane\Sites;

use RuntimeException;

/**
 * Permintaan terhadap sebuah situs ditolak dengan alasan yang boleh dibaca operator.
 *
 * `reason` adalah kode mesin untuk jawaban API; pesannya kalimat untuk layar.
 */
final class SiteRejected extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
