<?php

namespace App\Models;

use App\Support\ControlPlane\OwnedByControlPlane;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Satu tempat kerja milik sebuah tenant.
 *
 * Satu tenant boleh punya lebih dari satu: `production` tempat ia bekerja, `sandbox` salinan
 * produksinya, dan `demo` berisi data contoh yang berumur tetap. Yang membedakan keduanya bukan
 * sakelar di dalam aplikasi melainkan baris ini — dan sebagian besar aturannya ditegakkan
 * constraint database, bukan kode, supaya jalur yang lupa memeriksanya tetap tidak bisa membuat
 * keadaan terlarang.
 *
 * `database_name` yang kosong berarti environment ini ikut database koneksi bawaan. Itu keadaan
 * pooled dan on-prem, dan ia permanen di sana.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $kind
 * @property string $name
 * @property string $slug
 * @property ?string $database_name
 * @property string $status
 * @property bool $outbound_allowed
 * @property ?Carbon $purged_at
 */
class Environment extends Model
{
    use HasUlids;
    use OwnedByControlPlane;

    protected $fillable = [
        'tenant_id',
        'kind',
        'name',
        'slug',
        'database_name',
        'status',
        'source_environment_id',
        'copied_at',
        'expires_at',
        'outbound_allowed',
        'schema_migrated_at',
        'schema_fingerprint',
        'created_by',
    ];

    /*
     * `deleted_at`, `purge_after`, dan `purged_at` sengaja TIDAK dapat diisi massal.
     *
     * Keduanya terikat satu sama lain dan pada `status` oleh constraint database: menghapus lunak
     * wajib menulis ketiganya sekaligus, dan menulis salah satunya saja ditolak PostgreSQL. Trait
     * SoftDeletes juga tidak dipakai karena `delete()` miliknya hanya menyentuh `deleted_at` —
     * ia akan selalu gagal di sini. Jalur penghapusannya dibangun tersendiri.
     */

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'outbound_allowed' => 'boolean',
            'copied_at' => 'datetime',
            'expires_at' => 'datetime',
            'schema_migrated_at' => 'datetime',
            'deleted_at' => 'datetime',
            'purge_after' => 'datetime',
            'purged_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<Environment, $this> */
    public function sumber(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_environment_id');
    }

    /** @return HasMany<EnvironmentOperation, $this> */
    public function operations(): HasMany
    {
        return $this->hasMany(EnvironmentOperation::class);
    }

    public function produksi(): bool
    {
        return $this->kind === 'production';
    }
}
