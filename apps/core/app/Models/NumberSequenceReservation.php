<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $sequence_id
 * @property string $app_id
 * @property string $scope_key
 * @property string $period_key
 * @property int $numeric_value
 * @property string $formatted_value
 * @property string $idempotency_key
 * @property string $status
 * @property Carbon|null $expires_at
 */
class NumberSequenceReservation extends Model
{
    use HasUlids;

    protected $fillable = ['id', 'sequence_id', 'app_id', 'scope_key', 'period_key', 'numeric_value', 'formatted_value', 'idempotency_key', 'status', 'expires_at', 'confirmed_at', 'cancelled_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'confirmed_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    /** @return BelongsTo<TenantNumberSequence, $this> */
    public function sequence(): BelongsTo
    {
        return $this->belongsTo(TenantNumberSequence::class, 'sequence_id');
    }
}
