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
 * @property string $province_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string|null $type
 * @property string|null $it_county_code
 * @property string|null $es_county_code
 * @property bool $active
 * @property-read Province|null $province
 * @property-read Collection<int, District> $districts
 */
class Regency extends Model
{
    use HasUlids;

    protected $table = 'ref_regencies';

    protected $fillable = [
        'tenant_id',
        'province_id',
        'code',
        'name',
        'description',
        'type',
        'it_county_code',
        'es_county_code',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    /** @return BelongsTo<Province, $this> */
    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class, 'province_id');
    }

    /** @return HasMany<District, $this> */
    public function districts(): HasMany
    {
        return $this->hasMany(District::class, 'regency_id');
    }
}
