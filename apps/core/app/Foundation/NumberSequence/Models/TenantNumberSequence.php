<?php

namespace App\Foundation\NumberSequence\Models;

use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
use App\Platform\Tenant\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property list<array<string, mixed>> $segments
 * @property int $version
 */
#[DataClassification(DataClass::CustomerContent)]
class TenantNumberSequence extends Model
{
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'reference_id', 'profile_code', 'scope_type', 'status', 'is_continuous',
        'allow_manual', 'reset_period', 'preallocation_enabled', 'preallocation_quantity',
        'minimum_number', 'maximum_number', 'segments',
    ];

    protected function casts(): array
    {
        return [
            'is_continuous' => 'boolean',
            'allow_manual' => 'boolean',
            'preallocation_enabled' => 'boolean',
            'segments' => 'array',
        ];
    }

    /** Reset periods that need a fiscal calendar, and therefore a legal entity, to resolve. */
    public function usesFiscalCalendar(): bool
    {
        return in_array($this->reset_period, ['fiscal_year', 'fiscal_period'], true);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<NumberSequenceReference, $this> */
    public function reference(): BelongsTo
    {
        return $this->belongsTo(NumberSequenceReference::class, 'reference_id');
    }
}
