<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\ControlPlane\EnvironmentAddress;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menyesuaikan identitas WebAuthn dengan alamat yang sedang dilayani.
 *
 * ## Kenapa config statis tidak dapat menjawabnya
 *
 * `passkeys.allowed_origins` adalah daftar **string persis** — pustakanya tidak mengenal pola.
 * Bawaannya `[config('app.url')]`, satu alamat, dan itu benar selama seluruh aplikasi dilayani dari
 * satu host. Begitu tiap tenant punya hostnya sendiri, daftar itu tidak akan pernah memuat host
 * yang sedang dipakai orangnya, dan setiap upacara WebAuthn ditolak.
 *
 * Mendaftarkan seluruh host lingkungan ke dalam config berarti satu query ke registry pada setiap
 * permintaan demi daftar yang bertambah panjang selamanya — dan lingkungan yang lahir semenit lalu
 * tetap tidak akan ada di dalamnya sampai config-nya dibangun ulang.
 *
 * Yang dilakukan di sini kebalikannya: alamat yang sedang dilayani **itu sendiri** yang dijadikan
 * origin yang sah, dan yang menjaganya adalah syarat bahwa ia berada di bawah domain kita. Host di
 * luar domain kita tidak menyentuh config sama sekali — daftar ketatnya tetap berlaku di sana.
 *
 * ## Kenapa RP ID-nya domain dasar, bukan host penuh
 *
 * WebAuthn mengizinkan RP ID berupa host itu sendiri **atau** sufiks domain yang dapat didaftarkan
 * darinya. `erp.contoh.co.id` sah untuk `pelanggan.erp.contoh.co.id` maupun untuk
 * `pelanggan.demo.erp.contoh.co.id`.
 *
 * Memilih sufiksnya berarti **satu passkey berlaku di seluruh tenant milik orang itu** — dan itu
 * memang yang diputuskan: satu orang boleh berada di banyak tenant (konsultan, akuntan, operator
 * vendor), dan memaksanya mendaftarkan kunci baru di tiap tenant adalah gesekan yang tidak
 * membeli keamanan apa pun.
 *
 * Ia **bukan** berbagi izin. Kredensial hanya menjawab "orang ini siapa"; yang menjawab "ia boleh
 * apa di tenant ini" tetap `tenant_memberships` dan `role_assignments`. Passkey yang sah di dua
 * tenant tidak membuat pemiliknya punya satu pun hak di tenant yang tidak memuatnya.
 *
 * ## Kenapa middleware, bukan penyedia layanan
 *
 * Jawabannya bergantung pada permintaan, dan penyedia layanan berjalan sekali sebelum permintaan
 * mana pun ada. Bentuknya sama dengan {@see EnvironmentConnection} yang memaku koneksi per
 * permintaan: config yang berubah menurut alamat hanya dapat diubah setelah alamatnya diketahui.
 */
class ResolvePasskeyOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        $domain = EnvironmentAddress::baseDomain();

        if ($domain === '') {
            // Penempatan satu alamat: on-prem, pengembangan lokal, dan seluruh test yang ada.
            // Config bawaannya sudah benar di sana, dan menyentuhnya hanya membuat dua tempat yang
            // menjawab pertanyaan yang sama.
            return $next($request);
        }

        $host = mb_strtolower($request->getHost());

        if ($host !== $domain && ! str_ends_with($host, '.'.$domain)) {
            /*
             * Di luar domain kita. Daftar ketatnya sengaja dibiarkan berlaku.
             *
             * Ini yang membuat mekanisme di kelas ini bukan "izinkan origin apa pun": syaratnya
             * bukan bahwa origin-nya datang bersama permintaan, melainkan bahwa ia berada di bawah
             * domain yang memang kita miliki. Host yang dikarang orang lain tidak pernah lolos.
             */
            return $next($request);
        }

        config([
            'passkeys.relying_party_id' => $domain,
            'passkeys.allowed_origins' => [$request->getSchemeAndHttpHost()],
        ]);

        return $next($request);
    }
}
