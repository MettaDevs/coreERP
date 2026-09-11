<?php

namespace App\Models\ReferenceData\AddressHierarchy;

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
 * @property-read Country|null $country
 * @property-read Collection<int, Regency> $regencies
 */
class Province extends Model
{
    use HasUlids;

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

    /** @return BelongsTo<Country, $this> */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_code', 'code');
    }

    /** @return HasMany<Regency, $this> */
    public function regencies(): HasMany
    {
        return $this->hasMany(Regency::class, 'province_id');
    }
}
