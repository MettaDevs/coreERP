<?php

declare(strict_types=1);

namespace App\Platform\ControlPlane\Models;

use App\Platform\ControlPlane\OwnedByControlPlane;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $identity_hash
 * @property string $issuer
 * @property string $subject
 * @property string $name
 * @property string|null $email
 * @property string|null $verified_email
 * @property Carbon|null $email_verified_at
 */
final class ProductCustomer extends Model
{
    use OwnedByControlPlane, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime'];
    }
}
