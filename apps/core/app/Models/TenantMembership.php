<?php

namespace App\Models;

use App\Support\Access\CorePermissions;
use App\Support\ControlPlane\OwnedByControlPlane;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property int $user_id
 * @property string $status
 * @property-read Tenant $tenant
 * @property-read User $user
 */
class TenantMembership extends Model
{
    use HasUlids;
    use OwnedByControlPlane;

    protected $fillable = ['tenant_id', 'user_id', 'status'];

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

    /**
     * Apakah anggota ini memegang satu permission layar Core (`CoreSecurityCatalog`) lewat role-nya.
     *
     * Pengganti `canManageAccess()`: owner dan admin tidak ada lagi di luar rantai security role (SEC-22).
     */
    public function hasCorePermission(string $permission): bool
    {
        return app(CorePermissions::class)->allows($this, $permission);
    }
}
