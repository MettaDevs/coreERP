<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FiscalCalendar extends Model
{
    use HasUlids;

    protected $fillable = ['tenant_id', 'code', 'name'];

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return HasMany<FiscalYear, $this> */
    public function years(): HasMany
    {
        return $this->hasMany(FiscalYear::class);
    }
}
