<?php

namespace App\Models;

use App\Support\Modules\Contracts\DataClass;
use App\Support\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $version
 */
#[DataClassification(DataClass::CustomerContent)]
final class UnitOfMeasure extends Model
{
    use HasUlids, SoftDeletes;

    /** @var array<string, DataClass> */
    public const COLUMN_CLASSIFICATION = [
        'name' => DataClass::CustomerContent,
    ];

    protected $table = 'units_of_measure';

    protected $fillable = ['tenant_id', 'uom_class_id', 'uom_system_id', 'code', 'name', 'symbol', 'decimal_places', 'active'];

    protected $casts = ['active' => 'boolean'];
}
