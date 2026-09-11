<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $code
 * @property bool $is_continuous
 * @property bool $allow_manual
 * @property bool $preallocation_enabled
 * @property int $preallocation_quantity
 * @property array<int, array<string, mixed>> $segments
 */
class NumberSequenceProfile extends Model
{
    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['code', 'name', 'is_continuous', 'allow_manual', 'preallocation_enabled', 'preallocation_quantity', 'segments'];

    protected function casts(): array
    {
        return ['is_continuous' => 'boolean', 'allow_manual' => 'boolean', 'preallocation_enabled' => 'boolean', 'segments' => 'array'];
    }
}
