<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Permission extends Model
{
    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['code', 'app_id', 'entry_point_code', 'access_level', 'name', 'description'];

    /** @return BelongsTo<AppEntryPoint, $this> */
    public function entryPoint(): BelongsTo
    {
        return $this->belongsTo(AppEntryPoint::class, 'entry_point_code');
    }
}
