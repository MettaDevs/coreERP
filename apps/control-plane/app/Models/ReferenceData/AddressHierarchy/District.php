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
 * @property string $regency_id
 * @property string $code
 * @property string $name
 * @property bool $active
 * @property-read Regency|null $regency
 * @property-read Collection<int, Village> $villages
 */
class District extends Model
{
    use HasUlids;

    protected $table = 'ref_districts';

    protected $fillable = [
        'tenant_id',
        'regency_id',
        'code',
        'name',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    /** @return BelongsTo<Regency, $this> */
    public function regency(): BelongsTo
    {
        return $this->belongsTo(Regency::class, 'regency_id');
    }

    /** @return HasMany<Village, $this> */
    public function villages(): HasMany
    {
        return $this->hasMany(Village::class, 'district_id');
    }
}
