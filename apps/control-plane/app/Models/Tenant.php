<?php

declare(strict_types=1);

namespace ControlPlane\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Pelanggan, dibaca saja.
 *
 * Tetap "dibaca saja" meskipun konsol ini kini punya layar yang melahirkan pelanggan baru: yang
 * menulis barisnya adalah Core, dipanggil lewat HTTP. Model ini tidak punya `$fillable` dan tidak
 * boleh diberi — begitu ia punya, ada dua tempat yang dapat membuat tenant, dan yang menyimpang di
 * antara keduanya adalah rantai izin.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string $status
 * @property ?Carbon $created_at
 */
class Tenant extends Model
{
    use HasUlids;

    protected $table = 'tenants';

    /** @return HasMany<Environment, $this> */
    public function environments(): HasMany
    {
        return $this->hasMany(Environment::class, 'tenant_id');
    }
}
