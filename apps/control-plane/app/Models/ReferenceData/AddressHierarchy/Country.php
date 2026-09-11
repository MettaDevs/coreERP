<?php

namespace App\Models\ReferenceData\AddressHierarchy;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $code
 * @property string|null $iso3
 * @property string $name
 * @property string|null $phone_code
 * @property string|null $timezone
 * @property bool $active
 * @property-read Collection<int, Province> $provinces
 * @property-read Collection<int, TimeZone> $timezones
 */
class Country extends Model
{
    protected $table = 'ref_countries';

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'code',
        'iso3',
        'name',
        'phone_code',
        'timezone',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    /** @return HasMany<Province, $this> */
    public function provinces(): HasMany
    {
        return $this->hasMany(Province::class, 'country_code', 'code');
    }

    /** @return HasMany<TimeZone, $this> */
    public function timezones(): HasMany
    {
        return $this->hasMany(TimeZone::class, 'country_code', 'code');
    }
}
