<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string $id
 * @property string $membership_id
 * @property string $role_id
 * @property-read Role $role
 */
class RoleAssignment extends Model
{
    use HasUlids;

    protected $fillable = ['membership_id', 'role_id', 'source', 'status', 'valid_from', 'valid_until'];

    protected function casts(): array
    {
        return ['valid_from' => 'datetime', 'valid_until' => 'datetime'];
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** @return HasOne<RoleAssignmentOrgScope, $this> */
    public function organizationScope(): HasOne
    {
        return $this->hasOne(RoleAssignmentOrgScope::class, 'assignment_id');
    }
}
