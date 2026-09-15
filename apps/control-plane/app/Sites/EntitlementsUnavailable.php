<?php

declare(strict_types=1);

namespace ControlPlane\Sites;

use RuntimeException;

/**
 * Daftar app yang dibeli tenant tidak dapat dibaca dari Core.
 *
 * Sambungan putus, jawaban selain 200, dan jawaban 200 yang bentuknya tidak sesuai kontrak
 * dikumpulkan di satu kelas: ketiganya berakhir sama — **tidak ada lisensi yang diterbitkan**. Lisensi
 * dengan daftar app tebakan lebih buruk daripada tidak ada lisensi baru, karena ia mengunci app yang
 * dibayar klien sampai lisensi berikutnya.
 *
 * Pesannya menyebut alamat yang dicoba, dengan alasan yang sama dengan `CoreUnreachable`: sebab yang
 * paling sering adalah `COREERP_URL` yang salah setel.
 */
final class EntitlementsUnavailable extends RuntimeException {}
