<?php

namespace App\Platform\Access\Models;

use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $version
 */
#[DataClassification(DataClass::CustomerContent)]
class SecurityDuty extends Model
{
    /** @var array<string, DataClass> */
    public const COLUMN_CLASSIFICATION = [
        'name' => DataClass::CustomerContent,
    ];

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['code', 'app_id', 'tenant_id', 'name', 'description', 'source', 'status', 'published_at'];

    /** @return BelongsToMany<SecurityPrivilege, $this> */
    public function privileges(): BelongsToMany
    {
        return $this->belongsToMany(SecurityPrivilege::class, 'security_duty_privileges', 'duty_code', 'privilege_code');
    }
}
