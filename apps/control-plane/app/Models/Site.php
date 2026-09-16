<?php

declare(strict_types=1);

namespace ControlPlane\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Satu server milik klien yang dikelola dari konsol ini — tabel `sites` milik Core.
 *
 * Kelasnya `Site` dan alamatnya `/situs`, tetapi layarnya berbunyi "Server klien" sejak 15 September
 * 2026: kata yang sama dengan panel di halaman lingkungan, dan kata yang langsung menjawab isi daftarnya.
 * "Situs" terbaca seperti situs web. Aturan kerasnya — kunci dan waktu pendaftaran berpasangan, jendela
 * pembaruan berpasangan, profil yang dikenal — ditegakkan CHECK constraint di migration
 * `create_site_registry_tables`, bukan di kelas ini.
 *
 * Alamat aplikasi situs yang lahir dari lingkungan diturunkan dari lingkungannya — lihat `appUrl()` — dan
 * `address` hanya berlaku untuk situs lama tanpa lingkungan. `server_address` alamat mesinnya, dicatat
 * operator; `last_seen_ip` asal laporan agen terakhir; `dns_*` record DNS yang dibuat admin.erp untuk alamat
 * aplikasi itu. Alasannya di migration `add_server_address_to_sites` dan `add_dns_record_to_sites`.
 *
 * @property string $id
 * @property string $tenant_id
 * @property ?string $environment_id
 * @property string $name
 * @property string $profile
 * @property string $edition
 * @property ?string $address
 * @property ?string $server_address
 * @property ?string $last_seen_ip
 * @property ?string $dns_record_id
 * @property ?string $dns_name
 * @property ?string $dns_target
 * @property ?Carbon $dns_synced_at
 * @property ?string $update_window_start
 * @property ?string $update_window_end
 * @property string $timezone
 * @property ?string $public_key
 * @property ?Carbon $enrolled_at
 * @property ?Carbon $revoked_at
 * @property ?string $reported_edition
 * @property ?string $reported_release
 * @property ?string $reported_digest
 * @property ?Carbon $last_seen_at
 * @property ?array<string, mixed> $last_report
 * @property ?Carbon $license_issued_at
 * @property ?Carbon $license_valid_until
 * @property ?Carbon $license_suspended_at
 * @property ?int $created_by
 * @property ?Carbon $created_at
 */
class Site extends Model
{
    use HasUlids;

    public const PROFILES = ['managed_on_prem'];

    /**
     * Edisi setiap situs yang lahir dari panel "Server klien".
     *
     * Sejak 15 September 2026 satu image dipakai semua klien (`docs/todo/registry-harbor`): image membawa
     * Core dan seluruh modul, dan yang membedakan klien hanya lisensinya. Kolom `sites.edition` dan
     * `site_releases.edition` masih ada karena alur rilis dan agen hari ini mencocokkan berkas rilis per
     * edisi, jadi nilainya satu konstanta, bukan pilihan operator. PRD Harbor membuang kunci edisi itu
     * dari manifest dan rilis; konstanta ini ikut dibuang bersamanya.
     */
    public const SINGLE_IMAGE_EDITION = 'coreerp';

    protected $table = 'sites';

    protected $fillable = [
        'tenant_id',
        'environment_id',
        'name',
        'profile',
        'edition',
        'address',
        'server_address',
        'update_window_start',
        'update_window_end',
        'timezone',
        'public_key',
        'enrolled_at',
        'revoked_at',
        'reported_edition',
        'reported_release',
        'reported_digest',
        'last_seen_at',
        'last_report',
        'created_by',
        // Kolom lisensi sengaja tidak ada di sini. Yang boleh menulisnya hanya penerbit lisensi dan
        // tombol henti/lanjut perpanjangan, masing-masing dengan jejak auditnya; isian formulir yang
        // kebetulan membawa `license_suspended_at` tidak boleh ikut tersimpan lewat `create()`.
    ];

    protected function casts(): array
    {
        return [
            'enrolled_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_report' => 'array',
            'license_issued_at' => 'datetime',
            'license_valid_until' => 'date',
            'license_suspended_at' => 'datetime',
            'dns_synced_at' => 'datetime',
        ];
    }

    /**
     * Alamat yang dibuka pengguna klinik.
     *
     * Situs yang lahir dari lingkungan memakai alamat produksi lingkungannya, `<tenant>.<domain dasar>`, sama
     * dengan produksi di server kita — admin.erp yang membuat record DNS-nya ke server klien. Ia tidak dapat
     * diganti operator: domain milik klien belum didukung. Situs lama tanpa lingkungan tetap memakai alamat
     * yang dicatat untuknya.
     */
    public function appUrl(): ?string
    {
        if ($this->environment_id === null) {
            return $this->address;
        }

        return $this->environment?->url();
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Lingkungan produksi yang dijalankan situs ini. Kosong pada situs yang didaftarkan sebelum
     * 15 September 2026 — lihat migration `add_environment_to_sites`.
     *
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /** @return HasMany<SiteOperation, $this> */
    public function operations(): HasMany
    {
        return $this->hasMany(SiteOperation::class);
    }

    public function enrolled(): bool
    {
        return $this->public_key !== null;
    }

    public function revoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function licenseRenewalSuspended(): bool
    {
        return $this->license_suspended_at !== null;
    }

    /**
     * Apakah sekarang berada di jendela pembaruan situs ini.
     *
     * Jendela boleh melewati tengah malam — 22.00 sampai 04.00 adalah jendela yang paling lazim untuk
     * fasilitas kesehatan — jadi "mulai lebih besar dari selesai" berarti dua potongan, bukan jendela
     * kosong.
     */
    public function withinUpdateWindow(?CarbonImmutable $now = null): bool
    {
        if ($this->update_window_start === null || $this->update_window_end === null) {
            return true;
        }

        $local = ($now ?? CarbonImmutable::now())->setTimezone($this->timezone)->format('H:i:s');
        $start = substr($this->update_window_start, 0, 8);
        $end = substr($this->update_window_end, 0, 8);

        return $start <= $end
            ? $local >= $start && $local <= $end
            : $local >= $start || $local <= $end;
    }

    /** Situs terdaftar yang laporannya berhenti datang. */
    public function stale(): bool
    {
        if (! $this->enrolled()) {
            return false;
        }

        return $this->last_seen_at === null
            || $this->last_seen_at->lt(now()->subSeconds((int) config('sites.stale_after_seconds')));
    }

    /** @return array{start: string, end: string, timezone: string}|null */
    public function updateWindow(): ?array
    {
        if ($this->update_window_start === null || $this->update_window_end === null) {
            return null;
        }

        return [
            'start' => substr($this->update_window_start, 0, 5),
            'end' => substr($this->update_window_end, 0, 5),
            'timezone' => $this->timezone,
        ];
    }
}
