<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Katalog app yang dikenal platform.
 *
 * `database_name` bernilai null untuk module: module berjalan di dalam runtime Core dan
 * memakai database Core, jadi ia tidak punya nama database sendiri untuk disebutkan.
 *
 * @property string $id
 * @property string $name
 * @property string|null $database_name
 * @property bool $has_ui
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
        'has_ui',
        'navigation',
        'repository_url',
        'contract_url',
        'description',
    ];

    protected function casts(): array
    {
        return ['navigation' => 'array', 'has_ui' => 'boolean'];
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

    /** @return BelongsToMany<CoreApp, $this> */
    public function dependencies(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'app_dependencies', 'app_id', 'depends_on_app_id')
            ->withPivot('version_range')
            ->withTimestamps();
    }
}
