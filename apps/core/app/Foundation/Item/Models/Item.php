<?php

namespace App\Foundation\Item\Models;

use App\Foundation\UnitOfMeasure\Models\UnitOfMeasure;
use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property string $type
 * @property ?string $unit_of_measure_id
 * @property ?string $description
 * @property bool $active
 * @property ?string $creation_key
 * @property ?string $creation_payload_hash
 * @property int $version
 * @property-read ?UnitOfMeasure $unitOfMeasure
 */
#[DataClassification(DataClass::CustomerContent)]
final class Item extends Model
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
        'type',
        'unit_of_measure_id',
        'description',
        'active',
        'creation_key',
        'creation_payload_hash',
    ];

    protected $attributes = [
        'type' => 'service',
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

    /** @return BelongsTo<UnitOfMeasure, $this> */
    public function unitOfMeasure(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'unit_of_measure_id');
    }
}
