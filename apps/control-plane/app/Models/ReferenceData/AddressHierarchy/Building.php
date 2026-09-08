<?php

namespace App\Models\ReferenceData\AddressHierarchy;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Building extends Model
{
    use HasUlids;

    protected $table = 'ref_buildings';

    protected $fillable = [
        'tenant_id',
        'village_id',
        'street_id',
        'name',
        'unit',
        'floor',
        'block',
        'postal_code',
        'postal_code_id',
        'override_postal_code',
        'active',
    ];

    protected $casts = [
        'override_postal_code' => 'boolean',
        'active' => 'boolean',
    ];

    public function village(): BelongsTo
    {
        return $this->belongsTo(Village::class, 'village_id');
    }

    public function street(): BelongsTo
    {
        return $this->belongsTo(Street::class, 'street_id');
    }
}
