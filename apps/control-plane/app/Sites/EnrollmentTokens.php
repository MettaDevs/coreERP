<?php

declare(strict_types=1);

namespace ControlPlane\Sites;

use ControlPlane\Models\Site;
use ControlPlane\Models\SiteEnrollmentToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Melahirkan dan menukar token pendaftaran situs.
 *
 * Token mentah hanya ada di memori pada saat dibuat — lalu ditampilkan sekali kepada operator atau
 * ditulis ke paket pendaftaran. Yang tersimpan hash-nya.
 */
final class EnrollmentTokens
{
    /** @return array{token: string, expires_at: Carbon} */
    public function issue(Site $site, string $channel, ?int $createdBy): array
    {
        if (! in_array($channel, ['online', 'offline'], true)) {
            throw new SiteRejected('unknown_channel', 'Kanal pendaftaran tidak dikenal.');
        }

        if ($site->revoked()) {
            throw new SiteRejected('site_revoked', 'Situs ini sudah dicabut dan tidak dapat didaftarkan lagi.');
        }

        $token = Str::random(48);
        $expiresAt = now()->addMinutes((int) config('sites.enrollment_token_minutes.'.$channel));

        SiteEnrollmentToken::query()->create([
            'site_id' => $site->id,
            'token_hash' => hash('sha256', $token),
            'channel' => $channel,
            'expires_at' => $expiresAt,
            'created_by' => $createdBy,
        ]);

        return ['token' => $token, 'expires_at' => $expiresAt];
    }

    /**
     * Menukar token dengan kunci publik situs.
     *
     * Sekali pakai diputuskan UPDATE bersyarat `used_at IS NULL`, bukan pembacaan sebelumnya: dua
     * pendaftaran dengan token yang sama pada detik yang sama sama-sama lolos pembacaan, dan hanya
     * satu yang mengubah baris.
     *
     * Semua penolakan berbunyi sama. Token yang tidak ada, sudah dipakai, kedaluwarsa, salah kanal,
     * atau milik situs yang dicabut tidak dibedakan di jawaban — pembedaannya hanya membantu orang
     * yang sedang menebak token.
     */
    public function redeem(string $token, string $channel, string $publicKey): Site
    {
        if (! SitePublicKey::acceptable($publicKey)) {
            throw new SiteRejected('public_key_invalid', 'Kunci publik situs harus PEM RSA paling sedikit 2048 bit.');
        }

        return DB::transaction(function () use ($token, $channel, $publicKey): Site {
            $row = SiteEnrollmentToken::query()
                ->where('token_hash', hash('sha256', $token))
                ->where('channel', $channel)
                ->lockForUpdate()
                ->first();

            $site = $row instanceof SiteEnrollmentToken ? Site::query()->lockForUpdate()->find($row->site_id) : null;

            if (! $row instanceof SiteEnrollmentToken
                || ! $site instanceof Site
                || $row->used_at !== null
                || $row->expires_at->isPast()
                || $site->revoked()) {
                throw new SiteRejected('enrollment_rejected', 'Token pendaftaran tidak dapat dipakai.');
            }

            $claimed = SiteEnrollmentToken::query()
                ->whereKey($row->id)
                ->whereNull('used_at')
                ->update(['used_at' => now(), 'updated_at' => now()]);

            if ($claimed !== 1) {
                throw new SiteRejected('enrollment_rejected', 'Token pendaftaran tidak dapat dipakai.');
            }

            // Token lain yang belum dipakai untuk situs ini ikut gugur. Server yang sudah terdaftar
            // tidak butuh jalan masuk kedua yang menunggu dipakai orang lain.
            SiteEnrollmentToken::query()
                ->where('site_id', $site->id)
                ->whereNull('used_at')
                ->update(['used_at' => now(), 'updated_at' => now()]);

            $site->forceFill(['public_key' => $publicKey, 'enrolled_at' => now()])->save();

            return $site;
        });
    }
}
