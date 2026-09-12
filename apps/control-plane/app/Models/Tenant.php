<?php

declare(strict_types=1);

namespace ControlPlane\Models;

use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Pilihan pelanggan untuk formulir, urut nama.
     *
     * @return list<array{id: string, name: string}>
     */
    public static function options(): array
    {
        return array_values(
            self::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (self $t): array => ['id' => $t->id, 'name' => $t->name])
                ->all()
        );
    }

    /**
     * Daftar pelanggan untuk layar, beserta jumlah lingkungan yang masih hidup.
     *
     * Yang terbaru di atas. Layar ini dibuka paling sering tepat sesudah seorang pelanggan
     * dilahirkan — untuk membaca kata sandi sementaranya — jadi barisnya harus ada di tempat mata
     * jatuh pertama kali, bukan di akhir daftar yang panjang.
     *
     * @return list<array{id: string, name: string, environments: int, createdAt: ?string}>
     */
    public static function forScreen(): array
    {
        return array_values(
            self::query()
                ->withCount(['environments as environments_count' => self::onlyLive(...)])
                ->orderByDesc('created_at')
                ->orderBy('name')
                ->get(['id', 'name', 'created_at'])
                ->map(fn (self $t): array => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'environments' => (int) $t->getAttribute('environments_count'),
                    'createdAt' => $t->created_at?->toDateString(),
                ])
                ->all()
        );
    }

    /** @return HasMany<Environment, $this> */
    public function environments(): HasMany
    {
        return $this->hasMany(Environment::class, 'tenant_id');
    }

    /**
     * Lingkungan yang sudah dihapus lunak tidak ikut dihitung.
     *
     * Kalau ikut, angka di layar ini berbeda dari jumlah baris di layar Lingkungan — dan dua angka
     * yang menyebut hal yang sama dengan nilai berbeda selalu membuat yang membacanya berhenti
     * mempercayai keduanya.
     *
     * @param  Builder<Environment>  $query
     */
    private static function onlyLive(Builder $query): void
    {
        $query->whereNull('deleted_at');
    }
}
