<?php

namespace App\Models\ReferenceData\AddressHierarchy;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AdministrativeDivisionTranslation extends Model
{
    use HasUlids;

    protected $table = 'ref_administrative_division_translations';

    protected $fillable = [
        'id',
        'division_id',
        'locale',
        'name',
        'description',
    ];

    public function division(): BelongsTo
    {
        return $this->belongsTo(AdministrativeDivision::class, 'division_id', 'id');
    }
}
