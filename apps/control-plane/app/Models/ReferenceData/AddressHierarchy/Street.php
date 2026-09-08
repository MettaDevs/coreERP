<?php

namespace App\Models\ReferenceData\AddressHierarchy;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Street extends Model
{
    use HasUlids;

    protected $table = 'ref_streets';

    protected $fillable = [
        'tenant_id',
        'village_id',
        'rt',
        'rw',
        'name',
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

    public function buildings(): HasMany
    {
        return $this->hasMany(Building::class, 'street_id');
    }
}
