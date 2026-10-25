<?php

declare(strict_types=1);

namespace App\Platform\ControlPlane\Models;

use App\Platform\ControlPlane\OwnedByControlPlane;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** @property string $customer_id */
final class ProductCustomerSession extends Model
{
    use OwnedByControlPlane, SoftDeletes;

    public $incrementing = false;

    protected $primaryKey = 'token_hash';

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}
