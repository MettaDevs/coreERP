<?php

namespace App\Models\ReferenceData\AddressHierarchy;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class District extends Model
{
    use HasUlids;

    protected $table = 'ref_districts';

    protected $fillable = [
        'tenant_id',
        'regency_id',
        'code',
        'name',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function regency(): BelongsTo
    {
        return $this->belongsTo(Regency::class, 'regency_id');
    }

    public function villages(): HasMany
    {
        return $this->hasMany(Village::class, 'district_id');
    }
}
