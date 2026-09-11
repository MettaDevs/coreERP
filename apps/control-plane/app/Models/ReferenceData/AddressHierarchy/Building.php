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
 * @property string $name
 * @property string|null $unit
 * @property string|null $floor
 * @property string|null $block
 * @property string|null $postal_code
 * @property string|null $postal_code_id
 * @property bool $override_postal_code
 * @property bool $active
 * @property-read Village|null $village
 * @property-read Street|null $street
 */
class Building extends Model
{
    use HasUlids;

    protected $table = 'ref_buildings';

    protected $fillable = [
        'tenant_id',
        'village_id',
        'street_id',
        'name',
        'unit',
        'floor',
        'block',
        'postal_code',
        'postal_code_id',
        'override_postal_code',
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
}
