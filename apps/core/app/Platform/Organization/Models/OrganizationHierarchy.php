<?php

namespace App\Platform\Organization\Models;

use App\Models\Tenant;
use App\Support\Modules\Contracts\DataClass;
use App\Support\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 */
#[DataClassification(DataClass::CustomerContent)]
class OrganizationHierarchy extends Model
{
    use HasUlids;

    /** @var array<string, DataClass> */
    public const COLUMN_CLASSIFICATION = [
        'name' => DataClass::CustomerContent,
    ];

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
