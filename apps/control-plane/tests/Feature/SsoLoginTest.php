<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Feature;

use ControlPlane\Models\ExternalIdentity;
use ControlPlane\Models\User;
use ControlPlane\Tests\CoreSchema;
use ControlPlane\Tests\TestCase;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Masuk ke konsol operator lewat SSO, menghubungkan akun, dan pintu kata sandi di sampingnya.
 *
 * ## Yang dipalsukan, dan yang tidak
 *
 * Penyedianya dipalsukan lewat `Http::fake()`, tetapi token yang dikembalikannya **ditandatangani
 * sungguhan** dengan kunci RSA yang dibuat test ini — verifikasi tanda tangan, `iss`, `aud`, masa
 * berlaku, dan `nonce` di sisi konsol benar-benar berjalan. Endpoint token tiruannya juga menolak
 * seperti penyedia sungguhan: autentikasi klien yang salah, dan `code_verifier` yang tidak cocok
 * dengan `code_challenge` yang dikirim di awal upacara.
 *
 * Batas sungguhannya diukur terpisah terhadap penyedia dev asli, sama seperti suite Core.
 */
class SsoLoginTest extends TestCase
{
    use CoreSchema;

    private const ISSUER = 'https://sso.uji';

    private const CLIENT_ID = 'konsol-uji';

    private const CLIENT_SECRET = 'rahasia-uji';

    private const PASSWORD = 'kata-sandi-operator';

    private string $privateKey;

    /** @var array<string, mixed> */
    private array $jwks;

    /**
     * Klaim pengganti untuk ID token berikutnya.
     *
     * @var array<string, mixed>
     */
    private array $claimOverrides = [];

    private ?string $signWith = null;

    /** `nonce` dan `code_challenge` dari alamat penyedia yang terakhir dibuka, seperti dicatat penyedia. */
    private ?string $issuedNonce = null;

    private ?string $issuedChallenge = null;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'sso.issuer' => self::ISSUER,
            'sso.client_id' => self::CLIENT_ID,
            'sso.client_secret' => self::CLIENT_SECRET,
            'sso.password_login' => true,
            'sso.break_glass_emails' => [],
        ]);

        [$this->privateKey, $this->jwks] = $this->makeSigningKey('kunci-uji');

        Http::preventStrayRequests();
        $this->fakeProvider();
    }

    // ------------------------------------------------------------------ masuk lewat SSO

    public function test_start_sends_the_browser_to_the_provider_with_pkce_state_and_nonce(): void
    {
        $query = $this->providerQuery($this->get('/sso/masuk'));

        $this->assertSame('code', $query['response_type']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame(self::CLIENT_ID, $query['client_id']);
        // Alamat konsol itu sendiri — permintaan test berangkat dari APP_URL.
        $this->assertSame(rtrim((string) config('app.url'), '/').'/sso/callback', $query['redirect_uri']);
        $this->assertNotEmpty($query['nonce']);

        // Yang disimpan di sesi hash-nya, bukan state itu sendiri.
        $ceremony = session('sso.ceremony');
        $this->assertSame(hash('sha256', $query['state']), $ceremony['state_hash']);
        $this->assertArrayNotHasKey('state', $ceremony);
    }

    public function test_a_linked_operator_completes_the_ceremony_and_is_signed_in(): void
    {
        $operator = $this->operator('operator@contoh.test');
        $this->link($operator, 'subjek-operator');

        $this->completeLogin('subjek-operator')->assertRedirect('/lingkungan');

        $this->assertAuthenticatedAs($operator);
        $this->assertIsInt(session('login_at'));
        $this->get('/lingkungan')->assertOk();
    }

    /** Sesudah terhubung, `sub` yang menentukan — email yang berganti di penyedia tidak memutusnya. */
    public function test_once_linked_the_subject_wins_over_a_changed_email(): void
    {
        $operator = $this->operator('operator@contoh.test');
        $this->link($operator, 'subjek-operator');

        $this->completeLogin('subjek-operator', email: 'alamat-baru@tempat-lain.test');

        $this->assertAuthenticatedAs($operator);
    }

    /**
     * Inti berkas ini: email yang sama dan "terverifikasi" TIDAK membuka akun yang belum terhubung.
     *
     * Siapa pun dapat mendaftar di penyedia dengan email seorang operator, dan penyedia menandainya
     * terverifikasi tanpa memverifikasinya. Token yang dibawa pulang orang itu persis seperti di
     * bawah: sah, bertanda tangan, `email_verified: true`.
     */
    public function test_an_unlinked_sso_account_is_refused_even_when_its_verified_email_matches_an_operator(): void
    {
        $this->operator('operator@contoh.test');
        // Operator lain yang sudah terhubung: subjek asing tidak boleh jatuh ke hubungan milik siapa pun.
        $this->link($this->operator('lain@contoh.test'), 'subjek-lain');

        $this->completeLogin('subjek-penyerang', email: 'operator@contoh.test')
            ->assertRedirect('/login?sso_error=belum-terhubung');

        $this->assertGuest();
        $this->assertSame(['subjek-lain'], ExternalIdentity::query()->pluck('subject')->all());
    }

    public function test_a_linked_account_that_is_not_an_operator_is_refused_without_a_session(): void
    {
        $user = $this->ordinaryUser('bukan-operator@contoh.test');
        $this->link($user, 'subjek-biasa');

        $this->completeLogin('subjek-biasa')->assertRedirect('/login?sso_error=bukan-operator');

        $this->assertGuest();
    }

    public function test_a_provider_error_on_the_callback_is_shown_as_a_rejection(): void
    {
        $state = $this->startLogin();

        $this->get('/sso/callback?error=access_denied&state='.$state)
            ->assertRedirect('/login?sso_error=ditolak-penyedia');

        Http::assertNotSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/oauth/token'));
    }

    // ------------------------------------------------------------------ penolakan: upacara

    /**
     * Pembela login CSRF: upacara yang tidak dimulai di sesi ini — tautan dari orang lain, atau
     * "1-Click Launch" portal penyedia dengan `state` buatannya sendiri — tidak pernah ditukar.
     */
    public function test_a_callback_without_a_ceremony_in_this_session_is_refused_before_any_exchange(): void
    {
        $operator = $this->operator('operator@contoh.test');
        $this->link($operator, 'subjek-operator');

        $this->get('/sso/callback?code=kode-uji&state=launcher_1234abcd')
            ->assertRedirect('/login?sso_error=kedaluwarsa');

        $this->assertGuest();
        Http::assertNothingSent();
    }

    public function test_a_callback_with_another_ceremonys_state_is_refused(): void
    {
        $operator = $this->operator('operator@contoh.test');
        $this->link($operator, 'subjek-operator');
        $this->startLogin();

        $this->get('/sso/callback?code=kode-uji&state=state-milik-peramban-lain')
            ->assertRedirect('/login?sso_error=kedaluwarsa');

        $this->assertGuest();
    }

    public function test_a_state_is_used_once(): void
    {
        $operator = $this->operator('operator@contoh.test');
        $this->link($operator, 'subjek-operator');
        $this->claimOverrides = ['sub' => 'subjek-operator'];

        $state = $this->startLogin();
        $this->get('/sso/callback?code=kode-uji&state='.$state)->assertRedirect('/lingkungan');

        // Sesi yang sama, tanpa keluar lebih dulu: `regenerate()` sesudah masuk tidak membuang isi
        // sesi, jadi hanya `pull` yang dapat mencegah alamat balik yang sama dipakai kedua kali.
        $this->get('/sso/callback?code=kode-uji&state='.$state)
            ->assertRedirect('/akun?sso_error=kedaluwarsa');

        $this->assertCount(1, Http::recorded(fn (HttpRequest $request) => str_ends_with($request->url(), '/oauth/token')));
    }

    public function test_a_ceremony_that_outlived_its_window_is_refused(): void
    {
        $operator = $this->operator('operator@contoh.test');
        $this->link($operator, 'subjek-operator');
        $this->claimOverrides = ['sub' => 'subjek-operator'];

        $state = $this->startLogin();
        $this->travel(11)->minutes();

        $this->get('/sso/callback?code=kode-uji&state='.$state)
            ->assertRedirect('/login?sso_error=kedaluwarsa');

        $this->assertGuest();
    }

    // ------------------------------------------------------------------ penolakan: token

    public function test_a_token_for_another_client_is_refused(): void
    {
        $this->assertTokenRefused(['aud' => 'aplikasi-lain']);
    }

    /** Klien Core dan klien konsol berbagi penyedia; token untuk Core tidak membuka konsol. */
    public function test_a_token_issued_to_the_core_client_is_refused(): void
    {
        $this->assertTokenRefused(['aud' => 'coreerp']);
    }

    public function test_a_token_from_another_issuer_is_refused(): void
    {
        $this->assertTokenRefused(['iss' => 'https://penerbit-lain.uji']);
    }

    public function test_a_token_carrying_another_ceremonys_nonce_is_refused(): void
    {
        $this->assertTokenRefused(['nonce' => 'nonce-upacara-lain']);
    }

    public function test_an_expired_token_is_refused(): void
    {
        $this->assertTokenRefused(['iat' => time() - 7200, 'exp' => time() - 3600]);
    }

    /** Tanda tangan dari kunci yang tidak ada di JWKS penyedia, dengan `kid` yang sama. */
    public function test_a_token_signed_by_a_foreign_key_is_refused(): void
    {
        [$this->signWith] = $this->makeSigningKey('kunci-uji');

        $this->assertTokenRefused([]);
    }

    // ------------------------------------------------------------------ menghubungkan

    public function test_an_operator_connects_their_own_sso_account_after_typing_the_password(): void
    {
        $operator = $this->operator('operator@contoh.test');
        $this->claimOverrides = ['sub' => 'subjek-baru', 'email' => 'operator.sso@contoh.test'];

        $state = $this->startConnect($operator);
        $this->assertSame(0, ExternalIdentity::query()->count(), 'Hubungan belum boleh lahir sebelum penyedia menjawab.');

        $this->get('/sso/callback?code=kode-uji&state='.$state)->assertRedirect('/akun');

        $this->assertDatabaseHas('external_identities', [
            'user_id' => $operator->id,
            'issuer' => self::ISSUER,
            'subject' => 'subjek-baru',
            'email_at_link' => 'operator.sso@contoh.test',
        ]);
        $this->assertAuthenticatedAs($operator);
    }

    /** Tombolnya dikirim Inertia, yang tidak dapat mengikuti pengalihan ke domain lain. */
    public function test_connecting_from_the_page_asks_the_browser_to_leave_by_itself(): void
    {
        $operator = $this->operator('operator@contoh.test');

        $this->actingAs($operator)
            ->withHeaders(['X-Inertia' => 'true'])
            ->post('/akun/sso', ['password' => self::PASSWORD])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location');
    }

    public function test_connecting_with_a_wrong_password_starts_nothing(): void
    {
        $operator = $this->operator('operator@contoh.test');

        $this->actingAs($operator)
            ->from('/akun')
            ->post('/akun/sso', ['password' => 'bukan-kata-sandinya'])
            ->assertRedirect('/akun')
            ->assertSessionHasErrors('password');

        $this->actingAs($operator)->post('/akun/sso')->assertSessionHasErrors('password');

        $this->assertNull(session('sso.ceremony'));
    }

    /** Peramban yang sama, tetapi sesinya berganti akun sebelum upacara hubungkan selesai. */
    public function test_a_connect_ceremony_is_not_finished_by_a_different_signed_in_account(): void
    {
        $starter = $this->operator('pemulai@contoh.test');
        $other = $this->operator('lain@contoh.test');
        $this->claimOverrides = ['sub' => 'subjek-pemulai'];

        $state = $this->startConnect($starter);

        $this->actingAs($other)
            ->get('/sso/callback?code=kode-uji&state='.$state)
            ->assertRedirect('/akun?sso_error=kedaluwarsa');

        $this->assertSame(0, ExternalIdentity::query()->count());
    }

    public function test_a_subject_already_linked_to_someone_else_is_not_taken_over(): void
    {
        $owner = $this->operator('pemilik@contoh.test');
        $this->link($owner, 'subjek-diambil');
        $operator = $this->operator('operator@contoh.test');
        $this->claimOverrides = ['sub' => 'subjek-diambil'];

        $state = $this->startConnect($operator);

        $this->get('/sso/callback?code=kode-uji&state='.$state)
            ->assertRedirect('/akun?sso_error=terhubung-ke-akun-lain');

        $this->assertSame([$owner->id], ExternalIdentity::query()->pluck('user_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_an_account_already_linked_is_not_silently_relinked_to_another_subject(): void
    {
        $operator = $this->operator('operator@contoh.test');
        $this->link($operator, 'subjek-lama');
        $this->claimOverrides = ['sub' => 'subjek-baru'];

        $state = $this->startConnect($operator);

        $this->get('/sso/callback?code=kode-uji&state='.$state)
            ->assertRedirect('/akun?sso_error=terhubung-ke-akun-lain');

        $this->assertSame(['subjek-lama'], ExternalIdentity::query()->pluck('subject')->all());
    }

    public function test_disconnecting_needs_the_password_and_removes_only_this_accounts_link(): void
    {
        $operator = $this->operator('operator@contoh.test');
        $this->link($operator, 'subjek-operator');
        $this->link($this->operator('lain@contoh.test'), 'subjek-lain');

        $this->actingAs($operator)
            ->delete('/akun/sso', ['password' => 'salah'])
            ->assertSessionHasErrors('password');
        $this->assertSame(2, ExternalIdentity::query()->count());

        $this->actingAs($operator)
            ->delete('/akun/sso', ['password' => self::PASSWORD])
            ->assertRedirect('/akun');

        $this->assertSame(['subjek-lain'], ExternalIdentity::query()->pluck('subject')->all());
    }

    public function test_the_account_page_shows_the_link_and_is_closed_to_non_operators(): void
    {
        $operator = $this->operator('operator@contoh.test');
        $this->link($operator, 'subjek-operator', 'operator.sso@contoh.test');

        $this->actingAs($operator)
            ->get('/akun')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('account')
                ->where('sso.linked', true)
                ->where('sso.emailAtLink', 'operator.sso@contoh.test'));

        $this->actingAs($this->ordinaryUser('biasa@contoh.test'))->get('/akun')->assertNotFound();
    }

    // ------------------------------------------------------------------ pintu kata sandi

    public function test_password_login_is_throttled_per_email_and_address(): void
    {
        $operator = $this->operator('operator@contoh.test');

        // Huruf besar-kecil email tidak membuka hitungan baru.
        foreach (['operator@contoh.test', 'OPERATOR@contoh.test', 'Operator@contoh.test', 'operator@contoh.test', 'operator@CONTOH.test'] as $variant) {
            $this->post('/login', ['email' => $variant, 'password' => 'tebakan'])
                ->assertSessionHasErrors(['email' => 'Email atau kata sandi tidak cocok.']);
        }

        // Kata sandi yang benar pun ditolak selama batasnya berlaku.
        $this->post('/login', ['email' => 'operator@contoh.test', 'password' => self::PASSWORD])
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->travel(61)->seconds();

        $this->post('/login', ['email' => 'operator@contoh.test', 'password' => self::PASSWORD])
            ->assertRedirect('/lingkungan');
        $this->assertAuthenticatedAs($operator);
    }

    public function test_a_closed_password_door_refuses_operators_with_the_right_password(): void
    {
        config(['sso.password_login' => false, 'sso.break_glass_emails' => ['darurat@contoh.test']]);
        $this->operator('operator@contoh.test');

        $message = 'Masuk dengan kata sandi ditutup di konsol ini. Gunakan Masuk dengan SSO.';

        $this->post('/login', ['email' => 'operator@contoh.test', 'password' => self::PASSWORD])
            ->assertSessionHasErrors(['email' => $message]);
        // Email yang tidak ada mendapat kalimat yang sama: formulirnya bukan alat pencari akun.
        $this->post('/login', ['email' => 'tidak-ada@contoh.test', 'password' => 'apa-saja'])
            ->assertSessionHasErrors(['email' => $message]);

        $this->assertGuest();
    }

    public function test_a_break_glass_account_still_gets_in_when_the_password_door_is_closed(): void
    {
        config(['sso.password_login' => false, 'sso.break_glass_emails' => ['darurat@contoh.test']]);
        $breakGlass = $this->operator('Darurat@contoh.test');

        $this->post('/login', ['email' => 'darurat@contoh.test', 'password' => 'salah'])
            ->assertSessionHasErrors(['email' => 'Email atau kata sandi tidak cocok.']);

        $this->post('/login', ['email' => 'Darurat@contoh.test', 'password' => self::PASSWORD])
            ->assertRedirect('/lingkungan');

        $this->assertAuthenticatedAs($breakGlass);
    }

    public function test_the_login_page_describes_both_doors(): void
    {
        $this->get('/login')->assertInertia(fn ($page) => $page
            ->where('ssoAvailable', true)
            ->where('passwordLoginOpen', true)
            ->where('ssoError', null));

        config(['sso.password_login' => false]);

        $this->get('/login')->assertInertia(fn ($page) => $page->where('passwordLoginOpen', false));
    }

    /** Hanya kode yang dikenal yang menjadi kalimat. Teks bebas di alamat tidak pernah tercetak. */
    public function test_the_login_page_never_prints_free_text_from_the_address(): void
    {
        $this->get('/login?sso_error=bukan-operator')
            ->assertInertia(fn ($page) => $page->where('ssoError', 'Akun ini bukan operator Pusat Admin.'));

        $this->get('/login?sso_error=Hubungi+0812+untuk+reset+sandi')
            ->assertInertia(fn ($page) => $page->where('ssoError', null));
    }

    // ------------------------------------------------------------------ logout back-channel

    public function test_a_backchannel_logout_ends_the_operators_session_on_the_next_request(): void
    {
        $operator = $this->operator('operator@contoh.test');
        $this->link($operator, 'subjek-operator');

        $this->actingAs($operator)
            ->withSession(['login_at' => now()->getTimestamp()])
            ->get('/lingkungan')
            ->assertOk();

        $this->post('/sso/backchannel-logout', ['logout_token' => $this->logoutToken('subjek-operator')])
            ->assertOk();

        $this->get('/lingkungan')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_a_backchannel_logout_leaves_other_operators_and_later_sessions_alone(): void
    {
        $operator = $this->operator('operator@contoh.test');
        $bystander = $this->operator('lain@contoh.test');
        $this->link($operator, 'subjek-operator');

        $this->post('/sso/backchannel-logout', ['logout_token' => $this->logoutToken('subjek-operator')])
            ->assertOk();

        $this->actingAs($bystander)
            ->withSession(['login_at' => now()->subHour()->getTimestamp()])
            ->get('/lingkungan')
            ->assertOk();

        // Masuk lagi sesudah keluar di penyedia adalah sesi baru, bukan sesi yang harus diakhiri.
        $this->travel(5)->seconds();
        $this->actingAs($operator)
            ->withSession(['login_at' => now()->getTimestamp()])
            ->get('/lingkungan')
            ->assertOk();
    }

    /** Logout token dilarang memuat nonce — yang memuatnya bisa berupa ID token curian yang dikirim ulang. */
    public function test_a_backchannel_token_with_a_nonce_is_refused(): void
    {
        $this->post('/sso/backchannel-logout', [
            'logout_token' => $this->logoutToken('subjek-operator', ['nonce' => 'n']),
        ])->assertStatus(400);
    }

    public function test_a_backchannel_token_without_the_logout_event_is_refused(): void
    {
        $this->post('/sso/backchannel-logout', [
            'logout_token' => $this->logoutToken('subjek-operator', ['events' => []]),
        ])->assertStatus(400);
    }

    // ------------------------------------------------------------------ yang tidak menyala

    public function test_nothing_is_offered_when_sso_is_not_configured(): void
    {
        config(['sso.client_secret' => '']);
        $operator = $this->operator('operator@contoh.test');

        $this->get('/sso/masuk')->assertNotFound();
        $this->get('/sso/callback?code=a&state=b')->assertNotFound();
        $this->post('/sso/backchannel-logout', ['logout_token' => 'apa-saja'])->assertNotFound();
        $this->get('/login')->assertInertia(fn ($page) => $page->where('ssoAvailable', false));

        $this->actingAs($operator)->get('/akun')->assertInertia(fn ($page) => $page->where('sso', null));
        $this->actingAs($operator)->post('/akun/sso', ['password' => self::PASSWORD])->assertNotFound();
    }

    // ------------------------------------------------------------------ pembantu

    /** @param  array<string, mixed>  $claims */
    private function assertTokenRefused(array $claims): void
    {
        $operator = $this->operator('operator@contoh.test');
        $this->link($operator, 'subjek-operator');
        $this->claimOverrides = $claims;

        $this->completeLogin('subjek-operator')->assertRedirect('/login?sso_error=token-tidak-sah');

        $this->assertGuest();
    }

    private function startLogin(): string
    {
        return $this->recordIssuedCeremony($this->get('/sso/masuk'));
    }

    /** @return TestResponse<Response> */
    private function completeLogin(string $subject, string $email = 'operator@contoh.test'): TestResponse
    {
        $this->claimOverrides += ['sub' => $subject, 'email' => $email];

        $state = $this->startLogin();

        return $this->get('/sso/callback?code=kode-uji&state='.$state);
    }

    private function startConnect(User $user): string
    {
        return $this->recordIssuedCeremony($this->actingAs($user)->post('/akun/sso', ['password' => self::PASSWORD]));
    }

    /**
     * Mencatat yang dikirim konsol ke penyedia, seperti penyedia sungguhan mencatatnya.
     *
     * @param  TestResponse<Response>  $response
     */
    private function recordIssuedCeremony(TestResponse $response): string
    {
        $query = $this->providerQuery($response);

        $this->issuedNonce = $query['nonce'];
        $this->issuedChallenge = $query['code_challenge'];

        return $query['state'];
    }

    /**
     * Isi alamat penyedia yang dituju jawaban ini.
     *
     * @param  TestResponse<Response>  $response
     * @return array<string, string>
     */
    private function providerQuery(TestResponse $response): array
    {
        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith(self::ISSUER.'/oauth/authorize?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $strings = [];
        foreach ($query as $key => $value) {
            $strings[(string) $key] = is_string($value) ? $value : '';
        }

        return $strings;
    }

    private function fakeProvider(): void
    {
        Http::fake([
            self::ISSUER.'/.well-known/openid-configuration' => Http::response([
                'issuer' => self::ISSUER,
                'authorization_endpoint' => self::ISSUER.'/oauth/authorize',
                'token_endpoint' => self::ISSUER.'/oauth/token',
                'jwks_uri' => self::ISSUER.'/.well-known/jwks.json',
            ]),
            self::ISSUER.'/.well-known/jwks.json' => fn () => Http::response($this->jwks),
            self::ISSUER.'/oauth/token' => function (HttpRequest $request) {
                $expectedAuth = 'Basic '.base64_encode(self::CLIENT_ID.':'.self::CLIENT_SECRET);
                if (($request->header('Authorization')[0] ?? null) !== $expectedAuth) {
                    return Http::response(['error' => 'invalid_client'], 401);
                }

                // PKCE diperiksa seperti di penyedia: verifier yang dikirim sekarang harus menghasilkan
                // challenge yang dikirim di awal upacara.
                $verifier = (string) ($request->data()['code_verifier'] ?? '');
                $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
                if ($this->issuedChallenge === null || ! hash_equals($this->issuedChallenge, $challenge)) {
                    return Http::response(['error' => 'invalid_grant'], 400);
                }

                return Http::response(['id_token' => $this->idToken((string) $this->issuedNonce), 'token_type' => 'Bearer']);
            },
        ]);
    }

    private function idToken(string $nonce): string
    {
        $claims = array_merge([
            'iss' => self::ISSUER,
            'aud' => self::CLIENT_ID,
            'sub' => 'subjek-operator',
            'email' => 'operator@contoh.test',
            'email_verified' => true,
            'nonce' => $nonce,
            'iat' => time(),
            'exp' => time() + 3600,
        ], $this->claimOverrides);

        return JWT::encode($claims, $this->signWith ?? $this->privateKey, 'RS256', 'kunci-uji');
    }

    /** @param  array<string, mixed>  $overrides */
    private function logoutToken(string $subject, array $overrides = []): string
    {
        $claims = array_merge([
            'iss' => self::ISSUER,
            'aud' => self::CLIENT_ID,
            'sub' => $subject,
            'iat' => time(),
            'jti' => 'jti-uji',
            'events' => ['http://schemas.openid.net/event/backchannel-logout' => (object) []],
        ], $overrides);

        return JWT::encode($claims, $this->privateKey, 'RS256', 'kunci-uji');
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function makeSigningKey(string $kid): array
    {
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $key = openssl_pkey_new($options);

        // PHP untuk Windows tidak menemukan `openssl.cnf`-nya sendiri. Berkasnya ikut terpasang di
        // samping binary PHP; di Linux cabang ini tidak pernah diambil.
        $bundledConfig = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';
        if ($key === false && is_file($bundledConfig)) {
            $key = openssl_pkey_new($options + ['config' => $bundledConfig]);
        }

        $this->assertNotFalse($key, 'Kunci RSA untuk penyedia tiruan tidak dapat dibuat.');
        openssl_pkey_export($key, $pem, null, is_file($bundledConfig) ? ['config' => $bundledConfig] : []);
        $details = openssl_pkey_get_details($key);
        $this->assertIsArray($details);

        $b64url = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

        return [(string) $pem, ['keys' => [[
            'kty' => 'RSA',
            'kid' => $kid,
            'use' => 'sig',
            'alg' => 'RS256',
            'n' => $b64url($details['rsa']['n']),
            'e' => $b64url($details['rsa']['e']),
        ]]]];
    }

    private function operator(string $email): User
    {
        $user = $this->ordinaryUser($email);

        DB::table('provider_access')->insert([
            'user_id' => $user->id,
            'role' => 'provider_admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function ordinaryUser(string $email): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'Akun '.$email,
            'email' => $email,
            'password' => bcrypt(self::PASSWORD),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::query()->findOrFail($id);
    }

    private function link(User $user, string $subject, ?string $emailAtLink = null): void
    {
        ExternalIdentity::create([
            'user_id' => $user->id,
            'issuer' => self::ISSUER,
            'subject' => $subject,
            'email_at_link' => $emailAtLink,
        ]);
    }
}
