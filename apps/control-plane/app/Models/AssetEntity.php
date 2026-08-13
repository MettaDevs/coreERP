<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

final class AssetEntity extends Model
{
    use HasUlids, SoftDeletes;

    protected $table = 'asset_entities';

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'description',
        'status',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];
}
