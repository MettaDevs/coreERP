<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkingTimeCalendarLine extends Model
{
    use HasUlids;

    protected $fillable = [
        'tenant_id',
        'working_time_calendar_day_id',
        'from_time',
        'to_time',
        'efficiency',
        'property',
        'hours',
    ];

    protected $casts = [
        'efficiency' => 'float',
        'hours' => 'float',
    ];

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<WorkingTimeCalendarDay, $this> */
    public function day(): BelongsTo
    {
        return $this->belongsTo(WorkingTimeCalendarDay::class, 'working_time_calendar_day_id');
    }
}
