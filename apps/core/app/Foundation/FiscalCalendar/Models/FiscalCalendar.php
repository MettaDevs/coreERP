<?php

namespace App\Foundation\FiscalCalendar\Models;

use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
use App\Platform\Tenant\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $version
 */
#[DataClassification(DataClass::CustomerContent)]
class FiscalCalendar extends Model
{
    use HasUlids;

    /** @var array<string, DataClass> */
    public const COLUMN_CLASSIFICATION = [
        'name' => DataClass::CustomerContent,
    ];

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
