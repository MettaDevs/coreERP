<?php

declare(strict_types=1);

namespace Tests\Feature\ControlPlane;

use App\Support\Sso\SsoApiUnavailable;
use App\Support\Sso\SsoDirectory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pencarian pengguna di penyedia SSO — satu-satunya panggilan yang menentukan boleh tidaknya sebuah
 * undangan lahir, dan subjek mana yang diikatnya.
 *
 * Setiap amplop jawaban penyedia diuji terpisah, karena ketiga arti yang berbeda — "tidak terdaftar",
 * "tidak dapat dijawab sekarang", dan "ini orangnya" — masing-masing berujung pada kalimat berbeda
 * di layar operator. Menggabungkannya berarti operator membaca "belum terdaftar" pada hari penyedia
 * sedang mati, lalu menyuruh orang mendaftar ulang padahal akunnya ada.
 */
class SsoDirectoryTest extends TestCase
{
    private const API = 'https://sso.uji/api/v1';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('coreerp.sso.issuer', 'https://sso.uji');
        config()->set('coreerp.sso.client_id', 'coreerp-uji');
        config()->set('coreerp.sso.client_secret', 'rahasia-uji');
        config()->set('coreerp.sso.api_url', null);
    }

    public function test_a_registered_email_comes_back_as_a_subject(): void
    {
        $this->fakeLookup([
            'success' => true,
            'user' => [
                'id' => 42,
                'name' => 'Dewi Pemilik',
                'email' => 'Dewi@Klinik.test',
                'is_active' => true,
            ],
        ]);

        $user = app(SsoDirectory::class)->find('dewi@klinik.test');

        $this->assertNotNull($user);
        // Penyedia mengirim angka; yang disimpan dan dibandingkan dengan klaim `sub` adalah teks.
        $this->assertSame('42', $user->subject);
        $this->assertSame('Dewi Pemilik', $user->name);
        $this->assertSame('Dewi@Klinik.test', $user->email);
        $this->assertTrue($user->isActive);

        Http::assertSent(function (HttpRequest $request): bool {
            return $request->url() === self::API.'/users/lookup?email=dewi%40klinik.test'
                && $request->hasHeader('X-Client-ID', 'coreerp-uji')
                && $request->hasHeader('X-Client-Secret', 'rahasia-uji');
        });
    }

    /** Alamat API diturunkan dari issuer bila tidak disebut, dan disebut bila ada. */
    public function test_the_api_address_can_be_set_apart_from_the_issuer(): void
    {
        config()->set('coreerp.sso.api_url', 'https://api.sso.uji/v2/');
        Http::fake(['https://api.sso.uji/v2/users/lookup*' => Http::response(['success' => true, 'user' => ['id' => 7, 'name' => 'A', 'email' => 'a@b.test', 'is_active' => true]])]);

        $this->assertSame('7', app(SsoDirectory::class)->find('a@b.test')?->subject);
    }

    public function test_an_unknown_email_is_an_answer_not_a_failure(): void
    {
        $this->fakeLookup(['success' => false, 'message' => 'Pengguna tidak ditemukan di SSO Hub.'], 404);

        $this->assertNull(app(SsoDirectory::class)->find('bukan-siapa-siapa@klinik.test'));
    }

    public function test_an_inactive_account_is_returned_and_marked(): void
    {
        $this->fakeLookup([
            'success' => true,
            'user' => ['id' => 9, 'name' => 'Mantan Staf', 'email' => 'mantan@klinik.test', 'is_active' => false],
        ]);

        $user = app(SsoDirectory::class)->find('mantan@klinik.test');

        // Dikembalikan, bukan disembunyikan: pemanggilnya yang tahu kalimat mana yang harus dibaca
        // operator, dan "akunnya nonaktif" jauh berbeda dari "emailnya belum terdaftar".
        $this->assertNotNull($user);
        $this->assertFalse($user->isActive);
    }

    /** Bentuk selain `true` dibaca nonaktif: keliru ke arah itu hanya menolak satu undangan. */
    public function test_a_missing_active_flag_is_read_as_inactive(): void
    {
        $this->fakeLookup(['success' => true, 'user' => ['id' => 9, 'name' => 'Tanpa Bendera', 'email' => 'x@klinik.test']]);

        $this->assertFalse(app(SsoDirectory::class)->find('x@klinik.test')?->isActive);
    }

    public function test_wrong_credentials_name_the_settings_and_never_quote_the_secret(): void
    {
        $this->fakeLookup(['message' => 'Autentikasi gagal', 'rahasia' => 'rahasia-uji'], 401);

        try {
            app(SsoDirectory::class)->find('dewi@klinik.test');
            $this->fail('Kredensial yang ditolak seharusnya melempar SsoApiUnavailable.');
        } catch (SsoApiUnavailable $e) {
            $this->assertStringContainsString('COREERP_SSO_CLIENT_ID', $e->getMessage());
            $this->assertStringNotContainsString('rahasia-uji', $e->getMessage());
        }
    }

    public function test_being_rate_limited_says_when_to_come_back(): void
    {
        $this->fakeLookup(['message' => 'Too Many Attempts.'], 429);

        $this->expectException(SsoApiUnavailable::class);
        $this->expectExceptionMessage('satu menit lagi');

        app(SsoDirectory::class)->find('dewi@klinik.test');
    }

    public function test_a_provider_error_is_not_read_as_not_registered(): void
    {
        $this->fakeLookup(['message' => 'Server Error'], 500);

        $this->expectException(SsoApiUnavailable::class);

        app(SsoDirectory::class)->find('dewi@klinik.test');
    }

    /** 422 datang dari pemeriksa email penyedia sendiri; kalimatnya menyebut apa yang salah. */
    public function test_a_refused_email_carries_the_providers_own_sentence(): void
    {
        $this->fakeLookup(['message' => 'The email field must be a valid email address.'], 422);

        $this->expectException(SsoApiUnavailable::class);
        $this->expectExceptionMessage('valid email address');

        app(SsoDirectory::class)->find('bukan-email');
    }

    public function test_an_answer_without_a_usable_id_is_refused(): void
    {
        $this->fakeLookup(['success' => true, 'user' => ['name' => 'Tanpa Id', 'email' => 'x@klinik.test']]);

        $this->expectException(SsoApiUnavailable::class);

        app(SsoDirectory::class)->find('x@klinik.test');
    }

    public function test_a_body_that_is_not_the_agreed_shape_is_refused(): void
    {
        $this->fakeLookup(['success' => true, 'data' => ['id' => 42]]);

        $this->expectException(SsoApiUnavailable::class);

        app(SsoDirectory::class)->find('x@klinik.test');
    }

    public function test_a_provider_that_cannot_be_reached_is_refused(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('koneksi habis waktu'));

        $this->expectException(SsoApiUnavailable::class);
        $this->expectExceptionMessage('tidak dapat dihubungi');

        app(SsoDirectory::class)->find('dewi@klinik.test');
    }

    public function test_half_configured_is_not_configured(): void
    {
        $this->assertTrue(app(SsoDirectory::class)->isConfigured());

        config()->set('coreerp.sso.client_secret', '');

        $this->assertFalse(app(SsoDirectory::class)->isConfigured());
    }

    /** @param  array<string, mixed>  $body */
    private function fakeLookup(array $body, int $status = 200): void
    {
        Http::fake([self::API.'/users/lookup*' => Http::response($body, $status)]);
    }
}
