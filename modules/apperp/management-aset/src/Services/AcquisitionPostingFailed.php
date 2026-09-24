<?php

namespace Modules\Apperp\ManagementAset\Services;

use RuntimeException;
use Throwable;

/**
 * Posting perolehan tidak dapat diterbitkan karena kesalahan sistem, bukan karena isian pengguna
 * (TODO 9.7). Penyebabnya — `PostingTidakSah` dari Core — sudah dilaporkan ke pemantauan kesalahan;
 * pesan ini yang sampai ke layar, dan transaksi penyelesaian penerimaan dibatalkan karenanya.
 */
final class AcquisitionPostingFailed extends RuntimeException
{
    public function __construct(Throwable $previous)
    {
        parent::__construct('Penerimaan gagal diselesaikan karena kesalahan sistem pada jurnal perolehannya. Tidak ada aset yang terdaftar; tim kami sudah diberi tahu.', 0, $previous);
    }
}
