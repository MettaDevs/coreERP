<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkingTimeLine extends Model
{
    use HasUlids;

    protected $fillable = [
        'tenant_id',
        'working_time_template_id',
        'day_of_week',
        'from_time',
        'to_time',
        'efficiency',
        'property',
        'closed_for_pickup',
        'hours',
    ];

    protected $casts = [
        'day_of_week' => 'integer',
        'efficiency' => 'float',
        'closed_for_pickup' => 'boolean',
        'hours' => 'float',
    ];

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<WorkingTimeTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(WorkingTimeTemplate::class, 'working_time_template_id');
    }
}
