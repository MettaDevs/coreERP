<?php

declare(strict_types=1);

namespace ControlPlane\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Satu tindakan operator — tabel `operator_audit_events` milik Core, hanya-tambah.
 *
 * Trigger di database menolak UPDATE dan DELETE. Model ini tidak punya kolom `updated_at` karena
 * baris yang tidak pernah berubah tidak punya waktu berubah.
 *
 * @property string $id
 * @property ?int $user_id
 * @property string $action
 * @property string $subject_type
 * @property ?string $subject_id
 * @property array<string, mixed> $detail
 * @property ?string $ip_address
 * @property Carbon $occurred_at
 */
class OperatorAuditEvent extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'operator_audit_events';

    protected $fillable = ['user_id', 'action', 'subject_type', 'subject_id', 'detail', 'ip_address', 'occurred_at'];

    protected function casts(): array
    {
        return [
            'detail' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
