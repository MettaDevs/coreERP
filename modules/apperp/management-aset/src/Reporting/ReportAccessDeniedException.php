<?php

namespace Modules\Apperp\ManagementAset\Reporting;

use RuntimeException;

/**
 * Pengguna tidak memegang permission bisnis yang menjaga data sebuah laporan.
 *
 * Kelas ini ada supaya pemanggil di dalam module dapat membedakan "tidak boleh" dari
 * kegagalan laporan lain: pratinjau di layar menjawabnya 403, bukan 422. Bagi Core ia
 * tetap `RuntimeException` biasa, jadi kontrak `PenyediaLaporanModul` tidak berubah.
 */
final class ReportAccessDeniedException extends RuntimeException {}
