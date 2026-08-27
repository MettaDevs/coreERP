<?php

namespace App\Models\ReferenceData\AddressHierarchy;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function provinces(): HasMany
    {
        return $this->hasMany(Province::class, 'country_code', 'code');
    }
}
