<?php

declare(strict_types=1);

namespace ControlPlane\Dns;

use ControlPlane\Registry\RegistrySettings;
use ControlPlane\Sites\SiteDns;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Token API Cloudflare yang dipakai konsol ini untuk membuat record DNS alamat server klien.
 *
 * Disimpan di `console_settings` dan terenkripsi `Crypt`, sama seperti robot sistem Harbor
 * ({@see RegistrySettings}): token diputar tanpa men-deploy ulang konsol, dan isi
 * tabel yang bocor tanpa `APP_KEY` tidak membuka zona DNS. Tokennya tidak pernah dikembalikan ke layar
 * mana pun.
 *
 * Izin yang dibutuhkan hanya **Zone → DNS → Edit** untuk zona domain dasar. Konsol tidak pernah menyentuh
 * record selain `<tenant>.<domain dasar>` yang ia buat sendiri — lihat {@see SiteDns}.
 */
final class CloudflareSettings
{
    public const TOKEN = 'dns.cloudflare_token';

    public function token(): ?string
    {
        $value = DB::table('console_settings')->where('key', self::TOKEN)->value('value');

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            $token = Crypt::decryptString($value);
        } catch (DecryptException) {
            // APP_KEY berganti sesudah token disimpan. Diperlakukan sama dengan belum disetel.
            return null;
        }

        return $token !== '' ? $token : null;
    }

    public function configured(): bool
    {
        return $this->token() !== null;
    }

    public function storeToken(string $token, ?int $userId): void
    {
        DB::table('console_settings')->updateOrInsert(
            ['key' => self::TOKEN],
            ['value' => Crypt::encryptString($token), 'updated_at' => now(), 'updated_by' => $userId],
        );
    }

    /** Domain dasar alamat lingkungan — `erp.grenery.xyz` — tempat nama record server klien dibentuk. */
    public function baseDomain(): string
    {
        $domain = config('core.base_domain');

        return is_string($domain) ? mb_strtolower(trim($domain, ". \t\n\r\0\x0B")) : '';
    }
}
