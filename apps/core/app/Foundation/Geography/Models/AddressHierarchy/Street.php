<?php

namespace App\Foundation\Geography\Models\AddressHierarchy;

use App\Support\Modules\Contracts\DataClass;
use App\Support\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string|null $tenant_id
 * @property string|null $village_id
 * @property string|null $rt
 * @property string|null $rw
 * @property string $name
 * @property string|null $postal_code
 * @property string|null $postal_code_id
 * @property bool $override_postal_code
 * @property bool $active
 * @property-read Village|null $village
 * @property-read Collection<int, Building> $buildings
 */
#[DataClassification(DataClass::CustomerContent)]
class Street extends Model
{
    use HasUlids;

    /** @var array<string, DataClass> */
    public const COLUMN_CLASSIFICATION = [
        'name' => DataClass::CustomerContent,
    ];

    protected $table = 'ref_streets';

    protected $fillable = [
        'tenant_id',
        'village_id',
        'rt',
        'rw',
        'name',
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

    /** @return HasMany<Building, $this> */
    public function buildings(): HasMany
    {
        return $this->hasMany(Building::class, 'street_id');
    }
}
