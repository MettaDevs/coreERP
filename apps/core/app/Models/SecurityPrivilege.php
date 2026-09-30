<?php

namespace App\Models;

use App\Support\Modules\Contracts\DataClass;
use App\Support\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $version
 */
#[DataClassification(DataClass::CustomerContent)]
class SecurityPrivilege extends Model
{
    /** @var array<string, DataClass> */
    public const COLUMN_CLASSIFICATION = [
        'name' => DataClass::CustomerContent,
    ];

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['code', 'app_id', 'tenant_id', 'name', 'description', 'source', 'status', 'published_at'];

    /** @return BelongsToMany<Permission, $this> */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'security_privilege_permissions', 'privilege_code', 'permission_code');
    }
}
