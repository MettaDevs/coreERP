<?php

namespace Modules\Apperp\ManagementAset\Reporting;

use RuntimeException;

/**
 * Dataset tidak dapat disusun dengan alasan yang layak ditampilkan ke pengguna: record
 * tidak ada, berada di luar scope organisasinya, atau parameter tidak masuk akal. Core
 * meneruskan pesannya apa adanya ke baris ekspor.
 */
final class ReportDataException extends RuntimeException {}
