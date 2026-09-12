<?php

declare(strict_types=1);

namespace ControlPlane\Models;

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

    /**
     * Pilihan pelanggan untuk formulir, urut nama.
     *
     * @return list<array{id: string, nama: string}>
     */
    public static function pilihan(): array
    {
        return array_values(
            self::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (self $t): array => ['id' => $t->id, 'nama' => $t->name])
                ->all()
        );
    }

    /** @return HasMany<Lingkungan, $this> */
    public function lingkungan(): HasMany
    {
        return $this->hasMany(Lingkungan::class, 'tenant_id');
    }
}
