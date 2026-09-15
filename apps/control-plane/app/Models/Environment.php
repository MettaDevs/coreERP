<?php

declare(strict_types=1);

namespace ControlPlane\Models;

use ControlPlane\Environments\EnvironmentAddress;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Satu tempat kerja milik sebuah tenant — tabel `environments` milik Core.
 *
 * Kelasnya bernama `Environment`, layarnya berbunyi "Lingkungan", dan keduanya benar. Microsoft
 * memakai "Lingkungan" pada localization Indonesia produknya, jadi itu kata yang dibaca operator;
 * nama di dalam kode berbahasa Inggris karena ia dibaca berdampingan dengan Laravel dan PostgreSQL
 * yang seluruhnya begitu. Aturannya di `AGENTS.md`.
 *
 * (Docblock ini sebelumnya menjelaskan kebalikannya — bahwa kelasnya sengaja dinamai "Lingkungan".
 * Alasan itu gugur pada 12 September 2026, dan kalimatnya ditulis ulang alih-alih dibiarkan
 * bertentangan dengan berkas yang memuatnya.)
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
 * @property string $hosting
 * @property bool $outbound_allowed
 * @property ?Carbon $expires_at
 * @property ?Carbon $deleted_at
 * @property ?Carbon $created_at
 */
class Environment extends Model
{
    use HasUlids;

    /** Jenis yang dikenal registry. Sama persis dengan CHECK `environments_kind_dikenal`. */
    public const KINDS = ['production', 'sandbox', 'demo'];

    /** Tempat lingkungan berjalan. Sama persis dengan CHECK `environments_hosting_dikenal`. */
    public const HOSTINGS = ['provider', 'client_server'];

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

    /** @return HasMany<EnvironmentOperation, $this> */
    public function operations(): HasMany
    {
        return $this->hasMany(EnvironmentOperation::class, 'environment_id');
    }

    /**
     * Server klien yang menjalankan lingkungan ini. Paling banyak satu — `sites_satu_per_lingkungan`.
     *
     * @return HasOne<Site, $this>
     */
    public function site(): HasOne
    {
        return $this->hasOne(Site::class, 'environment_id');
    }

    /**
     * Apakah lingkungan ini produksi yang berjalan di server milik klien.
     *
     * Hanya pada lingkungan seperti ini panel "Server klien" dan perintah pasang punya arti. Constraint
     * `environments_server_klien_hanya_produksi` sudah menjamin `client_server` selalu produksi; jenisnya
     * tetap diperiksa di sini supaya penjaga konsol tidak bergantung pada constraint di database lain.
     */
    public function runsOnClientServer(): bool
    {
        return $this->kind === 'production' && $this->hosting === 'client_server' && $this->deleted_at === null;
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
        return $this->database_name ?? (string) config('database.connections.pgsql.database');
    }

    /**
     * Bentuk yang dikirim ke layar.
     *
     * Ditaruh di model, bukan diulang di tiap controller. Dua layar yang memetakan baris yang sama
     * dengan tangan akan menyimpang diam-diam, dan yang menyimpang biasanya kolom yang paling
     * jarang dilihat — persis yang akan salah dibaca ketika akhirnya dilihat.
     *
     * Nilai mentah dari database ikut apa adanya (`production`, `provisioning`); penerjemahannya ke
     * bahasa layar dikerjakan satu berkas di sisi React. Menerjemahkannya di sini berarti layar
     * tidak dapat lagi membedakan dua status yang kebetulan berbunyi mirip.
     *
     * @return array<string, mixed>
     */
    public function forScreen(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'kind' => $this->kind,
            'status' => $this->status,
            'hosting' => $this->hosting,
            'outboundAllowed' => $this->outbound_allowed,
            'database' => $this->database(),
            'expiresAt' => $this->expires_at?->toDateString(),
            'tenant' => $this->tenant->name ?? 'Tanpa tenant',
            /*
             * Alamat yang diketik pelanggan — dan sampai sekarang ia tidak pernah ditampilkan
             * di mana pun.
             *
             * Operator yang baru saja membuat lingkungan tidak punya cara mengetahui ke mana
             * pelanggannya harus diarahkan: bentuknya dihitung `EnvironmentAddress`, dan satu
             * satunya tempat aturan itu tertulis adalah kode Core. Menyuruh orang menyusunnya
             * sendiri dari slug tenant, slug lingkungan, dan jenisnya adalah cara tercepat
             * melahirkan alamat yang salah ketik lalu dilaporkan sebagai "tidak bisa dibuka".
             *
             * Kosong ketika domain dasar belum disetel — on-prem dan pengembangan lokal — dan di
             * sana memang tidak ada alamat per lingkungan sama sekali.
             */
            'url' => $this->url(),
        ];
    }

    /**
     * Alamat lengkap lingkungan ini, atau null bila penempatan ini satu alamat untuk semua.
     *
     * Aturannya dihitung di satu tempat — `EnvironmentAddress` — dan dibaca dari sana, bukan
     * disusun ulang di layar. Dua tempat yang menyusun alamat yang sama akan menyimpang, dan
     * penyimpangannya berbentuk pelanggan yang tidak dapat masuk ke alamat yang dicetak sistem
     * itu sendiri.
     */
    public function url(): ?string
    {
        return EnvironmentAddress::forEnvironment($this->tenant->slug ?? '', $this->kind);
    }
}
