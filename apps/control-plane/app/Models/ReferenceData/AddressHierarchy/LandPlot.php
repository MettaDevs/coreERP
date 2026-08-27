<?php

namespace App\Models\ReferenceData\AddressHierarchy;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LandPlot extends Model
{
    use HasUlids;

    protected $table = 'ref_land_plots';

    protected $fillable = [
        'id',
        'tenant_id',
        'village_id',
        'street_id',
        'group_of_houses_id',
        'plot_number',
        'name',
        'postal_code',
        'postal_code_id',
        'override_postal_code',
        'status',
        'active',
    ];

    protected $casts = [
        'override_postal_code' => 'boolean',
        'active'               => 'boolean',
    ];

    public function village(): BelongsTo
    {
        return $this->belongsTo(Village::class, 'village_id');
    }

    public function street(): BelongsTo
    {
        return $this->belongsTo(Street::class, 'street_id');
    }

    public function groupOfHouses(): BelongsTo
    {
        return $this->belongsTo(GroupOfHouses::class, 'group_of_houses_id');
    }
}
