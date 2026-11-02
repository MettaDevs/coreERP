<?php

declare(strict_types=1);

namespace App\Platform\Tenant\Models;

use App\Platform\ControlPlane\OwnedByControlPlane;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string $actor_scope
 * @property string $idempotency_key
 * @property string $request_hash
 * @property int|null $actor_user_id
 * @property int $principal_id
 * @property string $tenant_id
 * @property string $membership_id
 */
final class TenantCreationRequest extends Model
{
    use HasUlids, OwnedByControlPlane, SoftDeletes;

    protected $guarded = [];
}
