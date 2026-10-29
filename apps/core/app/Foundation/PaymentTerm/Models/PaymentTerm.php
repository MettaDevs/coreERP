<?php

namespace App\Foundation\PaymentTerm\Models;

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
 * @property int $due_days
 * @property ?string $description
 * @property bool $active
 * @property ?string $creation_key
 * @property ?string $creation_payload_hash
 * @property int $version
 */
#[DataClassification(DataClass::CustomerContent)]
final class PaymentTerm extends Model
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
        'due_days',
        'description',
        'active',
        'creation_key',
        'creation_payload_hash',
    ];

    protected $attributes = [
        'active' => true,
        'due_days' => 0,
        'version' => 1,
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'due_days' => 'integer',
            'version' => 'integer',
        ];
    }
}
