<?php

declare(strict_types=1);

namespace App\Support\Finance;

use RuntimeException;

/**
 * Status posting sudah tidak mengizinkan tindakan yang diminta, karena berubah di antara layar
 * dibuka dan tombol ditekan — misalnya ack pembaca tiba lebih dulu.
 *
 * Kelas sendiri, bukan `RuntimeException` biasa: `QueryException` juga turunan `RuntimeException`,
 * jadi menangkap induknya akan ikut menelan kesalahan database dan menampilkannya sebagai
 * "status berubah".
 */
final class StatusPostingBerubah extends RuntimeException {}
