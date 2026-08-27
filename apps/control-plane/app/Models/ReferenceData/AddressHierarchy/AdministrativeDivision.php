<?php

namespace App\Models\ReferenceData\AddressHierarchy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class AdministrativeDivision extends Model
{
    use HasUlids;

    protected $table = 'ref_administrative_divisions';

    protected $fillable = [
        'id',
        'country_id',
        'parent_id',
        'level',
        'type',
        'official_code',
        'display_code',
        'name',
        'status',
        'lineage',
    ];

    protected $casts = [
        'level'   => 'integer',
        'lineage' => 'array',
    ];

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_id', 'code');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(AdministrativeDivision::class, 'parent_id', 'id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(AdministrativeDivision::class, 'parent_id', 'id');
    }

    public function timezone(): HasOne
    {
        return $this->hasOne(AdministrativeDivisionTimezone::class, 'division_id', 'id');
    }

    public function externalCodes(): HasMany
    {
        return $this->hasMany(AdministrativeDivisionExternalCode::class, 'division_id', 'id');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(AdministrativeDivisionTranslation::class, 'division_id', 'id');
    }

    public function scopeProvinces(Builder $query): Builder
    {
        return $query->where('level', 1);
    }

    public function scopeRegencies(Builder $query): Builder
    {
        return $query->where('level', 2);
    }

    public function scopeDistricts(Builder $query): Builder
    {
        return $query->where('level', 3);
    }
}
