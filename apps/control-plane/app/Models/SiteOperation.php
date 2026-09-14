<?php

declare(strict_types=1);

namespace ControlPlane\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Satu operasi yang diminta operator dan dikerjakan agen — tabel `site_operations` milik Core.
 *
 * @property string $id
 * @property string $site_id
 * @property string $operation
 * @property array<string, mixed> $parameters
 * @property string $status
 * @property ?int $requested_by
 * @property ?string $step
 * @property ?string $failure_message
 * @property Carbon $requested_at
 * @property Carbon $expires_at
 * @property ?Carbon $started_at
 * @property ?Carbon $lease_until
 * @property ?Carbon $finished_at
 */
class SiteOperation extends Model
{
    use HasUlids;

    /** Daftar tertutup. Sama persis dengan CHECK `site_operations_jenis_dikenal` dan kontrak agen. */
    public const OPERATIONS = ['upgrade', 'backup', 'install_license', 'rotate_key', 'send_diagnostics'];

    protected $table = 'site_operations';

    protected $fillable = [
        'site_id',
        'operation',
        'parameters',
        'status',
        'requested_by',
        'step',
        'failure_message',
        'requested_at',
        'expires_at',
        'started_at',
        'lease_until',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'requested_at' => 'datetime',
            'expires_at' => 'datetime',
            'started_at' => 'datetime',
            'lease_until' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
