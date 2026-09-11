<?php

declare(strict_types=1);

namespace PusatAdmin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pelanggan, dibaca saja.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string $status
 */
class Tenant extends Model
{
    use HasUlids;

    protected $table = 'tenants';

    /** @return HasMany<Lingkungan, $this> */
    public function lingkungan(): HasMany
    {
        return $this->hasMany(Lingkungan::class, 'tenant_id');
    }
}
