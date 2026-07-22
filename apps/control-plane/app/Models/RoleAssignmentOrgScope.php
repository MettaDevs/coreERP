<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoleAssignmentOrgScope extends Model
{
    protected $fillable = ['assignment_id', 'organization_id', 'hierarchy_id', 'hierarchy_version_id', 'include_descendants'];

    protected function casts(): array
    {
        return ['include_descendants' => 'boolean'];
    }

    /** @return BelongsTo<RoleAssignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(RoleAssignment::class, 'assignment_id');
    }
}
