<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use stdClass;

/**
 * Satu posting finance: satu dokumen sumber dengan jurnal seimbang (area 6).
 *
 * `payload` adalah bentuk kontrak persis seperti yang disajikan ke pembaca. `input` adalah
 * permintaan asli module, dipakai untuk membentuk ulang posting yang tertahan setelah pemetaannya
 * diperbaiki.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $legal_entity_id
 * @property string $posting_id
 * @property string $posting_type
 * @property int $contract_version
 * @property string $source_module
 * @property string $source_type
 * @property ?string $source_number
 * @property ?string $source_id
 * @property string $currency_code
 * @property int $currency_decimals
 * @property Carbon $posting_date
 * @property Carbon $document_date
 * @property Carbon $occurred_at
 * @property Carbon $published_at
 * @property ?string $settlement_mode
 * @property string $status
 * @property ?string $manual_reason
 * @property ?list<array<string, mixed>> $hold_reasons
 * @property ?string $vendor_id
 * @property ?string $reverses_posting_id
 * @property ?string $adjusts_posting_id
 * @property string $total_debit
 * @property string $total_credit
 * @property array<string, mixed> $payload
 * @property array<string, mixed> $input
 * @property string $input_hash
 * @property ?string $external_reference
 * @property ?string $reason_code
 * @property ?string $reason
 * @property ?Carbon $acknowledged_at
 * @property ?string $acknowledged_by_client_id
 * @property int $served_count
 * @property ?Carbon $last_served_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class FinancePosting extends Model
{
    use HasUlids;

    public const HELD = 'held';

    public const PENDING = 'pending';

    public const POSTED = 'posted';

    public const REJECTED = 'rejected';

    public const MANUAL = 'manual';

    public const MANUAL_BEFORE_CUTOVER = 'before_cutover';

    public const MANUAL_FEED_DISABLED = 'feed_disabled';

    public const MANUAL_USER = 'user';

    /** Kode alasan penolakan dari pembaca (PRD, bagian Endpoint). */
    public const REJECTION_CODES = [
        'PERIOD_CLOSED', 'UNKNOWN_ACCOUNT', 'UNKNOWN_DIMENSION', 'UNKNOWN_VENDOR', 'UNKNOWN_LEGAL_ENTITY', 'INVALID',
    ];

    protected $fillable = [
        'tenant_id', 'legal_entity_id', 'posting_id', 'posting_type', 'contract_version',
        'source_module', 'source_type', 'source_number', 'source_id', 'currency_code', 'currency_decimals',
        'posting_date', 'document_date', 'occurred_at', 'published_at', 'settlement_mode', 'status',
        'manual_reason', 'hold_reasons', 'vendor_id', 'reverses_posting_id', 'adjusts_posting_id',
        'total_debit', 'total_credit', 'payload', 'input', 'input_hash', 'external_reference',
        'reason_code', 'reason', 'acknowledged_at', 'acknowledged_by_client_id', 'served_count', 'last_served_at',
    ];

    protected function casts(): array
    {
        return [
            'contract_version' => 'integer',
            'currency_decimals' => 'integer',
            'posting_date' => 'date',
            'document_date' => 'date',
            'occurred_at' => 'datetime',
            'published_at' => 'datetime',
            'hold_reasons' => 'array',
            'payload' => 'array',
            'input' => 'array',
            'acknowledged_at' => 'datetime',
            'served_count' => 'integer',
            'last_served_at' => 'datetime',
        ];
    }

    /**
     * Membatasi query ke jenis posting yang boleh sampai ke klien itu (K-23). Tanpa awalan berarti
     * semua jenis. Dipakai tarikan, ack, dan dorongan, supaya ketiganya tidak pernah berbeda.
     *
     * @param  Builder<self>  $query
     */
    public static function batasiUntukKlien(Builder $query, IntegrationClient $client): void
    {
        $awalan = $client->posting_type_prefixes ?? [];
        if ($awalan === []) {
            return;
        }

        $query->where(function (Builder $inner) use ($awalan): void {
            foreach ($awalan as $satu) {
                $inner->orWhere('posting_type', 'like', addcslashes($satu, '\\%_').'%');
            }
        });
    }

    /**
     * Payload seperti yang dikirim ke pembaca, lewat tarikan maupun dorongan.
     *
     * `details` adalah objek di kontrak. Array PHP yang kosong akan dikodekan sebagai `[]`, jadi
     * diubah ke objek kosong di sini, satu kali, untuk kedua jalur.
     *
     * @return array<string, mixed>
     */
    public function servedPayload(): array
    {
        $payload = $this->payload;
        if (($payload['details'] ?? []) === []) {
            $payload['details'] = new stdClass;
        }

        return $payload;
    }

    /** @return HasMany<FinancePostingLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(FinancePostingLine::class)->orderBy('line_no');
    }

    /** @return HasMany<FinancePostingDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(FinancePostingDelivery::class);
    }

    /** @return HasMany<FinancePostingEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(FinancePostingEvent::class)->orderBy('created_at');
    }
}
