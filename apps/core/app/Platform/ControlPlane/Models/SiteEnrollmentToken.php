<?php

declare(strict_types=1);

namespace App\Platform\ControlPlane\Models;

use App\Platform\ControlPlane\OwnedByControlPlane;
use Illuminate\Database\Eloquent\Model;

/** Penanda sisi pusat untuk tabel `site_enrollment_tokens`. Alasannya di {@see Site}. */
class SiteEnrollmentToken extends Model
{
    use OwnedByControlPlane;

    protected $table = 'site_enrollment_tokens';
}
