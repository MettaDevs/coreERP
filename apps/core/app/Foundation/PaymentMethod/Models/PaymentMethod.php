<?php

namespace App\Foundation\PaymentMethod\Models;

use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property ?string $description
 * @property bool $active
 * @property ?string $creation_key
 * @property ?string $creation_payload_hash
 * @property int $version
 */
#[DataClassification(DataClass::CustomerContent)]
final class PaymentMethod extends Model
{
    use HasUlids, SoftDeletes;

    public const COLUMN_CLASSIFICATION = [
        'name' => DataClass::CustomerContent,
        'description' => DataClass::CustomerContent,
    ];

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'description',
        'active',
        'creation_key',
        'creation_payload_hash',
    ];

    protected $attributes = [
        'active' => true,
        'version' => 1,
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'version' => 'integer',
        ];
    }
}
