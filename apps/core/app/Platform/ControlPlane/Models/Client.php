<?php

namespace App\Platform\ControlPlane\Models;

use App\Models\Tenant;
use App\Platform\ControlPlane\OwnedByControlPlane;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    use HasUlids;
    use OwnedByControlPlane;

    protected $fillable = ['legal_name', 'slug', 'status'];

    /** @return HasMany<Tenant, $this> */
    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class);
    }
}
