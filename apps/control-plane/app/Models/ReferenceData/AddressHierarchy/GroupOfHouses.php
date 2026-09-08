<?php

namespace App\Models\ReferenceData\AddressHierarchy;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class GroupOfHouses extends Model
{
    use HasUlids;

    protected $table = 'ref_group_of_houses';

    protected $fillable = [
        'id',
        'tenant_id',
        'village_id',
        'code',
        'name',
        'postal_code',
        'postal_code_id',
        'override_postal_code',
        'status',
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

    public function landPlots(): HasMany
    {
        return $this->hasMany(LandPlot::class, 'group_of_houses_id');
    }
}
