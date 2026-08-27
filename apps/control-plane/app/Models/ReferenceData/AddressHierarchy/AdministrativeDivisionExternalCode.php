<?php

namespace App\Models\ReferenceData\AddressHierarchy;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AdministrativeDivisionExternalCode extends Model
{
    use HasUlids;

    protected $table = 'ref_administrative_division_external_codes';

    protected $fillable = [
        'id',
        'division_id',
        'system',
        'external_code',
        'description',
        'status',
    ];

    public function division(): BelongsTo
    {
        return $this->belongsTo(AdministrativeDivision::class, 'division_id', 'id');
    }
}
