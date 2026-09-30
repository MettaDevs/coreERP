<?php

namespace App\Models;

use App\Support\Modules\Contracts\DataClass;
use App\Support\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $version
 */
#[DataClassification(DataClass::CustomerContent)]
class WorkingTimeCalendar extends Model
{
    use HasUlids, SoftDeletes;

    /** @var array<string, DataClass> */
    public const COLUMN_CLASSIFICATION = [
        'name' => DataClass::CustomerContent,
    ];

    protected $fillable = [
        'tenant_id',
        'legal_entity_id',
        'code',
        'name',
        'description',
        'base_calendar_id',
        'standard_work_hours',
        'is_active',
    ];

    protected $casts = [
        'standard_work_hours' => 'float',
        'is_active' => 'boolean',
    ];

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'legal_entity_id');
    }

    /** @return BelongsTo<WorkingTimeCalendar, $this> */
    public function baseCalendar(): BelongsTo
    {
        return $this->belongsTo(self::class, 'base_calendar_id');
    }

    /** @return HasMany<WorkingTimeCalendarDay, $this> */
    public function days(): HasMany
    {
        return $this->hasMany(WorkingTimeCalendarDay::class, 'working_time_calendar_id');
    }
}
