<?php

namespace Modules\Apperp\ManagementAset\Services;

use RuntimeException;
use Throwable;

/**
 * Jurnal penyesuaian nilai aset tidak dapat diterbitkan karena kesalahan sistem, bukan karena isian pengguna.
 * Penyebabnya — `InvalidPosting` dari Core — sudah dilaporkan ke pemantauan kesalahan; pesan ini yang
 * sampai ke layar, dan transaksinya dibatalkan: nilai buku aset tidak berubah.
 */
final class ValueAdjustmentPostingFailed extends RuntimeException
{
    public function __construct(Throwable $previous)
    {
        parent::__construct('Penyesuaian nilai gagal diposting karena kesalahan sistem pada jurnalnya. Nilai buku aset tidak berubah; tim kami sudah diberi tahu.', 0, $previous);
    }
}
