<?php

namespace App\Models\ReferenceData\AddressHierarchy;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'default_state'   => 'boolean',
        'union_territory' => 'boolean',
        'active'          => 'boolean',
    ];

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_code', 'code');
    }

    public function regencies(): HasMany
    {
        return $this->hasMany(Regency::class, 'province_id');
    }
}
