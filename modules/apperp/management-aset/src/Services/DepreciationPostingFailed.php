<?php

namespace Modules\Apperp\ManagementAset\Services;

use RuntimeException;
use Throwable;

/**
 * Jurnal penyusutan atau pembalikannya tidak dapat diterbitkan karena kesalahan sistem, bukan karena
 * isian pengguna (TODO 11.2, 11.3). Penyebabnya — `PostingTidakSah` dari Core — sudah dilaporkan ke
 * pemantauan kesalahan; pesan ini yang sampai ke layar, dan transaksinya dibatalkan: tidak ada periode
 * yang ditandai sudah di-post, dan pembalikan tidak tercatat.
 */
final class DepreciationPostingFailed extends RuntimeException
{
    public function __construct(Throwable $previous)
    {
        parent::__construct('Penyusutan gagal di-post karena kesalahan sistem pada jurnalnya. Tidak ada yang berubah; tim kami sudah diberi tahu.', 0, $previous);
    }
}
