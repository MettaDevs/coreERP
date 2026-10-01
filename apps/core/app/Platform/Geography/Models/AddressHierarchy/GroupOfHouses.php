<?php

namespace App\Platform\Geography\Models\AddressHierarchy;

use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string|null $tenant_id
 * @property string|null $village_id
 * @property string $code
 * @property string $name
 * @property string|null $postal_code
 * @property string|null $postal_code_id
 * @property bool $override_postal_code
 * @property string|null $status
 * @property bool $active
 * @property-read Village|null $village
 * @property-read Collection<int, LandPlot> $landPlots
 */
#[DataClassification(DataClass::CustomerContent)]
final class GroupOfHouses extends Model
{
    use HasUlids;

    /** @var array<string, DataClass> */
    public const COLUMN_CLASSIFICATION = [
        'name' => DataClass::CustomerContent,
    ];

    protected $table = 'ref_group_of_houses';

    protected $fillable = [
        'id',
        'tenant_id',
        'village_id',
        'code',
        'name',
        'postal_code',
        'postal_code_id',
        'override_postal_code',
        'status',
        'active',
    ];

    protected $casts = [
        'override_postal_code' => 'boolean',
        'active' => 'boolean',
    ];

    /** @return BelongsTo<Village, $this> */
    public function village(): BelongsTo
    {
        return $this->belongsTo(Village::class, 'village_id');
    }

    /** @return HasMany<LandPlot, $this> */
    public function landPlots(): HasMany
    {
        return $this->hasMany(LandPlot::class, 'group_of_houses_id');
    }
}
