<?php

declare(strict_types=1);

namespace ControlPlane\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $user_id
 * @property string $role
 */
class ProviderAccess extends Model
{
    protected $table = 'provider_access';

    protected $primaryKey = 'user_id';

    public $incrementing = false;
}
