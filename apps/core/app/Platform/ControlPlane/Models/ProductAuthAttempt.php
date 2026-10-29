<?php

declare(strict_types=1);

namespace App\Platform\ControlPlane\Models;

use App\Platform\ControlPlane\OwnedByControlPlane;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string $nonce
 * @property string $code_verifier
 */
final class ProductAuthAttempt extends Model
{
    use OwnedByControlPlane, SoftDeletes;

    public $incrementing = false;

    protected $primaryKey = 'state_hash';

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'consumed_at' => 'datetime'];
    }
}
