<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalPeriod extends Model
{
    use HasUlids;

    protected $fillable = ['fiscal_year_id', 'ordinal', 'name', 'starts_on', 'ends_on'];

    protected function casts(): array
    {
        return [
            'ordinal' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    /** @return BelongsTo<FiscalYear, $this> */
    public function year(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class, 'fiscal_year_id');
    }
}
