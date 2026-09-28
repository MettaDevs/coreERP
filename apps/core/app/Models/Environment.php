<?php

namespace App\Models;

use App\Support\ControlPlane\OwnedByControlPlane;
use Illuminate\Database\Eloquent\Builder;
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
 * ## Lingkungan yang berjalan di server klien
 *
 * `hosting = client_server` berarti barisnya tercatat di sini tetapi **isinya tidak**: produksi itu
 * berjalan di server milik klien, dipasang dan diperbarui lewat agen dari admin.erp. Bagi Core di
 * server kami ia tempat yang tidak ada — tidak dirutekan, tidak disiapkan, tidak disalin, tidak
 * diperbarui, dan tidak dipasangi module. `database_name`-nya kosong (dijamin constraint), dan justru
 * itu yang membuatnya berbahaya: kosong berarti "ikut database bawaan", sehingga setiap jalur yang
 * lupa memeriksanya akan bekerja di database pooled atas nama tenant yang datanya tidak ada di sana.
 *
 * Karena itu pembaca registry memilih salah satu dari dua bentuk, dan tidak ada bentuk ketiga:
 *
 * - **Menyaring** dengan {@see self::scopeHostedByProvider()} — daftar, armada, dan sapuan, tempat
 *   lingkungan server klien memang tidak pernah seharusnya muncul.
 * - **Menolak** dengan {@see self::clientServerRefusal()} — tindakan yang menyebut satu lingkungan
 *   dengan id-nya. Menyaring di sana berarti menjawab "tidak ada di registry" untuk baris yang jelas
 *   ada, dan operator yang membaca jawaban itu mencari salah ketik yang tidak pernah terjadi.
 *
 * Di server klien sendiri baris produksinya `provider`: dari sudut Core yang berjalan di sana, ia
 * memang yang menjalankannya.
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
 * @property ?Carbon $purged_at
 */
class Environment extends Model
{
    use HasUlids;
    use OwnedByControlPlane;

    /** Berjalan di server kami — keadaan setiap lingkungan sampai pemasangan satu perintah ada. */
    public const HOSTING_PROVIDER = 'provider';

    /** Berjalan di server milik klien; hanya produksi, dan tanpa database di server kami. */
    public const HOSTING_CLIENT_SERVER = 'client_server';

    protected $fillable = [
        'tenant_id',
        'kind',
        'name',
        'slug',
        'database_name',
        'hosting',
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

    /**
     * Hanya lingkungan yang berjalan di server kami.
     *
     * Satu scope, bukan `where('hosting', ...)` yang disalin ke tiap pembaca. Nilai yang ditulis ulang
     * di sepuluh tempat adalah sepuluh kesempatan menulis `!= 'client_server'` di satu tempat dan
     * `= 'provider'` di tempat lain — keduanya sama hari ini, dan berbeda pada hari nilai ketiga lahir.
     * Yang dipilih bentuk yang menyebut apa yang **boleh**: nilai baru yang belum dikenal pembaca mana
     * pun jatuh ke sisi yang disaring, bukan ke sisi yang dikerjakan.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeHostedByProvider(Builder $query): Builder
    {
        return $query->where('hosting', self::HOSTING_PROVIDER);
    }

    public function hostedOnClientServer(): bool
    {
        return $this->hosting === self::HOSTING_CLIENT_SERVER;
    }

    /**
     * Kalimat penolakan untuk tindakan atas lingkungan yang berjalan di server klien.
     *
     * Satu kalimat dipakai seluruh penolak, supaya operator yang menemuinya dari terminal, dari layar
     * konsol, maupun dari log membaca sebab yang sama dengan kata yang sama — dan langsung tahu bahwa
     * yang salah bukan id-nya melainkan tempatnya.
     *
     * @param  string  $tindakan  kata benda tindakannya, mis. "Penyiapan database"
     */
    public function clientServerRefusal(string $tindakan): string
    {
        return sprintf(
            'Lingkungan "%s" berjalan di server klien, bukan di server ini. %s ditolak: isinya tidak '
            .'ada di sini, dan mengerjakannya dari sini berarti mengerjakan database bersama milik '
            .'tenant lain. Lingkungan ini dikelola dari admin.erp lewat agen di server klien.',
            $this->slug,
            $tindakan,
        );
    }
}
