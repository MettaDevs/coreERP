<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HierarchyPurpose extends Model
{
    use HasUlids;

    protected $fillable = ['code', 'name', 'description'];

    /** @return HasMany<HierarchyPurposeOrganizationType, $this> */
    public function allowedOrganizationTypes(): HasMany
    {
        return $this->hasMany(HierarchyPurposeOrganizationType::class, 'purpose_id');
    }
}
