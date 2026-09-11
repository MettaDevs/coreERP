<?php

namespace App\Models;

use App\Support\Pusat\MilikPusat;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property int $user_id
 * @property string $system_role
 * @property string $status
 * @property-read Tenant $tenant
 * @property-read User $user
 */
class TenantMembership extends Model
{
    use HasUlids;
    use MilikPusat;

    protected $fillable = ['tenant_id', 'user_id', 'system_role', 'status'];

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<RoleAssignment, $this> */
    public function roleAssignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class, 'membership_id');
    }

    public function canManageAccess(): bool
    {
        return $this->status === 'active' && in_array($this->system_role, ['owner', 'admin'], true);
    }
}
