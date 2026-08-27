<?php

namespace App\Models\ReferenceData\AddressHierarchy;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class AdministrativeDivisionTimezone extends Model
{
    use HasUlids;

    protected $table = 'ref_administrative_division_timezones';

    protected $fillable = [
        'division_type',
        'division_id',
        'timezone',
        'is_default',
        'status',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];
}
