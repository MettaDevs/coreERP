<?php

declare(strict_types=1);

namespace ControlPlane\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Laporan situs yang isinya berubah — tabel `site_reports` milik Core.
 *
 * @property string $id
 * @property string $site_id
 * @property string $via
 * @property array<string, mixed> $payload
 * @property string $payload_hash
 * @property Carbon $reported_at
 * @property Carbon $received_at
 */
class SiteReport extends Model
{
    use HasUlids;

    protected $table = 'site_reports';

    protected $fillable = ['site_id', 'via', 'payload', 'payload_hash', 'reported_at', 'received_at'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'reported_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }
}
