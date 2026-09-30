<?php

namespace App\Models;

use App\Support\Modules\Contracts\DataClass;
use App\Support\Modules\Contracts\DataClassification;
use Illuminate\Database\Eloquent\Model;

#[DataClassification(DataClass::AccountData)]
class TenantAppEntitlement extends Model
{
    protected $table = 'tenant_app_entitlements';

    protected $fillable = ['tenant_id', 'app_id', 'status', 'starts_at', 'ends_at'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }
}
