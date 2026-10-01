<?php

namespace App\Platform\Geography\Models\AddressHierarchy;

use App\Platform\Geography\Models\CountryRegion;
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
 * @property string $country_code
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string|null $timezone
 * @property string|null $intrastat
 * @property string|null $it_state_code
 * @property string|null $state_code
 * @property bool $default_state
 * @property bool $union_territory
 * @property bool $active
 * @property-read CountryRegion|null $country
 * @property-read Collection<int, Regency> $regencies
 */
#[DataClassification(DataClass::CustomerContent)]
class Province extends Model
{
    use HasUlids;

    /** @var array<string, DataClass> */
    public const COLUMN_CLASSIFICATION = [
        'name' => DataClass::CustomerContent,
    ];

    protected $table = 'ref_provinces';

    protected $fillable = [
        'tenant_id',
        'country_code',
        'code',
        'name',
        'description',
        'timezone',
        'intrastat',
        'it_state_code',
        'state_code',
        'default_state',
        'union_territory',
        'active',
    ];

    protected $casts = [
        'default_state' => 'boolean',
        'union_territory' => 'boolean',
        'active' => 'boolean',
    ];

    /** @return BelongsTo<CountryRegion, $this> */
    public function country(): BelongsTo
    {
        return $this->belongsTo(CountryRegion::class, 'country_code', 'code');
    }

    /** @return HasMany<Regency, $this> */
    public function regencies(): HasMany
    {
        return $this->hasMany(Regency::class, 'province_id');
    }
}
