<?php

namespace Modules\Apperp\ManagementAset\Services;

use RuntimeException;
use Throwable;

/**
 * Jurnal koreksi nilai perolehan tidak dapat diterbitkan karena kesalahan sistem, bukan karena isian
 * pengguna (TODO 12). Penyebabnya — `PostingTidakSah` dari Core — sudah dilaporkan ke pemantauan
 * kesalahan; pesan ini yang sampai ke layar, dan transaksinya dibatalkan: nilai aset tidak berubah.
 */
final class AcquisitionAdjustmentFailed extends RuntimeException
{
    public function __construct(Throwable $previous)
    {
        parent::__construct('Koreksi nilai gagal disimpan karena kesalahan sistem pada jurnalnya. Tidak ada yang berubah; tim kami sudah diberi tahu.', 0, $previous);
    }
}
