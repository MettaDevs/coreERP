<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $classification
 */
class Organization extends Model
{
    use HasUlids;

    protected $fillable = ['tenant_id', 'code', 'name', 'classification', 'status'];

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return HasOne<LegalEntity, $this> */
    public function legalEntity(): HasOne
    {
        return $this->hasOne(LegalEntity::class);
    }

    /** @return HasOne<OperatingUnit, $this> */
    public function operatingUnit(): HasOne
    {
        return $this->hasOne(OperatingUnit::class);
    }
}
