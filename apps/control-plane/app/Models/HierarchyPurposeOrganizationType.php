<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HierarchyPurposeOrganizationType extends Model
{
    public $timestamps = false;

    protected $table = 'hierarchy_purpose_organization_types';

    protected $fillable = ['purpose_id', 'organization_type'];

    /** @return BelongsTo<HierarchyPurpose, $this> */
    public function purpose(): BelongsTo
    {
        return $this->belongsTo(HierarchyPurpose::class, 'purpose_id');
    }
}
