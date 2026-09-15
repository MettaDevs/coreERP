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
 * Layarnya berbunyi "Situs". Aturan kerasnya — kunci dan waktu pendaftaran berpasangan, jendela
 * pembaruan berpasangan, profil yang dikenal — ditegakkan CHECK constraint di migration
 * `create_site_registry_tables`, bukan di kelas ini.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $profile
 * @property string $edition
 * @property ?string $address
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
 * @property ?int $created_by
 * @property ?Carbon $created_at
 */
class Site extends Model
{
    use HasUlids;

    public const PROFILES = ['managed_on_prem'];

    protected $table = 'sites';

    protected $fillable = [
        'tenant_id',
        'name',
        'profile',
        'edition',
        'address',
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
    ];

    protected function casts(): array
    {
        return [
            'enrolled_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_report' => 'array',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
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
