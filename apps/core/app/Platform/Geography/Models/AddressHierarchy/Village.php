<?php

namespace App\Platform\Geography\Models\AddressHierarchy;

use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string|null $tenant_id
 * @property string $district_id
 * @property string $code
 * @property string $name
 * @property string|null $type
 * @property string|null $postal_code
 * @property bool $active
 * @property-read District|null $district
 */
#[DataClassification(DataClass::CustomerContent)]
class Village extends Model
{
    use HasUlids;

    /** @var array<string, DataClass> */
    public const COLUMN_CLASSIFICATION = [
        'name' => DataClass::CustomerContent,
    ];

    protected $table = 'ref_villages';

    protected $fillable = [
        'tenant_id',
        'district_id',
        'code',
        'name',
        'type',
        'postal_code',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    /** @return BelongsTo<District, $this> */
    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class, 'district_id');
    }
}
