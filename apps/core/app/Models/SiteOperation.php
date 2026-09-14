<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\ControlPlane\OwnedByControlPlane;
use Illuminate\Database\Eloquent\Model;

/** Penanda sisi pusat untuk tabel `site_operations`. Alasannya di {@see Site}. */
class SiteOperation extends Model
{
    use OwnedByControlPlane;

    protected $table = 'site_operations';
}
