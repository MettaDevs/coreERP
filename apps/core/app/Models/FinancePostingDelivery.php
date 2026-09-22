<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Jejak pengiriman satu posting ke satu klien mode `push` (TODO 6.10).
 *
 * `retrying`: gagal sementara (408, 429, 5xx, atau tidak terjangkau), dicoba lagi pada
 * `next_attempt_at`. `delivered`: pembaca menjawab 2xx. `failed`: pembaca menolak dengan 4xx lain,
 * atau batas waktu percobaan habis; tampil di layar pantau dan tidak dikirim lagi otomatis.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $finance_posting_id
 * @property string $integration_client_id
 * @property string $status
 * @property int $attempts
 * @property ?Carbon $first_attempt_at
 * @property ?Carbon $last_attempt_at
 * @property ?Carbon $next_attempt_at
 * @property ?int $last_status_code
 * @property ?string $last_error
 * @property ?Carbon $delivered_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read FinancePosting $posting
 */
class FinancePostingDelivery extends Model
{
    use HasUlids;

    public const RETRYING = 'retrying';

    public const DELIVERED = 'delivered';

    public const FAILED = 'failed';

    protected $fillable = [
        'tenant_id', 'finance_posting_id', 'integration_client_id', 'status', 'attempts', 'first_attempt_at',
        'last_attempt_at', 'next_attempt_at', 'last_status_code', 'last_error', 'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'first_attempt_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'last_status_code' => 'integer',
            'delivered_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<FinancePosting, $this> */
    public function posting(): BelongsTo
    {
        return $this->belongsTo(FinancePosting::class, 'finance_posting_id');
    }
}
