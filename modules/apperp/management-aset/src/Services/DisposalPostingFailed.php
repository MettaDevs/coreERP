<?php

namespace Modules\Apperp\ManagementAset\Services;

use RuntimeException;
use Throwable;

/**
 * Jurnal pelepasan aset tidak dapat diterbitkan karena kesalahan sistem, bukan karena isian pengguna.
 * Penyebabnya — `InvalidPosting` dari Core — sudah dilaporkan ke pemantauan kesalahan; pesan ini yang
 * sampai ke layar, dan transaksinya dibatalkan: aset tidak jadi dilepas.
 */
final class DisposalPostingFailed extends RuntimeException
{
    public function __construct(Throwable $previous)
    {
        parent::__construct('Pelepasan aset gagal disimpan karena kesalahan sistem pada jurnalnya. Aset belum dilepas; tim kami sudah diberi tahu.', 0, $previous);
    }
}
