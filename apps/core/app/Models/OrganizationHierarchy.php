<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 */
class OrganizationHierarchy extends Model
{
    use HasUlids;

    protected $fillable = ['tenant_id', 'name', 'status'];

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsToMany<HierarchyPurpose, $this> */
    public function purposes(): BelongsToMany
    {
        return $this->belongsToMany(HierarchyPurpose::class, 'organization_hierarchy_purposes', 'hierarchy_id', 'purpose_id');
    }

    /** @return HasMany<OrganizationHierarchyVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(OrganizationHierarchyVersion::class, 'hierarchy_id');
    }
}
