<?php

declare(strict_types=1);

namespace Tests\Feature\ControlPlane;

use Illuminate\Http\Middleware\TrustProxies;
use Tests\TestCase;

/**
 * Di belakang proxy, alamat yang berlaku datang dari `X-Forwarded-*` — bukan dari koneksi.
 *
 * ## Cacat yang melahirkan berkas ini
 *
 * `bootstrap/app.php` memanggil `trustProxies()` di dalam closure `withMiddleware()`, dengan
 * nilainya dibaca `env('COREERP_TRUSTED_PROXIES')`. Terbaca benar, dan **tidak pernah bekerja satu
 * kali pun**: closure itu dijalankan `afterResolving(HttpKernel::class)`, sementara kernel
 * di-resolve sebelum `LoadEnvironmentVariables` berjalan. `env()` di sana selalu null, jadi
 * cabangnya tidak pernah diambil.
 *
 * Kegagalannya tidak berbunyi di mana pun. Ia hanya muncul begitu ada proxy di depan: Laravel
 * membaca alamat dari koneksi ke proxy — yang memang `http` — lalu menerbitkan setiap pengalihan
 * sebagai `http://`. Di produksi itu berbentuk pengalihan berulang tanpa henti; di mesin
 * pengembang, peramban dilempar ke porta 80 dan menerima galat milik server lain yang kebetulan
 * mendengar di sana.
 *
 * Ditemukan dengan membuka konsol lewat Traefik, bukan oleh satu pun test yang ada.
 *
 * ## Kenapa env-nya disetel sebelum `parent::setUp()`
 *
 * Karena yang diuji justru **kapan** nilainya terbaca. `AppServiceProvider::boot()` berjalan di
 * dalam `parent::setUp()`, jadi config yang diubah sesudahnya tidak akan pernah sampai ke
 * `TrustProxies`. Menyetelnya sesudah itu menghasilkan test yang hijau tanpa membuktikan apa pun —
 * bentuk kegagalan yang sama dengan cacat yang sedang dijaganya.
 */
class TrustedProxyTest extends TestCase
{
    protected function setUp(): void
    {
        // Ketiganya, bukan dua. Repository env Laravel membaca `$_SERVER` lebih dulu, dan pemuat
        // `.env` menulis ke sana. Tanpa baris pertama, `COREERP_TRUSTED_PROXIES=` yang kosong di
        // `.env.example` — yang disalin CI — mengalahkan nilai test ini, dan test hanya hijau di
        // mesin yang `.env`-nya kebetulan sudah berisi `*`. Terukur: merah di CI, hijau di laptop.
        $_SERVER['COREERP_TRUSTED_PROXIES'] = '*';
        $_ENV['COREERP_TRUSTED_PROXIES'] = '*';
        putenv('COREERP_TRUSTED_PROXIES=*');

        parent::setUp();
    }

    protected function tearDown(): void
    {
        unset($_SERVER['COREERP_TRUSTED_PROXIES'], $_ENV['COREERP_TRUSTED_PROXIES']);
        putenv('COREERP_TRUSTED_PROXIES');

        parent::tearDown();
    }

    public function test_the_setting_reaches_the_middleware_and_not_only_the_configuration(): void
    {
        // Config-nya terisi. Itu bagian yang selalu benar, bahkan ketika cacatnya masih ada —
        // karena itu ia tidak pernah cukup sebagai bukti, dan assertion berikutnya yang menagih.
        $this->assertSame('*', config('coreerp.trusted_proxies'));

        $response = $this->withHeaders([
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'pelanggan.erp.contoh.co.id',
        ])->get('/settings/access');

        $location = (string) $response->headers->get('Location');

        $this->assertStringStartsWith(
            'https://',
            $location,
            'Pengalihan masih diterbitkan sebagai http meski permintaannya datang dengan '
            .'X-Forwarded-Proto: https. Itu berarti trusted proxy tidak sampai ke middleware — '
            .'periksa AppServiceProvider::boot(), bukan bootstrap/app.php.'
        );
    }

    /**
     * Pasangan merahnya, dan ia yang membuat test di atas berarti.
     *
     * Tanpa ini, "pengalihannya https" dapat dipenuhi aplikasi yang mempercayai header dari siapa
     * pun — dan itu justru lubang, bukan perbaikan: penyerang dapat menyuntikkan `X-Forwarded-Host`
     * untuk membuat tautan reset sandi menunjuk domainnya sendiri.
     */
    public function test_without_the_setting_the_forwarded_headers_are_ignored(): void
    {
        config(['coreerp.trusted_proxies' => null]);

        // Disetel ulang ke keadaan "tidak ada yang dipercaya", karena `TrustProxies::at()` statis
        // dan nilainya bertahan lintas test di proses yang sama.
        TrustProxies::at([]);

        $response = $this->withHeaders([
            'X-Forwarded-Proto' => 'https',
        ])->get('/settings/access');

        $this->assertStringStartsWith('http://', (string) $response->headers->get('Location'));
    }
}
