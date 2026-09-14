<?php

declare(strict_types=1);

namespace Tests\Feature\ControlPlane;

use App\Http\Controllers\Auth\SsoLoginController;
use App\Models\Client;
use App\Models\Environment;
use App\Models\ExternalIdentity;
use App\Models\SsoLoginAttempt;
use App\Models\Tenant;
use App\Models\TenantIdentityProvider;
use App\Models\TenantMembership;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Masuk lewat penyedia identitas bersama, dari tombol sampai sesi — dan setiap cara ia harus menolak.
 *
 * ## Yang dipalsukan, dan yang tidak
 *
 * Penyedianya dipalsukan lewat `Http::fake()`: dokumen penemuan, JWKS, dan endpoint token. Tetapi
 * token yang dikembalikannya **ditandatangani sungguhan** dengan kunci RSA yang dibuat test ini,
 * jadi verifikasi tanda tangan, `iss`, `aud`, masa berlaku, dan `nonce` di sisi Core benar-benar
 * berjalan — bukan dilewati karena jawabannya dikarang.
 *
 * Repo ini pernah membayar jalur yang hanya diuji lewat tiruan. Karena itu batas sungguhannya juga
 * sudah diukur terhadap penyedia dev asli pada 13 September 2026: dokumen penemuan terbaca dengan
 * `issuer` yang sama persis, dan penukaran kode palsu dijawab `invalid_grant` — autentikasi klien
 * diterima, hanya kodenya yang ditolak. Yang belum dapat diukur tanpa orang yang benar-benar masuk
 * adalah penukaran kode yang berhasil.
 */
class SsoLoginTest extends TestCase
{
    use RefreshDatabase;

    private const ISSUER = 'https://sso.uji';

    private const CLIENT_ID = 'coreerp-uji';

    private const CLIENT_SECRET = 'rahasia-uji';

    private Tenant $tenant;

    private string $privateKey;

    /** @var array<string, mixed> */
    private array $jwks;

    /**
     * Klaim pengganti untuk ID token berikutnya. Menang atas bawaan maupun argumen pembantu.
     *
     * @var array<string, mixed>
     */
    private array $claimOverrides = [];

    private ?string $signWith = null;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'coreerp.base_domain' => 'contoh.co.id',
            'coreerp.sso.issuer' => self::ISSUER,
            'coreerp.sso.client_id' => self::CLIENT_ID,
            'coreerp.sso.client_secret' => self::CLIENT_SECRET,
        ]);

        [$this->privateKey, $this->jwks] = $this->makeSigningKey('kunci-uji');
        $this->tenant = $this->tenantWithProduction('tenanta');

        TenantIdentityProvider::create([
            'tenant_id' => $this->tenant->id,
            'mode' => 'bersama',
            'protokol' => 'oidc',
            'aktif' => true,
        ]);

        $this->fakeProvider();
    }

    // ------------------------------------------------------------------ jalur hijau

    public function test_start_sends_the_browser_to_the_provider_with_pkce_state_and_nonce(): void
    {
        $response = $this->get('http://tenanta.contoh.co.id/sso/masuk');

        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith(self::ISSUER.'/oauth/authorize?', $location);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame(self::CLIENT_ID, $query['client_id']);
        // Satu alamat balik untuk seluruh penempatan, di alamat pangkal — bukan di alamat tenant.
        $this->assertSame('http://contoh.co.id/sso/callback', $query['redirect_uri']);
        $this->assertNotNull($response->getCookie(SsoLoginController::ATTEMPT_COOKIE));

        $attempt = SsoLoginAttempt::query()->sole();
        // Yang disimpan hash-nya, bukan state itu sendiri.
        $this->assertSame(hash('sha256', (string) $query['state']), $attempt->state_hash);
        $this->assertSame(
            rtrim(strtr(base64_encode(hash('sha256', $attempt->code_verifier, true)), '+/', '-_'), '='),
            $query['code_challenge'],
        );
    }

    public function test_a_linked_member_completes_the_whole_ceremony_and_is_signed_in_at_the_tenant_address(): void
    {
        $user = $this->linkedMember('subjek-anggota');

        [$start, $state] = $this->startCeremony();
        $callback = $this->get('http://contoh.co.id/sso/callback?code=kode-uji&state='.$state);

        $handoffUrl = (string) $callback->headers->get('Location');
        $this->assertStringStartsWith('http://tenanta.contoh.co.id/sso/serah?token=', $handoffUrl);

        $this->withCookie(SsoLoginController::ATTEMPT_COOKIE, $this->browserSecret($start))
            ->get($handoffUrl)
            ->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
    }

    /** Sesudah tertaut, `sub` yang menentukan — email yang berganti di penyedia tidak memutusnya. */
    public function test_once_linked_the_subject_wins_over_a_changed_email(): void
    {
        $user = $this->linkedMember('subjek-anggota', 'lama@contoh.co.id');
        $this->claimOverrides = ['email' => 'baru@tempat-lain.co.id'];

        $this->completeCeremony('subjek-anggota');

        $this->assertAuthenticatedAs($user);
    }

    // ------------------------------------------------------------------ penolakan: akun

    /**
     * Inti berkas ini: email yang sama dan "terverifikasi" TIDAK membuka akun yang belum terhubung.
     *
     * Pendaftaran mandiri di penyedia menandai email terverifikasi tanpa memverifikasinya. Siapa pun
     * dapat mendaftar di sana dengan email admin tenant ini, dan token yang ia bawa pulang persis
     * seperti di bawah — sah, bertanda tangan, `email_verified: true`.
     */
    public function test_an_unlinked_sso_account_is_refused_even_when_its_verified_email_matches_a_member(): void
    {
        $member = $this->memberWithEmail('anggota@contoh.co.id');
        $this->claimOverrides = ['email_verified' => true];

        $this->completeCeremony('subjek-penyerang', email: 'anggota@contoh.co.id')
            ->assertRedirect('http://tenanta.contoh.co.id/login?sso_error=belum-terhubung');

        $this->assertGuest();
        $this->assertSame(0, ExternalIdentity::query()->count());
        $this->assertNotNull($member->fresh());
    }

    public function test_an_sso_account_with_no_coreerp_account_is_not_given_one(): void
    {
        $this->completeCeremony('subjek-asing', email: 'tidak-terdaftar@contoh.co.id')
            ->assertRedirect('http://tenanta.contoh.co.id/login?sso_error=belum-terhubung');

        $this->assertGuest();
        $this->assertSame(0, User::query()->where('email', 'tidak-terdaftar@contoh.co.id')->count());
    }

    public function test_a_linked_account_that_is_not_a_member_of_this_tenant_is_refused(): void
    {
        $other = $this->tenantWithProduction('tenantb');
        $user = User::factory()->create(['email' => 'orang-b@contoh.co.id']);
        TenantMembership::create(['tenant_id' => $other->id, 'user_id' => $user->id, 'system_role' => 'owner', 'status' => 'active']);
        ExternalIdentity::create(['user_id' => $user->id, 'issuer' => self::ISSUER, 'subject' => 'subjek-b']);

        $this->completeCeremony('subjek-b', email: 'orang-b@contoh.co.id')
            ->assertRedirect('http://tenanta.contoh.co.id/login?sso_error=bukan-anggota');

        $this->assertGuest();
    }

    // ------------------------------------------------------------------ menghubungkan

    public function test_a_signed_in_member_connects_their_own_sso_account(): void
    {
        $user = $this->memberWithEmail('anggota@contoh.co.id');
        $this->claimOverrides = ['sub' => 'subjek-baru', 'email' => 'anggota.sso@contoh.co.id'];

        [$start, $state] = $this->startConnect($user);
        $handoffUrl = (string) $this->get('http://contoh.co.id/sso/callback?code=kode-uji&state='.$state)->headers->get('Location');

        $this->assertStringStartsWith('http://tenanta.contoh.co.id/sso/serah?token=', $handoffUrl);
        $this->assertSame(0, ExternalIdentity::query()->count(), 'Hubungan belum boleh lahir sebelum peramban yang memulai kembali.');

        $this->withCookie(SsoLoginController::ATTEMPT_COOKIE, $this->browserSecret($start))
            ->get($handoffUrl)
            ->assertRedirect('/settings/security');

        $this->assertDatabaseHas('external_identities', [
            'user_id' => $user->id,
            'issuer' => self::ISSUER,
            'subject' => 'subjek-baru',
            'email_at_link' => 'anggota.sso@contoh.co.id',
        ]);
        $this->assertAuthenticatedAs($user);
    }

    public function test_connecting_requires_the_password_to_be_confirmed_again(): void
    {
        $user = $this->memberWithEmail('anggota@contoh.co.id');

        $this->actingAs($user)
            ->post('http://tenanta.contoh.co.id/sso/hubungkan')
            ->assertRedirectContains('confirm-password');

        $this->assertSame(0, SsoLoginAttempt::query()->count());
    }

    /**
     * Penyerang memulai "hubungkan" dari akunnya sendiri, lalu mengirim tautan penyedia ke korban.
     *
     * Korban mengkliknya dan masuk di penyedia dengan akun SSO miliknya. Token serahnya mendarat di
     * peramban korban — yang tidak memegang cookie penyerang, dan tidak sedang masuk sebagai
     * penyerang. Tidak ada hubungan yang boleh lahir dari situ.
     */
    public function test_a_connect_ceremony_started_by_one_account_cannot_bind_someone_elses_sso_account(): void
    {
        $attacker = $this->memberWithEmail('penyerang@contoh.co.id');
        $victim = $this->memberWithEmail('korban@contoh.co.id');
        $this->claimOverrides = ['sub' => 'subjek-korban', 'email' => 'korban@contoh.co.id'];

        [, $state] = $this->startConnect($attacker);
        $handoffUrl = (string) $this->get('http://contoh.co.id/sso/callback?code=kode-uji&state='.$state)->headers->get('Location');

        $this->actingAs($victim)->get($handoffUrl)->assertRedirect();
        auth()->logout();
        $this->get($handoffUrl)->assertRedirect();

        $this->assertSame(0, ExternalIdentity::query()->count());
    }

    /** Peramban yang sama, tetapi sesinya sudah berganti akun sebelum upacara hubungkan selesai. */
    public function test_a_connect_ceremony_is_not_finished_by_a_different_signed_in_account(): void
    {
        $starter = $this->memberWithEmail('pemulai@contoh.co.id');
        $other = $this->memberWithEmail('lain@contoh.co.id');
        $this->claimOverrides = ['sub' => 'subjek-pemulai'];

        [$start, $state] = $this->startConnect($starter);
        $handoffUrl = (string) $this->get('http://contoh.co.id/sso/callback?code=kode-uji&state='.$state)->headers->get('Location');

        $this->actingAs($other)
            ->withCookie(SsoLoginController::ATTEMPT_COOKIE, $this->browserSecret($start))
            ->get($handoffUrl)
            ->assertRedirect('/login?sso_error=kedaluwarsa');

        $this->assertSame(0, ExternalIdentity::query()->count());
    }

    public function test_a_subject_already_linked_to_someone_else_is_not_taken_over(): void
    {
        $owner = $this->linkedMember('subjek-diambil', 'pemilik@contoh.co.id');
        $user = $this->memberWithEmail('anggota@contoh.co.id');
        $this->claimOverrides = ['sub' => 'subjek-diambil'];

        [, $state] = $this->startConnect($user);

        $this->get('http://contoh.co.id/sso/callback?code=kode-uji&state='.$state)
            ->assertRedirect('http://tenanta.contoh.co.id/settings/security?sso_error=terhubung-ke-akun-lain');

        $this->assertSame([$owner->id], ExternalIdentity::query()->pluck('user_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_an_account_already_linked_is_not_silently_relinked_to_another_subject(): void
    {
        $user = $this->linkedMember('subjek-lama');
        $this->claimOverrides = ['sub' => 'subjek-baru'];

        [, $state] = $this->startConnect($user);

        $this->get('http://contoh.co.id/sso/callback?code=kode-uji&state='.$state)
            ->assertRedirect('http://tenanta.contoh.co.id/settings/security?sso_error=terhubung-ke-akun-lain');

        $this->assertSame(['subjek-lama'], ExternalIdentity::query()->pluck('subject')->all());
    }

    public function test_disconnecting_removes_only_this_accounts_link_and_needs_the_password_again(): void
    {
        $user = $this->linkedMember('subjek-anggota');
        $this->linkedMember('subjek-lain', 'lain@contoh.co.id');

        $this->actingAs($user)->delete('http://tenanta.contoh.co.id/sso/hubungkan')->assertRedirectContains('confirm-password');
        $this->assertSame(2, ExternalIdentity::query()->count());

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->delete('http://tenanta.contoh.co.id/sso/hubungkan')
            ->assertRedirect('/settings/security');

        $this->assertSame(['subjek-lain'], ExternalIdentity::query()->pluck('subject')->all());
    }

    public function test_the_security_page_offers_connecting_only_at_an_address_that_uses_sso(): void
    {
        $user = $this->memberWithEmail('anggota@contoh.co.id');

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get('http://tenanta.contoh.co.id/settings/security')
            ->assertInertia(fn ($page) => $page->where('sso.canConnect', true)->where('sso.linked', false));

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get('http://contoh.co.id/settings/security')
            ->assertInertia(fn ($page) => $page->where('sso.canConnect', false));
    }

    // ------------------------------------------------------------------ penolakan: token

    public function test_a_token_for_another_client_is_refused(): void
    {
        $this->linkedMember('subjek-anggota');
        $this->claimOverrides = ['aud' => 'aplikasi-lain'];

        $this->completeCeremony('subjek-anggota')
            ->assertRedirect('http://tenanta.contoh.co.id/login?sso_error=token-tidak-sah');
    }

    public function test_a_token_from_another_issuer_is_refused(): void
    {
        $this->linkedMember('subjek-anggota');
        $this->claimOverrides = ['iss' => 'https://penerbit-lain.uji'];

        $this->completeCeremony('subjek-anggota')
            ->assertRedirect('http://tenanta.contoh.co.id/login?sso_error=token-tidak-sah');
    }

    public function test_a_token_carrying_another_ceremonys_nonce_is_refused(): void
    {
        $this->linkedMember('subjek-anggota');
        $this->claimOverrides = ['nonce' => 'nonce-upacara-lain'];

        $this->completeCeremony('subjek-anggota')
            ->assertRedirect('http://tenanta.contoh.co.id/login?sso_error=token-tidak-sah');
    }

    /** Tanda tangan dari kunci yang tidak ada di JWKS penyedia, dengan `kid` yang sama. */
    public function test_a_token_signed_by_a_foreign_key_is_refused(): void
    {
        $this->linkedMember('subjek-anggota');
        [$this->signWith] = $this->makeSigningKey('kunci-uji');

        $this->completeCeremony('subjek-anggota')
            ->assertRedirect('http://tenanta.contoh.co.id/login?sso_error=token-tidak-sah');

        $this->assertGuest();
    }

    public function test_an_expired_token_is_refused(): void
    {
        $this->linkedMember('subjek-anggota');
        $this->claimOverrides = ['iat' => time() - 7200, 'exp' => time() - 3600];

        $this->completeCeremony('subjek-anggota')
            ->assertRedirect('http://tenanta.contoh.co.id/login?sso_error=token-tidak-sah');
    }

    // ------------------------------------------------------------------ penolakan: upacara

    /**
     * `state` yang tidak pernah dicatat — termasuk "1-Click Launch" dari portal penyedia, yang
     * membawa `state` buatannya sendiri — tidak punya tenant untuk dikembalikan dan ditolak.
     */
    public function test_an_unknown_state_is_refused(): void
    {
        $this->get('http://contoh.co.id/sso/callback?code=kode-uji&state=launcher_1234abcd')
            ->assertForbidden();
    }

    /**
     * Pembela terhadap login CSRF: upacara yang dimulai di peramban lain tidak dapat diselesaikan di
     * peramban ini, sekalipun token serahnya dicuri.
     */
    public function test_the_handoff_is_refused_without_the_cookie_from_the_browser_that_started_it(): void
    {
        $this->linkedMember('subjek-anggota');

        [, $state] = $this->startCeremony();
        $handoffUrl = (string) $this->get('http://contoh.co.id/sso/callback?code=kode-uji&state='.$state)->headers->get('Location');

        $this->get($handoffUrl)->assertRedirect('/login?sso_error=kedaluwarsa');
        $this->withCookie(SsoLoginController::ATTEMPT_COOKIE, 'rahasia-peramban-lain')
            ->get($handoffUrl)
            ->assertRedirect('/login?sso_error=kedaluwarsa');

        $this->assertGuest();
    }

    public function test_a_handoff_token_opens_one_session_only(): void
    {
        $this->linkedMember('subjek-anggota');

        [$start, $state] = $this->startCeremony();
        $handoffUrl = (string) $this->get('http://contoh.co.id/sso/callback?code=kode-uji&state='.$state)->headers->get('Location');
        $secret = $this->browserSecret($start);

        $this->withCookie(SsoLoginController::ATTEMPT_COOKIE, $secret)->get($handoffUrl)->assertRedirect('/dashboard');
        auth()->logout();

        $this->withCookie(SsoLoginController::ATTEMPT_COOKIE, $secret)
            ->get($handoffUrl)
            ->assertRedirect('/login?sso_error=kedaluwarsa');

        $this->assertGuest();
    }

    /** Token serah milik tenant A tidak membuka sesi di alamat tenant B. */
    public function test_a_handoff_token_does_not_open_another_tenants_address(): void
    {
        $this->linkedMember('subjek-anggota');
        $this->tenantWithProduction('tenantb');

        [$start, $state] = $this->startCeremony();
        $handoffUrl = (string) $this->get('http://contoh.co.id/sso/callback?code=kode-uji&state='.$state)->headers->get('Location');
        $stolen = str_replace('tenanta.', 'tenantb.', $handoffUrl);

        $this->withCookie(SsoLoginController::ATTEMPT_COOKIE, $this->browserSecret($start))
            ->get($stolen)
            ->assertRedirect('/login?sso_error=kedaluwarsa');

        $this->assertGuest();
    }

    public function test_a_ceremony_that_outlived_its_window_is_refused(): void
    {
        $this->linkedMember('subjek-anggota');

        [, $state] = $this->startCeremony();
        SsoLoginAttempt::query()->update(['expires_at' => now()->subMinute()]);

        $this->get('http://contoh.co.id/sso/callback?code=kode-uji&state='.$state)
            ->assertRedirect('http://tenanta.contoh.co.id/login?sso_error=kedaluwarsa');
    }

    // ------------------------------------------------------------------ yang tidak menyala

    public function test_nothing_is_offered_when_the_tenant_did_not_choose_sso(): void
    {
        TenantIdentityProvider::query()->update(['mode' => 'lokal', 'protokol' => null, 'aktif' => false]);

        $this->get('http://tenanta.contoh.co.id/sso/masuk')->assertNotFound();
        $this->get('http://tenanta.contoh.co.id/login')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('ssoLoginUrl', null));
    }

    public function test_nothing_is_offered_when_the_provider_is_not_configured(): void
    {
        config(['coreerp.sso.client_secret' => '']);

        $this->get('http://tenanta.contoh.co.id/sso/masuk')->assertNotFound();
        $this->post('http://contoh.co.id/sso/backchannel-logout', ['logout_token' => 'apa-saja'])->assertNotFound();
    }

    public function test_the_login_page_offers_sso_only_at_an_address_that_uses_it(): void
    {
        $this->get('http://tenanta.contoh.co.id/login')
            ->assertInertia(fn ($page) => $page->where('ssoLoginUrl', '/sso/masuk'));

        $this->get('http://contoh.co.id/login')
            ->assertInertia(fn ($page) => $page->where('ssoLoginUrl', null));
    }

    /** Hanya kode yang dikenal yang menjadi kalimat. Teks bebas di alamat tidak pernah tercetak. */
    public function test_the_login_page_never_prints_free_text_from_the_address(): void
    {
        $this->get('http://tenanta.contoh.co.id/login?sso_error=bukan-anggota')
            ->assertInertia(fn ($page) => $page->where('ssoError', 'Akun ini bukan anggota aktif tenant ini.'));

        $this->get('http://tenanta.contoh.co.id/login?sso_error=Hubungi+0812+untuk+reset+sandi')
            ->assertInertia(fn ($page) => $page->where('ssoError', null));
    }

    // ------------------------------------------------------------------ logout back-channel

    public function test_a_backchannel_logout_ends_every_session_of_that_user(): void
    {
        $user = $this->memberWithEmail('anggota@contoh.co.id');
        $bystander = User::factory()->create();
        ExternalIdentity::create(['user_id' => $user->id, 'issuer' => self::ISSUER, 'subject' => 'subjek-anggota']);

        foreach ([$user->id, $user->id, $bystander->id] as $i => $owner) {
            DB::table('sessions')->insert([
                'id' => 'sesi-'.$i, 'user_id' => $owner, 'ip_address' => null, 'user_agent' => null,
                'payload' => '', 'last_activity' => time(),
            ]);
        }

        $this->post('http://contoh.co.id/sso/backchannel-logout', ['logout_token' => $this->logoutToken('subjek-anggota')])
            ->assertOk();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $bystander->id)->count(), 'Sesi orang lain tidak ikut berakhir.');
    }

    /** Logout token dilarang memuat nonce — yang memuatnya bisa berupa ID token curian yang dikirim ulang. */
    public function test_a_backchannel_token_with_a_nonce_is_refused(): void
    {
        $this->post('http://contoh.co.id/sso/backchannel-logout', [
            'logout_token' => $this->logoutToken('subjek-anggota', ['nonce' => 'n']),
        ])->assertStatus(400);
    }

    public function test_a_backchannel_token_without_the_logout_event_is_refused(): void
    {
        $this->post('http://contoh.co.id/sso/backchannel-logout', [
            'logout_token' => $this->logoutToken('subjek-anggota', ['events' => []]),
        ])->assertStatus(400);
    }

    // ------------------------------------------------------------------ pembantu

    /** @return array{0: TestResponse, 1: string} */
    private function startCeremony(): array
    {
        $response = $this->get('http://tenanta.contoh.co.id/sso/masuk');
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

        return [$response, (string) $query['state']];
    }

    /** Menjalankan upacara sampai serah terima dan memulangkan jawaban langkah yang berhenti. */
    private function completeCeremony(string $subject, string $email = 'anggota@contoh.co.id'): TestResponse
    {
        $this->claimOverrides += ['sub' => $subject, 'email' => $email];

        [$start, $state] = $this->startCeremony();
        $callback = $this->get('http://contoh.co.id/sso/callback?code=kode-uji&state='.$state);
        $location = (string) $callback->headers->get('Location');

        if (! str_contains($location, '/sso/serah?token=')) {
            return $callback;
        }

        return $this->withCookie(SsoLoginController::ATTEMPT_COOKIE, $this->browserSecret($start))->get($location);
    }

    private function browserSecret(TestResponse $start): string
    {
        return (string) $start->getCookie(SsoLoginController::ATTEMPT_COOKIE)?->getValue();
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
                $attempt = SsoLoginAttempt::query()->latest('created_at')->firstOrFail();

                // Penyedia sungguhan menolak klien dan verifier yang salah; tiruannya juga harus,
                // kalau tidak test ini hijau untuk klien yang tidak pernah mengirim keduanya.
                $expectedAuth = 'Basic '.base64_encode(self::CLIENT_ID.':'.self::CLIENT_SECRET);
                if (($request->header('Authorization')[0] ?? null) !== $expectedAuth) {
                    return Http::response(['error' => 'invalid_client'], 401);
                }

                if (($request->data()['code_verifier'] ?? null) !== $attempt->code_verifier) {
                    return Http::response(['error' => 'invalid_grant'], 400);
                }

                return Http::response(['id_token' => $this->idToken($attempt->nonce), 'token_type' => 'Bearer']);
            },
        ]);
    }

    private function idToken(string $nonce): string
    {
        $claims = array_merge([
            'iss' => self::ISSUER,
            'aud' => self::CLIENT_ID,
            'sub' => 'subjek-anggota',
            'email' => 'anggota@contoh.co.id',
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

    /** @return array{0: string, 1: array<string, mixed>} kunci privat PEM, dan JWKS berisi pasangan publiknya */
    private function makeSigningKey(string $kid): array
    {
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $key = openssl_pkey_new($options);

        // PHP untuk Windows tidak menemukan `openssl.cnf`-nya sendiri, dan `openssl_pkey_new()` gagal
        // dengan "configuration file routines::no such file". Berkasnya ikut terpasang di samping
        // binary PHP; di Linux cabang ini tidak pernah diambil.
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

    /** Anggota tenant ini yang sudah menghubungkan akun SSO-nya. */
    private function linkedMember(string $subject, string $email = 'anggota@contoh.co.id'): User
    {
        $user = $this->memberWithEmail($email);
        ExternalIdentity::create(['user_id' => $user->id, 'issuer' => self::ISSUER, 'subject' => $subject]);

        return $user;
    }

    /** @return array{0: TestResponse, 1: string} */
    private function startConnect(User $user): array
    {
        $response = $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post('http://tenanta.contoh.co.id/sso/hubungkan');

        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

        return [$response, (string) ($query['state'] ?? '')];
    }

    private function memberWithEmail(string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        TenantMembership::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'system_role' => 'owner',
            'status' => 'active',
        ]);

        return $user;
    }

    private function tenantWithProduction(string $slug): Tenant
    {
        $client = Client::create(['legal_name' => 'PT '.$slug, 'slug' => 'klien-'.$slug, 'status' => 'active']);
        $tenant = Tenant::create(['client_id' => $client->id, 'name' => 'Tenant '.$slug, 'slug' => $slug, 'status' => 'active']);

        Environment::create([
            'tenant_id' => $tenant->id,
            'kind' => 'production',
            'name' => 'Production',
            'slug' => $slug,
            'database_name' => null,
            'status' => 'active',
            'outbound_allowed' => true,
            'expires_at' => null,
        ]);

        return $tenant;
    }
}
