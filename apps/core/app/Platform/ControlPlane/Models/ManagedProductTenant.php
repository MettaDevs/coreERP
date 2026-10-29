<?php

declare(strict_types=1);

namespace App\Platform\ControlPlane\Models;

use App\Platform\ControlPlane\OwnedByControlPlane;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property array{version: string, seed_sha256: string, image_digest: string, profile_sha256: string, base_domain: string} $release
 * @property Carbon|null $lease_until
 * @property Carbon|null $expires_at
 * @property array{customer_id: string, revoked_before: int}|null $pending_sso_revocation
 */
final class ManagedProductTenant extends Model
{
    use OwnedByControlPlane, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['release' => 'array', 'expires_at' => 'datetime', 'lease_until' => 'datetime', 'retry_requested' => 'boolean', 'pending_sso_revocation' => 'array'];
    }

    /** @return array<string, mixed> */
    public function publicData(): array
    {
        return $this->only(['id', 'product', 'profile', 'name', 'slug', 'owner_name', 'owner_email', 'status', 'public_url', 'failure_message', 'created_at', 'expires_at']);
    }
}
