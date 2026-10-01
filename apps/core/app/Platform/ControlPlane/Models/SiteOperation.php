<?php

declare(strict_types=1);

namespace App\Platform\ControlPlane\Models;

use App\Platform\ControlPlane\OwnedByControlPlane;
use Illuminate\Database\Eloquent\Model;

/** Penanda sisi pusat untuk tabel `site_operations`. Alasannya di {@see Site}. */
class SiteOperation extends Model
{
    use OwnedByControlPlane;

    protected $table = 'site_operations';
}
