<?php

namespace App\Models\ReferenceData\AddressHierarchy;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $country_code
 * @property int $level
 * @property string $level_code
 * @property string $level_name
 * @property string|null $description
 */
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
