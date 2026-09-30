<?php

declare(strict_types=1);

namespace App\Platform\ControlPlane\Models;

use App\Platform\ControlPlane\OwnedByControlPlane;
use Illuminate\Database\Eloquent\Model;

/** Penanda sisi pusat untuk tabel `site_reports`. Alasannya di {@see Site}. */
class SiteReport extends Model
{
    use OwnedByControlPlane;

    protected $table = 'site_reports';
}
