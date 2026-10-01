<?php

namespace Modules\Apperp\ManagementAset\Services;

use RuntimeException;
use Throwable;

/**
 * Jurnal reklasifikasi aset tidak dapat diterbitkan karena kesalahan sistem, bukan karena isian pengguna.
 * Penyebabnya — `InvalidPosting` dari Core — sudah dilaporkan ke pemantauan kesalahan; pesan ini yang sampai ke
 * layar, dan transaksinya dibatalkan: tidak ada nilai yang berpindah dan tidak ada aset baru yang lahir.
 */
final class ReclassificationPostingFailed extends RuntimeException
{
    public function __construct(Throwable $previous)
    {
        parent::__construct('Reklasifikasi gagal diposting karena kesalahan sistem pada jurnalnya. Nilai aset belum berpindah; tim kami sudah diberi tahu.', 0, $previous);
    }
}
