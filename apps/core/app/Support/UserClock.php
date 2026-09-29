<?php

namespace App\Support;

use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Http\Request;

/**
 * Zona waktu dan "hari ini" pengguna (area 7 TODO analisa gap BC fase 1, K-10).
 *
 * Jam selalu dari server, dalam UTC; perangkat pengguna tidak pernah ditanya. Zonanya pilihan pengguna di
 * My Profile, seperti *Time Zone* di My Settings Business Central. Pengguna yang belum memilih mengikuti
 * zona entitas legal yang sedang aktif, seperti `DateTimeUtil::getCompanyTimeZone()` di F&O. Tanpa keduanya
 * (misalnya operator tanpa tenant) yang dipakai zona aplikasi.
 */
final class UserClock
{
    public function __construct(private readonly CurrentWorkspace $workspace) {}

    public function timezone(Request $request): string
    {
        $user = $request->user();
        if ($user instanceof User && self::known($user->timezone)) {
            return (string) $user->timezone;
        }

        return $this->legalEntityTimezone($request) ?? (string) config('app.timezone');
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
