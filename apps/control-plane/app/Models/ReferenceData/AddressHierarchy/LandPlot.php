<?php

namespace App\Models\ReferenceData\AddressHierarchy;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string|null $tenant_id
 * @property string|null $village_id
 * @property string|null $street_id
 * @property string|null $group_of_houses_id
 * @property string $plot_number
 * @property string|null $name
 * @property string|null $postal_code
 * @property string|null $postal_code_id
 * @property bool $override_postal_code
 * @property string|null $status
 * @property bool $active
 * @property-read Village|null $village
 * @property-read Street|null $street
 * @property-read GroupOfHouses|null $groupOfHouses
 */
final class LandPlot extends Model
{
    use HasUlids;

    protected $table = 'ref_land_plots';

    protected $fillable = [
        'id',
        'tenant_id',
        'village_id',
        'street_id',
        'group_of_houses_id',
        'plot_number',
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

    /** @return BelongsTo<Street, $this> */
    public function street(): BelongsTo
    {
        return $this->belongsTo(Street::class, 'street_id');
    }

    /** @return BelongsTo<GroupOfHouses, $this> */
    public function groupOfHouses(): BelongsTo
    {
        return $this->belongsTo(GroupOfHouses::class, 'group_of_houses_id');
    }
}
