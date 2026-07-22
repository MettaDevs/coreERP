<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property string $id @property string $name */
class CoreModule extends Model
{
    protected $table = 'modules';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'name', 'version', 'status', 'database_name', 'description'];

    /** @return HasMany<Permission, $this> */
    public function permissions(): HasMany
    {
        return $this->hasMany(Permission::class, 'module_id');
    }

    /** @return HasMany<SecurityDuty, $this> */
    public function duties(): HasMany
    {
        return $this->hasMany(SecurityDuty::class, 'module_id');
    }
}
