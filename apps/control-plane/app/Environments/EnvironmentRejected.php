<?php

declare(strict_types=1);

namespace ControlPlane\Environments;

use RuntimeException;

/**
 * Pembuatan lingkungan ditolak karena keadaan yang memang tidak boleh ada, bukan karena gangguan.
 *
 * Ia kelas tersendiri supaya lapisan HTTP dapat memulangkannya sebagai kesalahan formulir yang
 * terbaca operator, alih-alih halaman 500 yang menyembunyikan sebabnya.
 */
class EnvironmentRejected extends RuntimeException {}
