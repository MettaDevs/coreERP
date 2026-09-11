<?php

namespace App\Models\ReferenceData\AddressHierarchy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string $id
 * @property string $country_id
 * @property string|null $parent_id
 * @property int $level
 * @property string $type
 * @property string $official_code
 * @property string|null $display_code
 * @property string $name
 * @property string $status
 * @property array<mixed>|null $lineage
 * @property-read Country|null $country
 * @property-read AdministrativeDivision|null $parent
 * @property-read Collection<int, AdministrativeDivision> $children
 * @property-read AdministrativeDivisionTimezone|null $timezone
 * @property-read Collection<int, AdministrativeDivisionExternalCode> $externalCodes
 * @property-read Collection<int, AdministrativeDivisionTranslation> $translations
 */
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
        'level' => 'integer',
        'lineage' => 'array',
    ];

    /** @return BelongsTo<Country, $this> */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_id', 'code');
    }

    /** @return BelongsTo<AdministrativeDivision, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(AdministrativeDivision::class, 'parent_id', 'id');
    }

    /** @return HasMany<AdministrativeDivision, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(AdministrativeDivision::class, 'parent_id', 'id');
    }

    /** @return HasOne<AdministrativeDivisionTimezone, $this> */
    public function timezone(): HasOne
    {
        return $this->hasOne(AdministrativeDivisionTimezone::class, 'division_id', 'id');
    }

    /** @return HasMany<AdministrativeDivisionExternalCode, $this> */
    public function externalCodes(): HasMany
    {
        return $this->hasMany(AdministrativeDivisionExternalCode::class, 'division_id', 'id');
    }

    /** @return HasMany<AdministrativeDivisionTranslation, $this> */
    public function translations(): HasMany
    {
        return $this->hasMany(AdministrativeDivisionTranslation::class, 'division_id', 'id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeProvinces(Builder $query): Builder
    {
        return $query->where('level', 1);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeRegencies(Builder $query): Builder
    {
        return $query->where('level', 2);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDistricts(Builder $query): Builder
    {
        return $query->where('level', 3);
    }
}
