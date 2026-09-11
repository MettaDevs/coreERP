<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $hierarchy_id
 * @property string $status
 * @property-read OrganizationHierarchy $hierarchy
 */
class OrganizationHierarchyVersion extends Model
{
    use HasUlids;

    protected $fillable = ['hierarchy_id', 'version_number', 'status', 'effective_from', 'published_at'];

    protected function casts(): array
    {
        return ['effective_from' => 'datetime', 'published_at' => 'datetime'];
    }

    /** @return BelongsTo<OrganizationHierarchy, $this> */
    public function hierarchy(): BelongsTo
    {
        return $this->belongsTo(OrganizationHierarchy::class, 'hierarchy_id');
    }

    /** @return HasMany<OrganizationHierarchyNode, $this> */
    public function nodes(): HasMany
    {
        return $this->hasMany(OrganizationHierarchyNode::class, 'version_id');
    }
}
