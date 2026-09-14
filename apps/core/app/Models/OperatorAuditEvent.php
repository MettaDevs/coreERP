<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\ControlPlane\OwnedByControlPlane;
use Illuminate\Database\Eloquent\Model;

/** Penanda sisi pusat untuk tabel `operator_audit_events`. Alasannya di {@see Site}. */
class OperatorAuditEvent extends Model
{
    use OwnedByControlPlane;

    protected $table = 'operator_audit_events';
}
