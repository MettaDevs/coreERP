<?php

namespace App\Models\ReferenceData\AddressHierarchy;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Village extends Model
{
    use HasUlids;

    protected $table = 'ref_villages';

    protected $fillable = [
        'tenant_id',
        'district_id',
        'code',
        'name',
        'type',
        'postal_code',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class, 'district_id');
    }
}
