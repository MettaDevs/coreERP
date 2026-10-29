<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\ControlPlane;

use App\Platform\ControlPlane\Models\ManagedProductTenant;
use App\Platform\ControlPlane\Models\ProductAuthAttempt;
use App\Platform\ControlPlane\Models\ProductCustomer;
use App\Platform\ControlPlane\Models\ProductCustomerSession;
use App\Platform\ControlPlane\Models\ProductEmailChallenge;
use App\Platform\ControlPlane\Products\ProductRegistrationSigningKey;
use App\Platform\ControlPlane\Products\ProductTenantProvisioner;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ProductRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private const PREFIX = '/api/internal/v1/product-registration/';

    private string $privateKey = '';

    private array $jwk;

    private string $subject = 'customer-one';

    private string $tokenEmail = 'unproven@example.test';

    private string $tokenAudience = 'product-client';

    private ?string $emailUrl = null;

    private bool $mailFails = false;

    private bool $workerReady = false;

    private bool $revocationFails = false;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'managed_products.registration.gateway_token' => str_repeat('g', 40),
            'managed_products.registration.public_origin' => 'https://landing.example.test',
            'managed_products.registration.sso.issuer' => 'https://identity.example.test',
            'managed_products.registration.sso.client_id' => 'product-client',
            'managed_products.registration.sso.client_secret' => 'product-secret',
            'managed_products.products.faskes.worker_url' => 'http://worker.test',
            'managed_products.products.faskes.worker_token' => 'worker-secret',
        ]);
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        openssl_pkey_export($key, $this->privateKey);
        $details = openssl_pkey_get_details($key);
        $this->jwk = ['kty' => 'RSA', 'kid' => 'provider-key', 'use' => 'sig', 'alg' => 'RS256',
            'n' => $this->base64Url($details['rsa']['n']), 'e' => $this->base64Url($details['rsa']['e'])];
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            return match ($request->url()) {
                'https://identity.example.test/.well-known/openid-configuration' => Http::response([
                    'issuer' => 'https://identity.example.test', 'authorization_endpoint' => 'https://identity.example.test/authorize',
                    'token_endpoint' => 'https://identity.example.test/token', 'jwks_uri' => 'https://identity.example.test/jwks',
                ]),
                'https://identity.example.test/jwks' => Http::response(['keys' => [$this->jwk]]),
                'https://identity.example.test/token' => Http::response(['id_token' => JWT::encode([
                    'iss' => 'https://identity.example.test', 'aud' => $this->tokenAudience, 'sub' => $this->subject,
                    'name' => 'Customer Test', 'email' => $this->tokenEmail, 'email_verified' => true,
                    'iat' => time(), 'exp' => time() + 300,
                ], $this->privateKey, 'RS256', 'provider-key')]),
                'https://identity.example.test/api/v1/notifications/send-invitation' => $this->mailReply($request['accept_url']),
                default => $this->workerReply($request),
            };
        });
    }

    public function test_gateway_is_separate_and_anonymous_catalog_never_contains_secrets(): void
    {
        config(['coreerp.control_plane_token' => 'operator-token']);
        $this->withToken('operator-token')->getJson(self::PREFIX.'session')->assertUnauthorized();
        $reply = $this->gateway()->getJson(self::PREFIX.'session')->assertOk()
            ->assertJsonPath('authenticated', false)->assertJsonPath('customer', null)->assertJsonPath('tenant', null);
        $this->assertCount(3, $reply->json('products.0.profiles'));
        $this->assertStringNotContainsString('worker-secret', $reply->getContent());
        $this->assertStringNotContainsString('product-secret', $reply->getContent());
        $this->assertStringContainsString('no-store', (string) $reply->headers->get('Cache-Control'));
    }

    public function test_login_is_browser_bound_once_and_does_not_trust_provider_verified_email(): void
    {
        $secret = bin2hex(random_bytes(32));
        $reply = $this->gateway()->postJson(self::PREFIX.'auth/start', ['browser_challenge' => hash('sha256', $secret)])->assertOk();
        parse_str(parse_url($reply->json('authorization_url'), PHP_URL_QUERY), $query);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame('https://landing.example.test/api/trials/callback', $query['redirect_uri']);
        $this->gateway()->postJson(self::PREFIX.'auth/exchange', ['state' => $query['state'], 'code' => 'one', 'browser_secret' => str_repeat('x', 64)])->assertUnauthorized();
        Http::assertNotSent(fn ($request) => $request->url() === 'https://identity.example.test/token');
        $reply = $this->gateway()->postJson(self::PREFIX.'auth/exchange', ['state' => $query['state'], 'code' => 'one', 'browser_secret' => $secret])->assertOk()
            ->assertJsonPath('session.customer.email_verified', false);
        $token = $reply->json('session_token');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('clients', 0);
        $this->assertDatabaseCount('product_customers', 1);
        $this->assertSame(hash('sha256', $token), ProductCustomerSession::query()->value('token_hash'));
        $this->gateway()->postJson(self::PREFIX.'auth/exchange', ['state' => $query['state'], 'code' => 'one', 'browser_secret' => $secret])->assertUnauthorized();
        $this->customer($token)->postJson(self::PREFIX.'tenants', $this->tenantInput())->assertUnprocessable();
        $this->assertDatabaseCount('managed_product_tenants', 0);
        $this->assertNotNull(ProductAuthAttempt::query()->first()->consumed_at);
    }

    public function test_other_client_id_token_is_rejected_before_customer_is_created(): void
    {
        $this->tokenAudience = 'operator-client';
        $secret = bin2hex(random_bytes(32));
        $reply = $this->gateway()->postJson(self::PREFIX.'auth/start', ['browser_challenge' => hash('sha256', $secret)])->assertOk();
        parse_str(parse_url($reply->json('authorization_url'), PHP_URL_QUERY), $query);
        $this->gateway()->postJson(self::PREFIX.'auth/exchange', ['state' => $query['state'], 'code' => 'one', 'browser_secret' => $secret])->assertUnauthorized();
        $this->assertDatabaseCount('product_customers', 0);
    }

    public function test_email_proof_is_bound_to_subject_once_and_cannot_be_reassigned(): void
    {
        $first = $this->login('one');
        $this->customer($first)->postJson(self::PREFIX.'email-challenge', ['email' => 'owner@example.test'])->assertOk();
        $proof = $this->emailProof();
        $second = $this->login('two');
        $this->customer($second)->postJson(self::PREFIX.'email-verification', ['token' => $proof])->assertUnprocessable();
        $this->customer($first)->postJson(self::PREFIX.'email-verification', ['token' => $proof])->assertOk()->assertJsonPath('customer.email_verified', true);
        $this->customer($first)->postJson(self::PREFIX.'email-verification', ['token' => $proof])->assertUnprocessable();
        $this->customer($first)->postJson(self::PREFIX.'email-challenge', ['email' => 'new@example.test'])->assertUnprocessable();
        $this->customer($second)->postJson(self::PREFIX.'email-challenge', ['email' => 'owner@example.test'])->assertOk();
        $this->customer($second)->postJson(self::PREFIX.'email-verification', ['token' => $this->emailProof()])->assertUnprocessable();
        $this->assertSame(1, ProductCustomer::query()->whereNotNull('email_verified_at')->count());
        $this->assertDatabaseCount('users', 0);
    }

    public function test_failed_email_delivery_does_not_verify_and_can_be_retried(): void
    {
        $token = $this->login();
        $this->mailFails = true;
        $this->customer($token)->postJson(self::PREFIX.'email-challenge', ['email' => 'owner@example.test'])->assertUnprocessable();
        $failedProof = $this->emailProof();
        $this->assertNotNull(ProductEmailChallenge::query()->first()->consumed_at);
        $this->assertNull(ProductCustomer::query()->first()->email_verified_at);
        $this->mailFails = false;
        $this->customer($token)->postJson(self::PREFIX.'email-challenge', ['email' => 'owner@example.test'])->assertOk();
        $this->customer($token)->postJson(self::PREFIX.'email-verification', ['token' => $failedProof])->assertUnprocessable();
        $this->customer($token)->postJson(self::PREFIX.'email-verification', ['token' => $this->emailProof()])->assertOk();
    }

    public function test_shared_provisioning_is_idempotent_and_customer_never_receives_credentials(): void
    {
        $token = $this->verifiedLogin();
        $input = $this->tenantInput();
        $reply = $this->customer($token)->postJson(self::PREFIX.'tenants', $input)->assertAccepted();
        $id = $reply->json('id');
        $this->assertNull(ManagedProductTenant::query()->findOrFail($id)->requested_by);
        $this->assertNotNull(ManagedProductTenant::query()->findOrFail($id)->owner_customer_id);
        $expires = ManagedProductTenant::query()->findOrFail($id)->expires_at;
        $this->assertTrue($expires->between(now()->addDays(13), now()->addDays(15)));
        $this->travel(2)->minutes();
        $this->customer($token)->postJson(self::PREFIX.'tenants', $input)->assertAccepted()->assertJsonPath('id', $id);
        $this->customer($token)->postJson(self::PREFIX.'tenants', array_merge($input, ['idempotency_key' => (string) Str::uuid(), 'slug' => 'another']))->assertConflict();
        $this->assertDatabaseCount('managed_product_tenants', 1);
        $this->workerReady = true;
        app(ProductTenantProvisioner::class)->reconcile($id);
        $reply = $this->customer($token)->getJson(self::PREFIX.'session')->assertOk()->assertJsonPath('tenant.status', 'ready');
        $this->assertStringNotContainsString('owner-password', $reply->getContent());
        $this->assertStringNotContainsString('metta-password', $reply->getContent());
        $this->assertStringNotContainsString('credentials', $reply->getContent());
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('clients', 0);
    }

    public function test_browser_cannot_supply_privileged_owner_or_lifecycle_fields(): void
    {
        $token = $this->verifiedLogin();
        foreach (['requested_by', 'owner_customer_id', 'owner_email', 'owner_name', 'expires_at', 'release'] as $field) {
            $this->customer($token)->postJson(self::PREFIX.'tenants', array_merge($this->tenantInput(), [$field => 'forged']))
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertDatabaseCount('managed_product_tenants', 0);
    }

    public function test_public_trial_cap_does_not_count_or_block_operator_provisioning(): void
    {
        config(['managed_products.products.faskes.public_trial_limit' => 1, 'coreerp.control_plane_token' => 'operator-test-token']);
        $operator = DB::table('users')->insertGetId(['name' => 'Operator', 'email' => 'operator@example.test', 'password' => bcrypt('test'), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('provider_access')->insert(['user_id' => $operator, 'role' => 'provider_admin', 'created_at' => now(), 'updated_at' => now()]);
        $input = array_merge($this->tenantInput(), ['slug' => 'operator-first', 'owner_name' => 'Operator Owner', 'owner_email' => 'operator-owner@example.test', 'requested_by' => $operator]);
        $this->withToken('operator-test-token')->postJson('/api/internal/v1/product-tenants', $input)->assertAccepted();
        $token = $this->verifiedLogin();
        $this->customer($token)->postJson(self::PREFIX.'tenants', $this->tenantInput())->assertAccepted();
        $other = $this->login('another-customer');
        $this->customer($other)->postJson(self::PREFIX.'email-challenge', ['email' => 'other-owner@example.test'])->assertOk();
        $this->customer($other)->postJson(self::PREFIX.'email-verification', ['token' => $this->emailProof()])->assertOk();
        $this->customer($other)->postJson(self::PREFIX.'tenants', array_merge($this->tenantInput(), ['slug' => 'other-apotek']))->assertStatus(429);
        $input['slug'] = 'operator-after-full';
        $input['idempotency_key'] = (string) Str::uuid();
        $this->withToken('operator-test-token')->postJson('/api/internal/v1/product-tenants', $input)->assertAccepted();
        $this->assertSame(1, ManagedProductTenant::query()->whereNotNull('owner_customer_id')->count());
        $this->assertDatabaseCount('managed_product_tenants', 3);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_launch_ticket_is_signed_for_owner_and_cross_customer_or_expired_trial_is_denied(): void
    {
        $token = $this->verifiedLogin();
        $id = $this->customer($token)->postJson(self::PREFIX.'tenants', $this->tenantInput())->assertAccepted()->json('id');
        $this->workerReady = true;
        app(ProductTenantProvisioner::class)->reconcile($id);
        $public = app(ProductRegistrationSigningKey::class)->publicData(true);
        $input = ['tenant_id' => $id, 'browser_challenge' => str_repeat('a', 64), 'state' => str_repeat('s', 40)];
        $reply = $this->customer($token)->postJson(self::PREFIX.'launch', $input)->assertOk();
        $claims = JWT::decode($reply->json('ticket'), new Key(base64_decode($public['public_key_b64']), 'RS256'));
        $this->assertSame($id, $claims->aud);
        $this->assertSame('owner', $claims->username);
        $this->assertSame($input['browser_challenge'], $claims->browser_challenge);
        $this->assertSame($input['state'], $claims->state);
        $this->assertLessThanOrEqual(60, $claims->exp - $claims->iat);
        $other = $this->login('other');
        $this->customer($other)->postJson(self::PREFIX.'launch', $input)->assertNotFound();
        ManagedProductTenant::query()->findOrFail($id)->update(['expires_at' => now()->subSecond()]);
        $this->customer($token)->postJson(self::PREFIX.'launch', $input)->assertStatus(410);
    }

    public function test_logout_and_valid_provider_backchannel_revoke_sessions(): void
    {
        $token = $this->login();
        $this->customer($token)->postJson(self::PREFIX.'logout')->assertOk();
        $this->customer($token)->getJson(self::PREFIX.'session')->assertOk()->assertJsonPath('authenticated', false);
        $token = $this->login();
        $logout = JWT::encode([
            'iss' => 'https://identity.example.test', 'aud' => 'product-client', 'sub' => $this->subject,
            'iat' => time(), 'exp' => time() + 60, 'jti' => 'provider-logout-once',
            'events' => ['http://schemas.openid.net/event/backchannel-logout' => (object) []],
        ], $this->privateKey, 'RS256', 'provider-key');
        $this->gateway()->postJson(self::PREFIX.'backchannel-logout', ['logout_token' => $logout])->assertOk();
        $this->customer($token)->getJson(self::PREFIX.'session')->assertOk()->assertJsonPath('authenticated', false);
        $this->gateway()->postJson(self::PREFIX.'backchannel-logout', ['logout_token' => $logout])->assertUnauthorized();
    }

    public function test_tenant_session_revocation_is_retained_and_retried_even_when_tenant_is_ready(): void
    {
        $token = $this->verifiedLogin();
        $id = $this->customer($token)->postJson(self::PREFIX.'tenants', $this->tenantInput())->assertAccepted()->json('id');
        $this->workerReady = true;
        app(ProductTenantProvisioner::class)->reconcile($id);
        $this->revocationFails = true;
        $logout = JWT::encode([
            'iss' => 'https://identity.example.test', 'aud' => 'product-client', 'sub' => $this->subject,
            'iat' => time(), 'exp' => time() + 60, 'jti' => 'provider-tenant-logout-once',
            'events' => ['http://schemas.openid.net/event/backchannel-logout' => (object) []],
        ], $this->privateKey, 'RS256', 'provider-key');
        $this->gateway()->postJson(self::PREFIX.'backchannel-logout', ['logout_token' => $logout])->assertOk();
        $tenant = ManagedProductTenant::query()->findOrFail($id);
        $this->assertSame('ready', $tenant->status);
        $this->assertSame($tenant->owner_customer_id, $tenant->pending_sso_revocation['customer_id']);
        $this->customer($token)->getJson(self::PREFIX.'session')->assertJsonPath('authenticated', false);
        $this->revocationFails = false;
        $this->artisan('product-tenants:reconcile')->assertExitCode(0);
        $this->assertNull($tenant->fresh()->pending_sso_revocation);
        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && $request->url() === 'http://worker.test/tenants/'.$id.'/sso-revocation'
            && $request['customer_id'] === $tenant->owner_customer_id);
    }

    private function gateway(): self
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.str_repeat('g', 40), 'X-Registration-Session' => '']);
    }

    private function customer(string $token): self
    {
        return $this->gateway()->withHeader('X-Registration-Session', $token);
    }

    private function login(string $subject = 'customer-one'): string
    {
        $this->subject = $subject;
        $secret = bin2hex(random_bytes(32));
        $start = $this->gateway()->postJson(self::PREFIX.'auth/start', ['browser_challenge' => hash('sha256', $secret)])->assertOk();
        parse_str(parse_url($start->json('authorization_url'), PHP_URL_QUERY), $query);

        return $this->gateway()->postJson(self::PREFIX.'auth/exchange', ['state' => $query['state'], 'code' => 'valid', 'browser_secret' => $secret])->assertOk()->json('session_token');
    }

    private function verifiedLogin(): string
    {
        $token = $this->login();
        $this->customer($token)->postJson(self::PREFIX.'email-challenge', ['email' => 'owner@example.test'])->assertOk();
        $this->customer($token)->postJson(self::PREFIX.'email-verification', ['token' => $this->emailProof()])->assertOk();

        return $token;
    }

    private function emailProof(): string
    {
        parse_str(parse_url($this->emailUrl, PHP_URL_QUERY), $query);

        return $query['token'];
    }

    private function mailReply(string $url): PromiseInterface
    {
        $this->emailUrl = $url;

        return Http::response(['sent' => ! $this->mailFails], $this->mailFails ? 500 : 202);
    }

    private function workerReply(Request $request): PromiseInterface
    {
        if (! str_starts_with($request->url(), 'http://worker.test/tenants/')) {
            return Http::response([], 404);
        }
        if (str_ends_with($request->url(), '/sso-revocation')) {
            return Http::response(['ok' => ! $this->revocationFails], $this->revocationFails ? 503 : 200);
        }
        if ($request->method() === 'PUT' || ! $this->workerReady) {
            return Http::response(['status' => 'provisioning'], 202);
        }
        $id = basename($request->url());
        $tenant = ManagedProductTenant::query()->findOrFail($id);

        return Http::response(['status' => 'ready', 'public_url' => 'https://'.$tenant->slug.'.'.$tenant->release['base_domain'].'/',
            'credentials' => ['username' => 'owner', 'password' => 'owner-password', 'operator_username' => 'metta', 'operator_password' => 'metta-password']]);
    }

    private function tenantInput(): array
    {
        return ['product' => 'faskes', 'profile' => 'apotek', 'name' => 'Apotek Customer', 'slug' => 'customer-apotek', 'idempotency_key' => (string) Str::uuid()];
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
