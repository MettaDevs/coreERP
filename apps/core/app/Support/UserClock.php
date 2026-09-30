<?php

namespace App\Support;

use App\Models\LegalEntity;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Http\Request;

/**
 * Zona waktu dan "hari ini" pengguna (area 7 TODO analisa gap BC fase 1, K-10).
 *
 * Jam selalu dari server, dalam UTC; perangkat pengguna tidak pernah ditanya. Zonanya pilihan pengguna di
 * My Profile, seperti *Time Zone* di My Settings Business Central. Pengguna yang belum memilih mengikuti
 * zona entitas legal yang sedang aktif, seperti `DateTimeUtil::getCompanyTimeZone()` di F&O. Tanpa keduanya
 * (misalnya operator tanpa tenant) yang dipakai zona aplikasi.
 *
 * Layar dan cetakan memformat waktu dengan zona yang sama. Cetakan menuliskan zonanya lewat
 * {@see zoneLabel()}, misalnya "28/09/2026 14:05 WITA".
 */
final class UserClock
{
    /**
     * Singkatan resmi zona Indonesia. Zona lain ditulis sebagai selisihnya dari UTC, karena singkatan
     * seperti IST atau CST dipakai lebih dari satu zona dan tidak dapat dibaca tanpa ragu.
     */
    private const INDONESIAN_ZONES = [
        'Asia/Jakarta' => 'WIB',
        'Asia/Pontianak' => 'WIB',
        'Asia/Makassar' => 'WITA',
        'Asia/Jayapura' => 'WIT',
    ];

    public function __construct(private readonly CurrentWorkspace $workspace) {}

    public function timezone(Request $request): string
    {
        $user = $request->user();
        if ($user instanceof User && self::known($user->timezone)) {
            return (string) $user->timezone;
        }

        return $this->legalEntityTimezone($request) ?? (string) config('app.timezone');
    }

    /**
     * Zona pengguna tanpa permintaan, untuk pekerjaan latar seperti ekspor laporan di worker antrean.
     * Aturannya sama dengan {@see timezone()}; entitas legalnya diambil dari catatan pekerjaan itu.
     */
    public function timezoneFor(?User $user, ?string $legalEntityId): string
    {
        if ($user !== null && self::known($user->timezone)) {
            return (string) $user->timezone;
        }
        $zone = $legalEntityId === null ? null : LegalEntity::query()->whereKey($legalEntityId)->value('timezone');

        return is_string($zone) && self::known($zone) ? $zone : (string) config('app.timezone');
    }

    /** Zona entitas legal aktif, bawaan bagi pengguna yang belum memilih zonanya sendiri. */
    public function legalEntityTimezone(Request $request): ?string
    {
        $membership = $this->workspace->membership($request);
        $zone = $membership === null ? null : $this->workspace->legalEntity($request, $membership)?->legalEntity?->timezone;

        return self::known($zone) ? $zone : null;
    }

    /** Hari ini (`Y-m-d`) menurut zona pengguna. */
    public function today(Request $request): string
    {
        return CarbonImmutable::now($this->timezone($request))->toDateString();
    }

    public static function known(?string $zone): bool
    {
        return $zone !== null && in_array($zone, DateTimeZone::listIdentifiers(), true);
    }

    /**
     * Nama zona yang ditulis cetakan di belakang jamnya: WIB, WITA, atau WIT untuk zona Indonesia, "UTC"
     * untuk selisih nol, dan selisih dari UTC untuk zona lain, misalnya "UTC+09:00". Selisihnya dihitung
     * pada saat itu sendiri, jadi zona yang mengenal waktu musim panas tetap tertulis benar.
     */
    public static function zoneLabel(CarbonInterface $moment): string
    {
        $name = $moment->getTimezone()->getName();
        if (isset(self::INDONESIAN_ZONES[$name])) {
            return self::INDONESIAN_ZONES[$name];
        }

        return $moment->getOffset() === 0 ? 'UTC' : 'UTC'.$moment->format('P');
    }

    /**
     * Pilihan zona untuk layar: nama IANA beserta selisihnya dari UTC saat ini, misalnya
     * "Asia/Makassar · UTC+08:00", urut dari selisih terkecil.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        $now = CarbonImmutable::now('UTC');
        $zones = array_map(static function (string $zone) use ($now): array {
            $offset = (new DateTimeZone($zone))->getOffset($now);

            return ['offset' => $offset, 'value' => $zone, 'label' => $zone.' · UTC'.$now->setTimezone($zone)->format('P')];
        }, DateTimeZone::listIdentifiers());
        usort($zones, static fn (array $a, array $b): int => [$a['offset'], $a['value']] <=> [$b['offset'], $b['value']]);

        return array_map(static fn (array $zone): array => ['value' => $zone['value'], 'label' => $zone['label']], $zones);
    }
}
