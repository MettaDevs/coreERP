<?php

declare(strict_types=1);

namespace ControlPlane\Sites;

use ControlPlane\Models\Site;
use Illuminate\Support\Facades\DB;

/**
 * Masa lisensi: berapa lama lisensi berlaku, kapan diperpanjang, dan apakah situs ini memakai lisensi
 * tanpa tanggal berakhir.
 *
 * Satu tempat, karena angka yang sama dibaca tiga pihak yang tidak saling melihat: penerbit
 * ({@see LicenseIssuer}), penjadwal perpanjangan ({@see LicenseRenewal}), dan layar yang menjelaskan
 * keduanya kepada operator. Dua salinan aturan berarti layar yang menjanjikan "diperpanjang 10 hari
 * sebelum habis" sementara penjadwalnya memakai angka lain.
 *
 * ## Tiga lapis, dan yang lebih khusus menang
 *
 * 1. Setelan per situs (`sites.license_valid_days`, `sites.license_renew_before_days`) bila diisi.
 * 2. Bawaan konsol di `console_settings`, diubah operator di halaman Pengaturan tanpa men-deploy ulang.
 * 3. Nilai di `config/sites.php`, yang dipakai konsol yang belum pernah menyetel apa pun.
 *
 * ## Lisensi permanen
 *
 * `sites.license_perpetual` membuat penerbit menulis `valid_until: null`. Lisensi itu tidak pernah habis
 * dan tidak pernah diperpanjang, jadi masa dan jendela perpanjangan tidak berlaku untuknya — tetapi
 * nilainya tetap dibaca di sini, karena operator dapat mengembalikan situs itu menjadi bertanggal dan
 * penerbitan berikutnya harus tahu angkanya.
 *
 * Yang membatasi app tetap daftar `apps` di lisensi. Permanen berarti tidak pernah terkunci karena waktu,
 * bukan terbuka untuk seluruh modul.
 */
final class LicenseTerms
{
    public const VALID_DAYS = 'license.valid_days';

    public const RENEW_BEFORE_DAYS = 'license.renew_before_days';

    /** Batasnya sama dengan yang dijaga constraint `sites_masa_lisensi_masuk_akal`. */
    public const MAX_VALID_DAYS = 3650;

    public const MAX_RENEW_BEFORE_DAYS = 365;

    /**
     * Bawaan konsol ini.
     *
     * Membaca `console_settings` setiap kali dipanggil: nilainya berubah begitu operator menyimpannya, dan
     * dua panggilan dalam satu permintaan memang boleh menjawab berbeda.
     *
     * @return array{validDays: int, renewBeforeDays: int}
     *
     * @phpstan-impure
     */
    public function defaults(): array
    {
        $rows = DB::table('console_settings')
            ->whereIn('key', [self::VALID_DAYS, self::RENEW_BEFORE_DAYS])
            ->pluck('value', 'key');

        $valid = $this->positiveInt($rows[self::VALID_DAYS] ?? null) ?? (int) config('sites.license_valid_days');
        $renew = $this->positiveInt($rows[self::RENEW_BEFORE_DAYS] ?? null) ?? (int) config('sites.license_renew_before_days');

        return ['validDays' => $valid, 'renewBeforeDays' => $renew];
    }

    /**
     * Masa yang berlaku untuk satu situs.
     *
     * @return array{perpetual: bool, validDays: int, renewBeforeDays: int, overridden: bool}
     *
     * @phpstan-impure
     */
    public function forSite(Site $site): array
    {
        $defaults = $this->defaults();
        $valid = $site->license_valid_days;
        $renew = $site->license_renew_before_days;

        return [
            'perpetual' => (bool) $site->license_perpetual,
            'validDays' => $valid ?? $defaults['validDays'],
            'renewBeforeDays' => $renew ?? $defaults['renewBeforeDays'],
            'overridden' => $valid !== null || $renew !== null,
        ];
    }

    public function storeDefaults(int $validDays, int $renewBeforeDays, ?int $userId): void
    {
        DB::transaction(function () use ($validDays, $renewBeforeDays, $userId): void {
            foreach ([self::VALID_DAYS => $validDays, self::RENEW_BEFORE_DAYS => $renewBeforeDays] as $key => $value) {
                DB::table('console_settings')->updateOrInsert(
                    ['key' => $key],
                    ['value' => (string) $value, 'updated_at' => now(), 'updated_by' => $userId],
                );
            }
        });
    }

    /**
     * Nilai tersimpan yang bukan angka wajar diperlakukan seperti belum disetel.
     *
     * Tabelnya disunting tangan lewat runbook, dan `license.valid_days = 0` di sana berarti setiap lisensi
     * yang lahir sudah habis — seluruh klien terkunci pada perpanjangan berikutnya. Jatuh ke bawaan lebih
     * aman daripada mematuhi angka yang tidak mungkin dimaksud siapa pun.
     */
    private function positiveInt(mixed $value): ?int
    {
        if (! is_string($value) || preg_match('/^\d{1,4}$/', $value) !== 1) {
            return null;
        }

        $number = (int) $value;

        return $number >= 1 && $number <= self::MAX_VALID_DAYS ? $number : null;
    }
}
