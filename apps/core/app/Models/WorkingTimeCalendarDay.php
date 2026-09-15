<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkingTimeCalendarDay extends Model
{
    use HasUlids;

    protected $fillable = [
        'tenant_id',
        'working_time_calendar_id',
        'date',
        'day_of_week',
        'control',
        'closed_for_pickup',
        'hours',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'day_of_week' => 'integer',
        'closed_for_pickup' => 'boolean',
        'hours' => 'float',
    ];

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<WorkingTimeCalendar, $this> */
    public function calendar(): BelongsTo
    {
        return $this->belongsTo(WorkingTimeCalendar::class, 'working_time_calendar_id');
    }

    /** @return HasMany<WorkingTimeCalendarLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(WorkingTimeCalendarLine::class, 'working_time_calendar_day_id');
    }
}
