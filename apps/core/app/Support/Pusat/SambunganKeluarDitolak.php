<?php

declare(strict_types=1);

namespace App\Support\Pusat;

use RuntimeException;

/**
 * Sebuah panggilan keluar ditolak karena lingkungannya bukan produksi.
 *
 * Ia sengaja kelas tersendiri, bukan `RuntimeException` telanjang: pemanggil yang ingin
 * membedakan "ditolak karena tempatnya" dari "gagal karena jaringannya" harus bisa melakukannya
 * tanpa membaca isi pesan.
 */
class SambunganKeluarDitolak extends RuntimeException {}
