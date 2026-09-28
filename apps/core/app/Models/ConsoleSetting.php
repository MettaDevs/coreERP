<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\ControlPlane\OwnedByControlPlane;
use Illuminate\Database\Eloquent\Model;

/**
 * Penanda sisi pusat untuk tabel `console_settings`. Alasannya di {@see Site}.
 *
 * Setelan konsol — termasuk kunci privat lisensi yang terenkripsi — hanya dibaca konsol operator.
 * Pembaca di Core berarti kunci itu punya dua tempat yang harus ingat mendekripsinya dengan benar.
 */
class ConsoleSetting extends Model
{
    use OwnedByControlPlane;

    protected $table = 'console_settings';
}
