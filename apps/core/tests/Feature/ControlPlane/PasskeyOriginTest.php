<?php

declare(strict_types=1);

namespace Tests\Feature\ControlPlane;

use App\Http\Middleware\ResolvePasskeyOrigin;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tests\TestCase;

/**
 * Identitas WebAuthn mengikuti alamat yang sedang dilayani.
 *
 * `passkeys.allowed_origins` adalah daftar string persis — pustakanya tidak mengenal pola. Selama
 * ia diturunkan dari `APP_URL`, ia memuat satu alamat, dan alamat itu bukan alamat tenant mana pun.
 * Akibatnya setiap upacara WebAuthn di alamat sungguhan ditolak, dengan pesan yang tidak menyebut
 * sebabnya di mana pun selain konsol peramban.
 *
 * Middleware-nya diuji langsung, bukan lewat permintaan HTTP. Yang diperiksa di sini adalah config
 * yang ia tinggalkan, dan permintaan penuh akan menyeret Fortify, sesi, serta rute — tiga hal yang
 * tidak satu pun menjawab pertanyaan ini, dan ketiganya dapat gagal karena sebab lain.
 */
class PasskeyOriginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'coreerp.base_domain' => 'erp.contoh.co.id',
            'passkeys.relying_party_id' => 'localhost',
            'passkeys.allowed_origins' => ['http://localhost:8000'],
        ]);
    }

    /**
     * Alamat produksi sebuah tenant.
     *
     * RP ID-nya domain dasar, bukan host penuh — dan itu yang membuat satu passkey berlaku di
     * seluruh tenant milik orang yang sama. WebAuthn mengizinkannya: RP ID boleh berupa sufiks
     * domain yang dapat didaftarkan dari origin-nya.
     */
    public function test_a_tenant_host_becomes_its_own_allowed_origin(): void
    {
        $this->pass('https://pt-sinar-abadi.erp.contoh.co.id/masuk');

        $this->assertSame('erp.contoh.co.id', config('passkeys.relying_party_id'));
        $this->assertSame(['https://pt-sinar-abadi.erp.contoh.co.id'], config('passkeys.allowed_origins'));
    }

    /** Alamat demo berada satu tingkat lebih dalam, dan sufiksnya tetap sah. */
    public function test_a_demo_host_one_level_deeper_is_allowed_too(): void
    {
        $this->pass('https://pt-sinar-abadi--peragaan.demo.erp.contoh.co.id/masuk');

        $this->assertSame('erp.contoh.co.id', config('passkeys.relying_party_id'));
        $this->assertSame(
            ['https://pt-sinar-abadi--peragaan.demo.erp.contoh.co.id'],
            config('passkeys.allowed_origins'),
        );
    }

    public function test_the_base_domain_itself_is_allowed(): void
    {
        $this->pass('https://erp.contoh.co.id/masuk');

        $this->assertSame(['https://erp.contoh.co.id'], config('passkeys.allowed_origins'));
    }

    /**
     * Pasangan merahnya, dan ia yang membuat seluruh test di atas berarti.
     *
     * Tanpa ini, "origin yang sedang dilayani menjadi origin yang sah" dapat dipenuhi middleware
     * yang menerima **apa pun** — dan yang tersisa hanyalah pemeriksaan yang selalu lulus. Yang
     * menjaganya syarat berada di bawah domain kita, bukan kehadiran origin-nya di permintaan.
     */
    public function test_a_host_outside_our_domain_never_becomes_an_allowed_origin(): void
    {
        $this->pass('https://erp.contoh.co.id.penyerang.test/masuk');

        $this->assertSame('localhost', config('passkeys.relying_party_id'));
        $this->assertSame(['http://localhost:8000'], config('passkeys.allowed_origins'));
    }

    /**
     * Sebuah nama yang hanya BERAKHIR dengan domain kita tanpa titik pemisah bukan milik kita.
     *
     * `bukanerp.contoh.co.id` berakhir dengan `erp.contoh.co.id` sebagai untaian huruf, tetapi ia
     * host yang sama sekali berbeda. Pemeriksaan yang memakai `str_ends_with` tanpa titik akan
     * menyerahkan identitas WebAuthn kita kepada siapa pun yang dapat mendaftarkan nama itu.
     */
    public function test_a_name_that_merely_ends_with_our_domain_is_not_ours(): void
    {
        config(['coreerp.base_domain' => 'erp.contoh.co.id']);

        $this->pass('https://bukanerp.contoh.co.id/masuk');

        $this->assertSame(['http://localhost:8000'], config('passkeys.allowed_origins'));
    }

    /**
     * Penempatan satu alamat — on-prem, pengembangan lokal, dan seluruh test yang sudah ada.
     *
     * Config bawaannya sudah benar di sana. Menyentuhnya berarti dua tempat yang menjawab
     * pertanyaan yang sama, dan yang satu akan menyimpang tanpa ada yang tahu.
     */
    public function test_without_a_base_domain_the_configuration_is_left_alone(): void
    {
        config(['coreerp.base_domain' => null]);

        $this->pass('https://apa-pun.contoh.co.id/masuk');

        $this->assertSame('localhost', config('passkeys.relying_party_id'));
        $this->assertSame(['http://localhost:8000'], config('passkeys.allowed_origins'));
    }

    private function pass(string $url): void
    {
        $middleware = new ResolvePasskeyOrigin;

        $middleware->handle(Request::create($url), fn (): Response => new Response);
    }
}
