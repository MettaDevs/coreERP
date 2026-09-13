<?php

declare(strict_types=1);

namespace ControlPlane\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Riwayat apa yang pernah dilakukan terhadap sebuah lingkungan — tabel `environment_operations`.
 *
 * `step` dan `failure_message` ada untuk menjawab cacat yang berulang di repo ini: kegagalan yang
 * tidak dapat dibaca siapa pun. Operasi yang gagal wajib menyebutkan langkah terakhir yang
 * tercapai dan sebabnya apa adanya — constraint database menolak baris gagal tanpa alasan.
 *
 * @property string $id
 * @property string $environment_id
 * @property string $operation
 * @property string $status
 * @property ?string $step
 * @property ?string $failure_message
 * @property Carbon $started_at
 * @property ?Carbon $finished_at
 */
class EnvironmentOperation extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'environment_operations';

    protected $fillable = [
        'environment_id',
        'operation',
        'status',
        'requested_by',
        'source_environment_id',
        'step',
        'failure_message',
        'detail',
        'started_at',
        'finished_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'detail' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
