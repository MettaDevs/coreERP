<?php

namespace App\Models\ReferenceData\AddressHierarchy;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class CountryHierarchyLevel extends Model
{
    use HasUlids;

    protected $table = 'ref_country_hierarchy_levels';

    protected $fillable = [
        'country_code',
        'level',
        'level_code',
        'level_name',
        'description',
    ];

    protected $casts = [
        'level' => 'integer',
    ];
}
