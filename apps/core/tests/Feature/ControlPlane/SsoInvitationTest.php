<?php

declare(strict_types=1);

namespace Tests\Feature\ControlPlane;

use App\Http\Controllers\Auth\SsoLoginController;
use App\Models\Client;
use App\Models\Environment;
use App\Models\ExternalIdentity;
use App\Models\InvitationCode;
use App\Models\SsoLoginAttempt;
use App\Models\Tenant;
use App\Models\TenantIdentityProvider;
use App\Models\TenantMembership;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Undangan yang terikat pada satu akun SSO: dari operator mengetik email sampai orangnya masuk.
 *
 * ## Yang dijaga berkas ini
 *
 * Satu kalimat: **email tidak pernah menjadi bukti identitas.** Undangan menyimpan subjek yang
 * dijawab penyedia saat dibuat, dan hanya ID token yang membawa subjek itu yang dapat menukarkannya.
 * Test `an_account_with_the_invited_email_but_another_subject_is_refused` adalah kembaran dari
 * penjaga yang sama di `SsoLoginTest`, dan keduanya harus tetap ada: yang satu menjaga jalur masuk,
 * yang lain menjaga jalur undangan.
 *
 * Penyedianya dipalsukan, tetapi ID token yang dikembalikannya **ditandatangani sungguhan** dengan
 * kunci RSA yang dibuat test ini — sama seperti `SsoLoginTest`, dan karena alasan yang sama.
 */
class SsoInvitationTest extends TestCase
{
    use RefreshDatabase;

    private const ISSUER = 'https://sso.uji';

    private const API = 'https://sso.uji/api/v1';

    private const CLIENT_ID = 'coreerp-uji';

    private const CLIENT_SECRET = 'rahasia-uji';

    private Tenant $tenant;

    private User $operator;

    private string $privateKey;

    /** @var array<string, mixed> */
    private array $jwks;

    /** @var array<string, mixed> */
    private array $claimOverrides = [];

    /** Jawaban pencarian berikutnya; null berarti penyedia menjawab 404. */
    private ?array $lookupUser = ['id' => 4242, 'name' => 'Dewi Perawat', 'email' => 'dewi@klinik.test', 'is_active' => true];

    private int $lookupStatus = 200;

    private int $sendStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'coreerp.base_domain' => 'contoh.co.id',
            'coreerp.sso.issuer' => self::ISSUER,
            'coreerp.sso.client_id' => self::CLIENT_ID,
            'coreerp.sso.client_secret' => self::CLIENT_SECRET,
            'coreerp.sso.api_url' => null,
        ]);

        [$this->privateKey, $this->jwks] = $this->makeSigningKey('kunci-uji');
        $this->tenant = $this->tenantWithProduction('tenanta');

        TenantIdentityProvider::create([
            'tenant_id' => $this->tenant->id,
            'mode' => 'bersama',
            'protokol' => 'oidc',
            'aktif' => true,
        ]);

        $this->operator = $this->member('operator@klinik.test', 'owner');
        $this->fakeProvider();
    }

    // ------------------------------------------------------------------ membuat undangan

    public function test_an_email_that_exists_in_sso_is_bound_to_its_subject(): void
    {
        $this->createInvitation()->assertSessionHasNoErrors();

        $invitation = InvitationCode::query()->sole();

        $this->assertTrue($invitation->isSsoBound());
        // Yang diikat subjeknya, dan bentuknya teks walaupun penyedia mengirim angka.
        $this->assertSame('4242', $invitation->sso_subject);
        $this->assertSame(self::ISSUER, $invitation->sso_issuer);
        // Ejaan penyedia, bukan ketikan operator.
        $this->assertSame('dewi@klinik.test', $invitation->sso_email_at_invite);
        $this->assertSame('Dewi Perawat', $invitation->sso_name_at_invite);
        $this->assertNotNull($invitation->sso_checked_at);
        $this->assertNotNull($invitation->expires_at);

        // Tidak ada akun yang lahir saat mengundang. Akun lahir saat orangnya benar-benar masuk.
        $this->assertSame(1, User::query()->count());

        $this->assertDatabaseHas('access_audit_events', ['action' => 'access.invitation.sso.diterbitkan']);
    }

    public function test_the_provider_is_asked_to_send_the_invitation_email(): void
    {
        $this->createInvitation();

        $invitation = InvitationCode::query()->sole();
        $this->assertNotNull($invitation->sso_notified_at);

        Http::assertSent(function (HttpRequest $request): bool {
            if ($request->url() !== self::API.'/notifications/send-invitation') {
                return false;
            }

            $body = $request->data();

            // Tautannya mendarat di domain dasar, bukan di alamat tenant: penyedia menolak host di
            // luar alamat balik client, dan alamat balik itu ada di domain dasar.
            return $body['recipient_email'] === 'dewi@klinik.test'
                && str_starts_with((string) $body['accept_url'], 'http://contoh.co.id/undangan?kode=')
                && $body['expires_in_days'] >= 1
                && $request->hasHeader('X-Client-Secret', self::CLIENT_SECRET);
        });
    }

    /** Surat yang gagal terkirim tidak menghanguskan undangannya. */
    public function test_an_invitation_survives_a_provider_that_cannot_send_the_email(): void
    {
        $this->sendStatus = 503;

        $this->createInvitation()->assertSessionHasNoErrors();

        $invitation = InvitationCode::query()->sole();
        $this->assertTrue($invitation->isSsoBound());
        $this->assertNull($invitation->sso_notified_at);
    }

    public function test_an_email_the_provider_does_not_know_is_refused(): void
    {
        $this->lookupUser = null;
        $this->lookupStatus = 404;
        $this->fakeProvider();

        $this->createInvitation()->assertSessionHasErrors('sso_email');

        $this->assertSame(0, InvitationCode::query()->count());
        $this->assertDatabaseHas('access_audit_events', ['action' => 'access.invitation.sso.ditolak']);
    }

    public function test_an_inactive_sso_account_is_refused(): void
    {
        $this->lookupUser = ['id' => 4242, 'name' => 'Mantan Staf', 'email' => 'dewi@klinik.test', 'is_active' => false];
        $this->fakeProvider();

        $this->createInvitation()->assertSessionHasErrors('sso_email');

        $this->assertSame(0, InvitationCode::query()->count());
    }

    public function test_a_tenant_that_does_not_use_sso_cannot_invite_by_email(): void
    {
        TenantIdentityProvider::query()->where('tenant_id', $this->tenant->id)->update(['mode' => 'lokal', 'aktif' => false]);

        $this->createInvitation()->assertSessionHasErrors('sso_email');

        $this->assertSame(0, InvitationCode::query()->count());
        // Dan penyedia tidak pernah ditanya: tenant yang tidak memakai SSO tidak boleh menjadi
        // jalan bagi siapa pun untuk menyisir direktori.
        Http::assertNotSent(fn (HttpRequest $request): bool => str_contains($request->url(), '/users/lookup'));
    }

    public function test_a_provider_that_is_down_refuses_the_invitation_without_writing_anything(): void
    {
        $this->lookupStatus = 500;
        $this->fakeProvider();

        $this->createInvitation()->assertSessionHasErrors('sso_email');

        $this->assertSame(0, InvitationCode::query()->count());
    }

    public function test_a_second_open_invitation_for_the_same_person_is_refused(): void
    {
        $this->createInvitation()->assertSessionHasNoErrors();
        $this->createInvitation()->assertSessionHasErrors('sso_email');

        $this->assertSame(1, InvitationCode::query()->count());
    }

    public function test_someone_who_is_already_a_member_is_not_invited_again(): void
    {
        $member = $this->member('dewi@klinik.test');
        ExternalIdentity::create(['user_id' => $member->id, 'issuer' => self::ISSUER, 'subject' => '4242']);

        $this->createInvitation()->assertSessionHasErrors('sso_email');

        $this->assertSame(0, InvitationCode::query()->count());
    }

    /** Kode anonim tetap lahir tanpa menyentuh penyedia sama sekali. */
    public function test_an_anonymous_code_never_touches_the_provider(): void
    {
        $this->createInvitation(email: null)->assertSessionHasNoErrors();

        $invitation = InvitationCode::query()->sole();
        $this->assertFalse($invitation->isSsoBound());
        $this->assertNull($invitation->expires_at);

        Http::assertNotSent(fn (HttpRequest $request): bool => str_contains($request->url(), '/api/v1/'));
    }

    // ------------------------------------------------------------------ menukarkan undangan

    public function test_the_invited_account_joins_and_gets_its_membership_in_one_go(): void
    {
        $invitation = $this->boundInvitation();

        $this->completeCeremony($invitation)->assertRedirect('/dashboard');

        $user = User::query()->where('email', 'dewi@klinik.test')->sole();
        $this->assertAuthenticatedAs($user);

        // Akun, tautan identitas, dan keanggotaan lahir bersama.
        $this->assertDatabaseHas('external_identities', ['user_id' => $user->id, 'issuer' => self::ISSUER, 'subject' => '4242']);
        $this->assertDatabaseHas('tenant_memberships', ['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'status' => 'active', 'system_role' => 'user']);

        $invitation->refresh();
        $this->assertNotNull($invitation->sso_redeemed_at);
        $this->assertSame($user->id, $invitation->sso_redeemed_by);

        // Kata sandinya tidak dapat dipakai siapa pun: kolomnya NOT NULL, jadi diisi acak.
        $this->assertFalse(Hash::check('password', (string) $user->password));

        $this->assertDatabaseHas('access_audit_events', ['action' => 'access.invitation.sso.ditukar']);
    }

    public function test_an_account_that_already_exists_joins_without_a_second_account(): void
    {
        $existing = User::factory()->create(['email' => 'dewi@klinik.test']);
        ExternalIdentity::create(['user_id' => $existing->id, 'issuer' => self::ISSUER, 'subject' => '4242']);

        $invitation = $this->boundInvitation();
        $before = User::query()->count();

        $this->completeCeremony($invitation)->assertRedirect('/dashboard');

        $this->assertSame($before, User::query()->count());
        $this->assertSame(1, ExternalIdentity::query()->count());
        $this->assertAuthenticatedAs($existing->fresh());
        $this->assertDatabaseHas('tenant_memberships', ['tenant_id' => $this->tenant->id, 'user_id' => $existing->id, 'status' => 'active']);
    }

    // ------------------------------------------------------------------ serangan

    public function test_another_sso_account_cannot_redeem_someone_elses_invitation(): void
    {
        $invitation = $this->boundInvitation();
        $this->claimOverrides = ['sub' => 'subjek-penyerang'];

        $this->completeCeremony($invitation)->assertRedirect('/join?sso_error=undangan-untuk-akun-lain');

        $this->assertGuest();
        $this->assertSame(1, User::query()->count());
        $this->assertNull($invitation->refresh()->sso_redeemed_at);
        $this->assertDatabaseHas('access_audit_events', ['action' => 'access.invitation.sso.subjek_tidak_cocok']);
    }

    /**
     * Email yang sama, subjek berbeda — dan tetap ditolak.
     *
     * Inilah alasan seluruh rancangan ini mengikat subjek: penyedia menulis `email_verified_at`
     * tanpa memverifikasi, jadi siapa pun dapat mendaftar di sana dengan email orang lain.
     */
    public function test_an_account_with_the_invited_email_but_another_subject_is_refused(): void
    {
        $invitation = $this->boundInvitation();
        $this->claimOverrides = ['sub' => 'subjek-penyerang', 'email' => 'dewi@klinik.test', 'email_verified' => true];

        $this->completeCeremony($invitation)->assertRedirect('/join?sso_error=undangan-untuk-akun-lain');

        $this->assertGuest();
        $this->assertSame(0, ExternalIdentity::query()->count());
        $this->assertSame(1, User::query()->count());
    }

    public function test_a_bound_invitation_cannot_be_redeemed_with_a_password(): void
    {
        $invitation = $this->boundInvitation();
        $code = (string) $invitation->accessibleCode();

        $this->post('http://tenanta.contoh.co.id/join', [
            'code' => $code,
            'name' => 'Penyerang',
            'email' => 'penyerang@klinik.test',
            'password' => 'KataSandi#2026',
            'password_confirmation' => 'KataSandi#2026',
        ])->assertSessionHasErrors('code');

        $this->assertGuest();
        $this->assertSame(1, User::query()->count());
        $this->assertNull($invitation->refresh()->sso_redeemed_at);
    }

    public function test_a_bound_invitation_cannot_be_redeemed_by_a_signed_in_account_either(): void
    {
        $invitation = $this->boundInvitation();
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->post('http://tenanta.contoh.co.id/join', ['code' => (string) $invitation->accessibleCode()])
            ->assertSessionHasErrors('code');

        $this->assertDatabaseMissing('tenant_memberships', ['tenant_id' => $this->tenant->id, 'user_id' => $outsider->id]);
    }

    public function test_an_invitation_is_spent_once(): void
    {
        $invitation = $this->boundInvitation();
        $this->completeCeremony($invitation)->assertRedirect('/dashboard');

        auth()->logout();
        $this->completeCeremony($invitation)->assertRedirect('/join?sso_error=undangan-tidak-berlaku');

        $this->assertSame(1, TenantMembership::query()->where('tenant_id', $this->tenant->id)->where('system_role', 'user')->count());
    }

    public function test_an_invitation_revoked_while_the_ceremony_is_away_changes_nothing(): void
    {
        $invitation = $this->boundInvitation();

        [$start, $state] = $this->startJoin($invitation);
        $handoff = (string) $this->get('http://contoh.co.id/sso/callback?code=kode-uji&state='.$state)->headers->get('Location');

        // Operator mencabutnya selama sepuluh menit upacara berjalan.
        $invitation->update(['revoked_at' => now()]);

        $this->withCookie(SsoLoginController::ATTEMPT_COOKIE, $this->browserSecret($start))
            ->get($handoff)
            ->assertRedirect('/join?sso_error=undangan-tidak-berlaku');

        $this->assertGuest();
        $this->assertSame(1, User::query()->count());
        $this->assertNull($invitation->refresh()->sso_redeemed_at);
    }

    public function test_a_handoff_without_the_starting_browser_is_refused(): void
    {
        $invitation = $this->boundInvitation();

        [, $state] = $this->startJoin($invitation);
        $handoff = (string) $this->get('http://contoh.co.id/sso/callback?code=kode-uji&state='.$state)->headers->get('Location');

        $this->withCookie(SsoLoginController::ATTEMPT_COOKIE, 'rahasia-peramban-lain')
            ->get($handoff)
            ->assertRedirect('/join?sso_error=kedaluwarsa');

        $this->assertGuest();
        $this->assertSame(1, User::query()->count());
    }

    public function test_a_code_from_another_tenant_starts_nothing(): void
    {
        $invitation = $this->boundInvitation();

        $other = $this->tenantWithProduction('tenantb');
        TenantIdentityProvider::create(['tenant_id' => $other->id, 'mode' => 'bersama', 'protokol' => 'oidc', 'aktif' => true]);

        $this->post('http://tenantb.contoh.co.id/sso/gabung', ['code' => (string) $invitation->accessibleCode()])
            ->assertRedirect('/join?sso_error=undangan-tidak-berlaku');

        $this->assertSame(0, SsoLoginAttempt::query()->count());
    }

    public function test_an_anonymous_code_cannot_be_used_to_start_the_sso_ceremony(): void
    {
        $this->createInvitation(email: null);
        auth()->logout();
        session()->flush();
        $invitation = InvitationCode::query()->sole();

        $this->post('http://tenanta.contoh.co.id/sso/gabung', ['code' => (string) $invitation->accessibleCode()])
            ->assertRedirect('/join?sso_error=undangan-tidak-berlaku');

        $this->assertSame(0, SsoLoginAttempt::query()->count());
    }

    public function test_an_invited_subject_that_is_already_a_member_is_told_so(): void
    {
        $invitation = $this->boundInvitation();

        // Diundang, lalu di sela-selanya orangnya sudah menjadi anggota lewat jalan lain.
        $member = $this->member('dewi@klinik.test');
        ExternalIdentity::create(['user_id' => $member->id, 'issuer' => self::ISSUER, 'subject' => '4242']);

        $this->completeCeremony($invitation)->assertRedirect('/join?sso_error=sudah-menjadi-anggota');

        $this->assertGuest();
        $this->assertNull($invitation->refresh()->sso_redeemed_at);
    }

    public function test_an_email_that_belongs_to_an_unlinked_account_is_refused(): void
    {
        $invitation = $this->boundInvitation();
        // Akun CoreERP dengan email yang sama, tetapi belum pernah menghubungkan SSO.
        User::factory()->create(['email' => 'dewi@klinik.test']);

        $this->completeCeremony($invitation)->assertRedirect('/join?sso_error=email-sudah-punya-akun');

        $this->assertGuest();
        $this->assertSame(0, ExternalIdentity::query()->count());
    }

    // ------------------------------------------------------------------ halaman tukar

    public function test_the_join_page_offers_sso_for_a_bound_invitation_and_a_form_otherwise(): void
    {
        $invitation = $this->boundInvitation();

        $this->get('http://tenanta.contoh.co.id/join?kode='.$invitation->accessibleCode())
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('mode', 'sso')
                ->where('invitedName', 'Dewi Perawat')
                ->where('tenantName', $this->tenant->name));

        $this->get('http://tenanta.contoh.co.id/join')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('mode', 'kata-sandi')->where('code', null));
    }

    /** Tautan di email mendarat di domain dasar, lalu dialihkan ke alamat tenant. */
    public function test_the_landing_link_sends_the_browser_to_the_tenant_address(): void
    {
        $invitation = $this->boundInvitation();
        $code = (string) $invitation->accessibleCode();

        $this->get('http://contoh.co.id/undangan?kode='.$code)
            ->assertRedirect('http://tenanta.contoh.co.id/join?kode='.urlencode($code));

        $this->get('http://contoh.co.id/undangan?kode=BUKAN-KODE-APA-PUN')
            ->assertRedirect('/join?sso_error=undangan-tidak-berlaku');
    }

    // ------------------------------------------------------------------ pembantu

    private function createInvitation(?string $email = 'dewi@klinik.test'): TestResponse
    {
        return $this->actingAs($this->operator)->post('http://tenanta.contoh.co.id/settings/access/invitations', [
            'system_role' => 'user',
            'label' => 'Perawat baru',
            'assignments' => [],
            'sso_email' => $email,
        ]);
    }

    private function boundInvitation(): InvitationCode
    {
        $this->createInvitation()->assertSessionHasNoErrors();

        // Undangan dibuat operator di peramban miliknya; yang menukarkannya orang lain, sebagai
        // tamu. Tanpa keluar di sini, `guest` pada rute `/sso/gabung` mengalihkan ke dasbor dan
        // upacaranya tidak pernah lahir — kegagalan yang terbaca seperti cacat produk, padahal
        // test-nya yang salah memerankan orangnya.
        auth()->logout();
        session()->flush();

        return InvitationCode::query()->sole();
    }

    /** @return array{0: TestResponse, 1: string} */
    private function startJoin(InvitationCode $invitation): array
    {
        $response = $this->post('http://tenanta.contoh.co.id/sso/gabung', ['code' => (string) $invitation->accessibleCode()]);
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

        return [$response, (string) ($query['state'] ?? '')];
    }

    private function completeCeremony(InvitationCode $invitation): TestResponse
    {
        [$start, $state] = $this->startJoin($invitation);
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
            self::ISSUER.'/oauth/token' => function () {
                $attempt = SsoLoginAttempt::query()->latest('created_at')->firstOrFail();

                return Http::response(['id_token' => $this->idToken($attempt->nonce), 'token_type' => 'Bearer']);
            },
            self::API.'/users/lookup*' => fn () => Http::response(
                $this->lookupUser === null
                    ? ['success' => false, 'message' => 'Pengguna tidak ditemukan di SSO Hub.']
                    : ['success' => true, 'user' => $this->lookupUser],
                $this->lookupStatus,
            ),
            self::API.'/notifications/send-invitation' => fn () => Http::response(
                $this->sendStatus === 200 ? ['success' => true, 'data' => []] : ['message' => 'Sedang mati'],
                $this->sendStatus,
            ),
        ]);
    }

    private function idToken(string $nonce): string
    {
        $claims = array_merge([
            'iss' => self::ISSUER,
            'aud' => self::CLIENT_ID,
            'sub' => '4242',
            'email' => 'dewi@klinik.test',
            'email_verified' => true,
            'nonce' => $nonce,
            'iat' => time(),
            'exp' => time() + 3600,
        ], $this->claimOverrides);

        return JWT::encode($claims, $this->privateKey, 'RS256', 'kunci-uji');
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function makeSigningKey(string $kid): array
    {
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $key = openssl_pkey_new($options);

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

    private function member(string $email, string $role = 'user'): User
    {
        $user = User::factory()->create(['email' => $email]);
        TenantMembership::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'system_role' => $role,
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
