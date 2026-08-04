<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

final class UnitOfMeasure extends Model
{
    use HasUlids, SoftDeletes;

    protected $table = 'units_of_measure';
    protected $fillable = ['tenant_id', 'uom_class_id', 'uom_system_id', 'code', 'name', 'symbol', 'decimal_places', 'active'];
    protected $casts = ['active' => 'boolean'];
}
