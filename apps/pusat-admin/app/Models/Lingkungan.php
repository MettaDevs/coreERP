<?php

declare(strict_types=1);

namespace PusatAdmin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Satu tempat kerja milik sebuah tenant — tabel `environments` milik Core.
 *
 * Namanya "Lingkungan" dan bukan "Environment" karena itu kata yang muncul di layar. Microsoft
 * memakai "Lingkungan" pada localization Indonesia produknya, dan layar ini mengikuti; prosa teknis
 * di `docs/dev` tetap memakai "environment" supaya tidak bertabrakan dengan "lingkungan lokal".
 *
 * Sebagian besar aturannya tidak ditegakkan di kelas ini melainkan oleh CHECK constraint dan
 * partial unique index di PostgreSQL. Itu disengaja: aturan yang hanya hidup di kode akan dilewati
 * oleh jalur yang lupa memanggilnya, dan konsol ini bukan satu-satunya yang menulis ke tabelnya.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $kind
 * @property string $name
 * @property string $slug
 * @property ?string $database_name
 * @property string $status
 * @property bool $outbound_allowed
 * @property ?Carbon $expires_at
 * @property ?Carbon $deleted_at
 * @property ?Carbon $created_at
 */
class Lingkungan extends Model
{
    use HasUlids;

    protected $table = 'environments';

    protected $fillable = [
        'tenant_id',
        'kind',
        'name',
        'slug',
        'status',
        'source_environment_id',
        'expires_at',
        'outbound_allowed',
        'created_by',
    ];

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
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /** @return HasMany<OperasiLingkungan, $this> */
    public function operasi(): HasMany
    {
        return $this->hasMany(OperasiLingkungan::class, 'environment_id');
    }

    /**
     * Tempat data lingkungan ini sebenarnya berada.
     *
     * `database_name` yang kosong berarti "ikut database koneksi bawaan", dan itu bukan keadaan
     * setengah jadi: pada on-prem dan pada penempatan pooled, banyak tenant memang berbagi satu
     * database selamanya.
     */
    public function database(): string
    {
        return $this->database_name ?? config('database.connections.pgsql.database');
    }
}
