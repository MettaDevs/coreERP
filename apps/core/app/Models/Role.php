<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property bool $is_active
 */
class Role extends Model
{
    use HasUlids;

    protected $fillable = ['tenant_id', 'name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsToMany<SecurityDuty, $this> */
    public function duties(): BelongsToMany
    {
        return $this->belongsToMany(SecurityDuty::class, 'security_role_duties', 'role_id', 'duty_code');
    }

    /**
     * Role yang berada di bawah role ini. Hak seluruh child ikut berlaku bagi
     * pemegang role ini.
     *
     * @return BelongsToMany<Role, $this>
     */
    public function children(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'security_role_children', 'parent_role_id', 'child_role_id')
            ->withTimestamps();
    }

    /** @return BelongsToMany<Role, $this> */
    public function parents(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'security_role_children', 'child_role_id', 'parent_role_id')
            ->withTimestamps();
    }
}
