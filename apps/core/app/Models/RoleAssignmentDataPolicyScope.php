<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoleAssignmentDataPolicyScope extends Model
{
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'role_assignment_id', 'policy_code', 'legal_entity_id', 'organization_id',
        'hierarchy_id', 'hierarchy_version_id', 'include_descendants', 'valid_from', 'valid_until',
    ];

    protected function casts(): array
    {
        return [
            'include_descendants' => 'boolean',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
        ];
    }

    /** @return BelongsTo<RoleAssignment, $this> */
    public function roleAssignment(): BelongsTo
    {
        return $this->belongsTo(RoleAssignment::class, 'role_assignment_id');
    }

    /** @return BelongsTo<AppDataPolicy, $this> */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(AppDataPolicy::class, 'policy_code', 'code');
    }
}
