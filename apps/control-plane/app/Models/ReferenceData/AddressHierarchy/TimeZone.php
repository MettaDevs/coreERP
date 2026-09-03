<?php

namespace App\Models\ReferenceData\AddressHierarchy;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimeZone extends Model
{
    use HasUlids;

    protected $table = 'time_zones';

    protected $fillable = [
        'id',
        'iana_name',
        'display_name',
        'utc_offset',
        'country_code',
        'is_default',
        'active',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'active'     => 'boolean',
    ];

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_code', 'code');
    }
}
