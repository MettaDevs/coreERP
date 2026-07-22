<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TenantModuleEntitlement extends Model
{
    protected $fillable = ['tenant_id', 'module_id', 'status', 'starts_at', 'ends_at'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }
}
