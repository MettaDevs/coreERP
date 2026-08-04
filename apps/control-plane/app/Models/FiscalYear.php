<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FiscalYear extends Model
{
    use HasUlids;

    protected $fillable = ['fiscal_calendar_id', 'name', 'starts_on', 'ends_on'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    /** @return BelongsTo<FiscalCalendar, $this> */
    public function calendar(): BelongsTo
    {
        return $this->belongsTo(FiscalCalendar::class, 'fiscal_calendar_id');
    }

    /** @return HasMany<FiscalPeriod, $this> */
    public function periods(): HasMany
    {
        return $this->hasMany(FiscalPeriod::class);
    }
}
