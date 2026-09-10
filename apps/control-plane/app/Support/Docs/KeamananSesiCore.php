<?php

declare(strict_types=1);

namespace App\Support\Docs;

use Dedoc\Scramble\SecurityDocumentation\MiddlewareAuthSecurityStrategy;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Support\Str;

/**
 * Strategi keamanan dokumen API: cookie sesi, bukan bearer token.
 *
 * **Kenapa kelas ini ada, dan bukan sekadar beberapa baris di berkas setelan.** Sampai
 * 10 September 2026 skemanya disusun langsung di `config/scramble.php`, dan itu membuat berkas
 * setelan memuat sebuah **objek hidup**. Setelan yang di-cache ditulis Laravel dengan
 * `var_export`, yang hanya bisa menuliskan ulang objek bila kelasnya punya `__set_state()` —
 * dan `SecurityScheme` tidak punya. Akibatnya `php artisan config:cache` berhenti dengan
 * "Call to undefined method ...ApiKeySecurityScheme::__set_state()".
 *
 * Kegagalan itu tidak pernah terlihat karena tidak ada satu pun langkah penyebaran yang
 * memanggil perintah itu — jadi yang sebenarnya terjadi bukan "cache-nya rusak", melainkan
 * **setiap permintaan membayar bootstrap penuh** dan tidak ada yang menyadarinya. Diukur pada
 * F7-03, gate latensi lulus sampai 12 permintaan bersamaan dalam keadaan itu.
 *
 * Sekarang berkas setelan hanya memuat nama kelas ini — sebuah string, yang dapat
 * di-`var_export` — dan skemanya disusun saat kelas ini dibuat, yaitu saat runtime.
 *
 * **Satu akibat sampingan yang justru perbaikan.** Nama cookie dibaca dari
 * `config('session.cookie')` di dalam konstruktor, bukan saat berkas setelan dimuat. Dulu nilai
 * itu ikut terbekukan ke dalam setelan yang di-cache; sekarang ia dibaca dari setelan sesi apa
 * adanya, jadi mengubah nama cookie tidak lagi menuntut cache setelan dibangun ulang agar
 * dokumen API-nya ikut benar.
 */
final class KeamananSesiCore extends MiddlewareAuthSecurityStrategy
{
    public function __construct()
    {
        $namaCookie = config('session.cookie');

        parent::__construct(
            ['auth', 'auth:*'],
            SecurityScheme::apiKey(
                'cookie',
                is_string($namaCookie) && $namaCookie !== ''
                    ? $namaCookie
                    : Str::slug((string) config('app.name', 'laravel')).'-session',
            )
                ->as('sessionCookie')
                ->setDescription('Sign in through the web application to obtain the session cookie.'),
        );
    }
}
