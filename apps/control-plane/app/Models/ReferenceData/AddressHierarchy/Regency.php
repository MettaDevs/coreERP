<?php

namespace App\Models\ReferenceData\AddressHierarchy;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class, 'province_id');
    }

    public function districts(): HasMany
    {
        return $this->hasMany(District::class, 'regency_id');
    }
}
