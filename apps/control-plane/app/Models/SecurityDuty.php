<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SecurityDuty extends Model
{
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
