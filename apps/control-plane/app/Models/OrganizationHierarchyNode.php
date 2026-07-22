<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $organization_id
 */
class OrganizationHierarchyNode extends Model
{
    use HasUlids;

    protected $fillable = ['version_id', 'organization_id', 'parent_node_id'];

    /** @return BelongsTo<OrganizationHierarchyVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(OrganizationHierarchyVersion::class, 'version_id');
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<OrganizationHierarchyNode, $this> */
    public function parentNode(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_node_id');
    }
}
