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

    /**
     * Daftar pelanggan untuk layar, beserta jumlah lingkungan yang masih hidup.
     *
     * Yang terbaru di atas. Layar ini dibuka paling sering tepat sesudah seorang pelanggan
     * dilahirkan — untuk membaca kata sandi sementaranya — jadi barisnya harus ada di tempat mata
     * jatuh pertama kali, bukan di akhir daftar yang panjang.
     *
     * @return list<array{id: string, nama: string, lingkungan: int, dibuat: ?string}>
     */
    public static function daftar(): array
    {
        return array_values(
            self::query()
                ->withCount(['lingkungan as jumlah_lingkungan' => self::hanyaYangHidup(...)])
                ->orderByDesc('created_at')
                ->orderBy('name')
                ->get(['id', 'name', 'created_at'])
                ->map(fn (self $t): array => [
                    'id' => $t->id,
                    'nama' => $t->name,
                    'lingkungan' => (int) $t->getAttribute('jumlah_lingkungan'),
                    'dibuat' => $t->created_at?->toDateString(),
                ])
                ->all()
        );
    }

    /** @return HasMany<Lingkungan, $this> */
    public function lingkungan(): HasMany
    {
        return $this->hasMany(Lingkungan::class, 'tenant_id');
    }

    /**
     * Lingkungan yang sudah dihapus lunak tidak ikut dihitung.
     *
     * Kalau ikut, angka di layar ini berbeda dari jumlah baris di layar Lingkungan — dan dua angka
     * yang menyebut hal yang sama dengan nilai berbeda selalu membuat yang membacanya berhenti
     * mempercayai keduanya.
     *
     * @param  Builder<Lingkungan>  $kueri
     */
    private static function hanyaYangHidup(Builder $kueri): void
    {
        $kueri->whereNull('deleted_at');
    }
}
