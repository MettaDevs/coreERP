<?php

namespace App\Foundation\Geography\Models\AddressHierarchy;

use App\Foundation\Geography\Models\CountryRegion;
use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string|null $tenant_id
 * @property string $country_code
 * @property string $postal_code
 * @property string $area_name
 * @property string|null $source
 * @property string|null $source_reference
 * @property string $status
 * @property string|null $province_id
 * @property string|null $regency_id
 * @property string|null $district_id
 * @property string|null $village_id
 * @property bool $active
 * @property-read CountryRegion|null $country
 * @property-read Province|null $province
 * @property-read Regency|null $regency
 * @property-read District|null $district
 * @property-read Village|null $village
 */
#[DataClassification(DataClass::CustomerContent)]
class PostalCode extends Model
{
    use HasUlids;

    /** @var array<string, DataClass> */
    public const COLUMN_CLASSIFICATION = [
        'area_name' => DataClass::CustomerContent,
    ];

    protected $table = 'ref_postal_codes';

    protected $fillable = [
        'tenant_id',
        'country_code',
        'postal_code',
        'area_name',
        'source',
        'source_reference',
        'status',
        'province_id',
        'regency_id',
        'district_id',
        'village_id',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    /** @return BelongsTo<CountryRegion, $this> */
    public function country(): BelongsTo
    {
        return $this->belongsTo(CountryRegion::class, 'country_code', 'code');
    }

    /** @return BelongsTo<Province, $this> */
    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class, 'province_id');
    }

    /** @return BelongsTo<Regency, $this> */
    public function regency(): BelongsTo
    {
        return $this->belongsTo(Regency::class, 'regency_id');
    }

    /** @return BelongsTo<District, $this> */
    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class, 'district_id');
    }

    /** @return BelongsTo<Village, $this> */
    public function village(): BelongsTo
    {
        return $this->belongsTo(Village::class, 'village_id');
    }
}
