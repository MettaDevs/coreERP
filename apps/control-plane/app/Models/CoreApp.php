<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $name
 * @property string|null $ui_entry
 * @property array<string, mixed>|null $navigation
 * @property string|null $repository_url
 * @property string|null $contract_url
 */
class CoreApp extends Model
{
    protected $table = 'apps';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'name',
        'version',
        'status',
        'database_name',
        'ui_entry',
        'navigation',
        'repository_url',
        'contract_url',
        'description',
    ];

    protected function casts(): array
    {
        return ['navigation' => 'array'];
    }

    /** @return HasMany<Permission, $this> */
    public function permissions(): HasMany
    {
        return $this->hasMany(Permission::class, 'app_id');
    }

    /** @return HasMany<SecurityDuty, $this> */
    public function duties(): HasMany
    {
        return $this->hasMany(SecurityDuty::class, 'app_id');
    }

    /** @return HasMany<AppRelease, $this> */
    public function releases(): HasMany
    {
        return $this->hasMany(AppRelease::class, 'app_id');
    }
}
