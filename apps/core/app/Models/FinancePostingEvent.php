<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Riwayat satu posting finance: terbit, ditahan, dinilai ulang, diakui, dikirim (area 6 dan 7).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $finance_posting_id
 * @property string $event
 * @property ?string $from_status
 * @property ?string $to_status
 * @property ?string $integration_client_id
 * @property ?int $user_id
 * @property ?array<string, mixed> $data
 * @property ?Carbon $created_at
 */
class FinancePostingEvent extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'finance_posting_id', 'event', 'from_status', 'to_status', 'integration_client_id', 'user_id', 'data',
    ];

    protected function casts(): array
    {
        return ['data' => 'array', 'user_id' => 'integer'];
    }

    /** @param  array<string, mixed>  $data */
    public static function catat(
        FinancePosting $posting,
        string $event,
        ?string $fromStatus,
        ?string $toStatus,
        ?string $clientId = null,
        ?int $userId = null,
        array $data = [],
    ): self {
        return self::query()->create([
            'tenant_id' => $posting->tenant_id,
            'finance_posting_id' => $posting->id,
            'event' => $event,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'integration_client_id' => $clientId,
            'user_id' => $userId,
            'data' => $data === [] ? null : $data,
        ]);
    }
}
