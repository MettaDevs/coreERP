<?php

declare(strict_types=1);

namespace App\Platform\ControlPlane\Products;

use App\Platform\ControlPlane\Models\ManagedProductTenant;
use App\Platform\ControlPlane\Models\ProductAuthAttempt;
use App\Platform\ControlPlane\Models\ProductCustomer;
use App\Platform\ControlPlane\Models\ProductCustomerSession;
use App\Platform\ControlPlane\Models\ProductEmailChallenge;
use App\Platform\Identity\Support\Sso\SsoApiUnavailable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ProductRegistration
{
    public function __construct(
        private ProductIdentityProvider $provider,
        private ProductSsoApiClient $notifications,
        private CreateProductTenant $creator,
        private ProductTenantProvisioner $provisioner,
        private ProductRegistrationSigningKey $signing,
    ) {}

    /** @return array{authorization_url: string} */
    public function start(string $browserChallenge): array
    {
        abort_unless($this->provider->isConfigured(), 503, 'Login SSO belum tersedia.');
        $state = bin2hex(random_bytes(32));
        $nonce = bin2hex(random_bytes(32));
        $verifier = bin2hex(random_bytes(32));
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $url = $this->provider->authorizationUrl($state, $nonce, $challenge, $this->callback());
        $this->connection()->transaction(function () use ($state, $nonce, $verifier, $browserChallenge): void {
            $attempt = ProductAuthAttempt::query()->create([
                'id' => (string) Str::uuid(),
                'state_hash' => hash('sha256', $state), 'browser_challenge' => $browserChallenge,
                'nonce' => $nonce, 'code_verifier' => Crypt::encryptString($verifier), 'expires_at' => now()->addMinutes(10),
            ]);
            $this->audit('product-registration.auth.start', $attempt->id);
        });

        return ['authorization_url' => $url];
    }

    /** @return array<string, mixed> */
    public function exchange(string $state, string $code, string $browserSecret): array
    {
        $attempt = $this->connection()->transaction(function () use ($state, $browserSecret): ProductAuthAttempt {
            $attempt = ProductAuthAttempt::query()->lockForUpdate()->where('state_hash', hash('sha256', $state))
                ->whereNull('consumed_at')->where('expires_at', '>', now())
                ->where('browser_challenge', hash('sha256', $browserSecret))->first();
            abort_unless($attempt !== null, 401, 'Permintaan login sudah tidak berlaku. Silakan masuk kembali.');
            $attempt->update(['consumed_at' => now()]);
            $this->audit('product-registration.auth.consume', $attempt->id);

            return $attempt;
        });
        $claims = $this->provider->exchangeCode($code, Crypt::decryptString($attempt->code_verifier), $this->callback(), $attempt->nonce);
        $subject = $claims['sub'] ?? null;
        abort_unless(is_string($subject) && strlen($subject) <= 255, 401);
        $identity = hash('sha256', json_encode([$this->provider->issuer(), $subject], JSON_THROW_ON_ERROR));
        $name = $claims['name'] ?? $claims['preferred_username'] ?? 'Pengguna';
        $name = is_string($name) ? trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name)) : '';
        $name = mb_substr($name !== '' ? $name : 'Pengguna', 0, 100);
        $email = $claims['email'] ?? null;
        $email = is_string($email) && strlen($email) <= 250 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            ? mb_strtolower(trim($email)) : null;
        $token = bin2hex(random_bytes(32));
        $customer = $this->connection()->transaction(function () use ($identity, $subject, $name, $email, $token): ProductCustomer {
            $customer = ProductCustomer::query()->firstOrCreate(['identity_hash' => $identity], [
                'id' => (string) Str::uuid(), 'issuer' => $this->provider->issuer(), 'subject' => $subject,
                'name' => $name, 'email' => $email,
            ]);
            $customer->update(['name' => $name, 'email' => $customer->verified_email ?? $email]);
            ProductCustomerSession::query()->create([
                'token_hash' => hash('sha256', $token), 'customer_id' => $customer->id,
                'expires_at' => now()->addHours(24),
            ]);
            $this->audit('product-registration.auth.login', $customer->id);

            return $customer;
        });

        return ['session_token' => $token, 'session' => $this->sessionData($customer)];
    }

    public function customer(?string $token): ?ProductCustomer
    {
        if ($token === null || strlen($token) < 32 || strlen($token) > 128) {
            return null;
        }
        $session = ProductCustomerSession::query()->where('token_hash', hash('sha256', $token))
            ->whereNull('revoked_at')->where('expires_at', '>', now())->first();

        return $session === null ? null : ProductCustomer::query()->find($session->customer_id);
    }

    /** @return array<string, mixed> */
    public function sessionData(?ProductCustomer $customer): array
    {
        $products = [];
        foreach (config('managed_products.products', []) as $code => $definition) {
            $profiles = [];
            foreach ($definition['profiles'] as $profile => $description) {
                $profiles[] = array_merge(['code' => $profile], $description);
            }
            $products[] = ['code' => $code, 'name' => $definition['name'], 'profiles' => $profiles, 'trial_days' => $this->trialDays($code)];
        }
        $tenant = $customer === null ? null : ManagedProductTenant::query()->where('owner_customer_id', $customer->id)->latest()->first();

        return [
            'authenticated' => $customer !== null,
            'customer' => $customer === null ? null : [
                'id' => $customer->id, 'name' => $customer->name, 'email' => $customer->verified_email ?? $customer->email,
                'email_verified' => $customer->email_verified_at !== null && $customer->verified_email !== null,
            ],
            'products' => $products,
            'tenant' => $tenant?->only(['id', 'product', 'profile', 'name', 'slug', 'status', 'public_url', 'failure_message', 'expires_at']),
        ];
    }

    /** @return array{sent: bool, expires_at: string} */
    public function sendEmailChallenge(ProductCustomer $customer, string $email): array
    {
        $email = mb_strtolower(trim($email));
        abort_unless($this->notifications->isConfigured(), 503, 'Email verifikasi belum tersedia.');
        abort_if($customer->verified_email !== null && $customer->verified_email !== $email, 422, 'Email trial yang sudah diverifikasi tidak dapat diganti melalui pendaftaran. Hubungi pengelola.');
        $key = 'product-registration.email.'.hash('sha256', $email);
        $customerKey = 'product-registration.email.customer.'.$customer->id;
        abort_if(RateLimiter::tooManyAttempts($key, 3) || RateLimiter::tooManyAttempts($customerKey, 3), 429, 'Tunggu sebelum meminta email verifikasi lagi.');
        RateLimiter::hit($key, 3600);
        RateLimiter::hit($customerKey, 3600);
        $token = bin2hex(random_bytes(32));
        $expiresAt = now()->addDay();
        $challenge = $this->connection()->transaction(function () use ($customer, $email, $token, $expiresAt): ProductEmailChallenge {
            $current = ProductCustomer::query()->lockForUpdate()->findOrFail($customer->id);
            abort_if($current->verified_email !== null && $current->verified_email !== $email, 422, 'Email trial yang sudah diverifikasi tidak dapat diganti melalui pendaftaran.');
            ProductEmailChallenge::query()->where('customer_id', $customer->id)->whereNull('consumed_at')->update(['consumed_at' => now()]);
            $challenge = ProductEmailChallenge::query()->create([
                'token_hash' => hash('sha256', $token), 'customer_id' => $customer->id, 'email' => $email, 'expires_at' => $expiresAt,
            ]);
            $this->audit('product-registration.email.request', $customer->id);

            return $challenge;
        });
        try {
            $reply = $this->notifications->post('/notifications/send-invitation', [
                'recipient_email' => $email, 'recipient_name' => $customer->name,
                'app_name' => (string) config('managed_products.registration.name', 'TrialFaskes'),
                'role_name' => 'Verifikasi email trial',
                'accept_url' => rtrim((string) config('managed_products.registration.public_origin'), '/').'/api/trials/verify?token='.rawurlencode($token),
                'expires_in_days' => 1,
            ]);
            if (! $reply->successful()) {
                throw new SsoApiUnavailable('Pengiriman email verifikasi ditolak.');
            }
        } catch (SsoApiUnavailable $failure) {
            $this->connection()->transaction(function () use ($customer, $challenge): void {
                $challenge->update(['consumed_at' => now()]);
                $this->audit('product-registration.email.delivery-failed', $customer->id);
            });
            throw ValidationException::withMessages(['email' => 'Email verifikasi belum dapat dikirim. Silakan coba lagi nanti.']);
        }

        return ['sent' => true, 'expires_at' => $expiresAt->toIso8601String()];
    }

    public function verifyEmail(ProductCustomer $customer, string $token): void
    {
        try {
            $this->connection()->transaction(function () use ($customer, $token): void {
                $customer = ProductCustomer::query()->lockForUpdate()->findOrFail($customer->id);
                $challenge = ProductEmailChallenge::query()->lockForUpdate()->where('token_hash', hash('sha256', $token))
                    ->where('customer_id', $customer->id)->whereNull('consumed_at')->where('expires_at', '>', now())->first();
                abort_unless($challenge !== null, 422, 'Tautan verifikasi sudah tidak berlaku. Minta email baru.');
                abort_if($customer->verified_email !== null && $customer->verified_email !== $challenge->email, 422, 'Email trial yang sudah diverifikasi tidak dapat diganti melalui pendaftaran.');
                $challenge->update(['consumed_at' => now()]);
                $customer->update(['email' => $challenge->email, 'verified_email' => $challenge->email, 'email_verified_at' => now()]);
                $this->audit('product-registration.email.verify', $customer->id);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'Email ini sudah terhubung dengan akun trial lain. Gunakan akun asal atau hubungi pengelola.']);
        }
    }

    /** @param array<string, mixed> $input */
    public function createTenant(ProductCustomer $customer, array $input): ManagedProductTenant
    {
        $input['owner_name'] = $customer->name;
        $input['owner_email'] = $customer->verified_email;
        $input['expires_at'] = now()->addDays($this->trialDays($input['product']))->toIso8601String();
        $existing = ManagedProductTenant::query()->where('idempotency_key', $input['idempotency_key'])->where('owner_customer_id', $customer->id)->first();
        if ($existing !== null) {
            $input['owner_name'] = $existing->owner_name;
            $input['owner_email'] = $existing->owner_email;
            $input['expires_at'] = $existing->expires_at?->toIso8601String();
        }
        $tenant = $this->creator->handle($input, null, $customer);
        $this->provisioner->reconcile($tenant->id);

        return $tenant->fresh();
    }

    /** @return array{ticket: string, public_url: string} */
    public function launch(ProductCustomer $customer, string $tenantId, string $browserChallenge, string $state): array
    {
        $tenant = ManagedProductTenant::query()->where('owner_customer_id', $customer->id)->findOrFail($tenantId);
        abort_unless($tenant->status === 'ready', 409, 'Aplikasi masih disiapkan.');
        abort_if($tenant->expires_at !== null && $tenant->expires_at->isPast(), 410, 'Masa trial sudah berakhir. Hubungi pengelola untuk melanjutkan.');
        $url = 'https://'.$tenant->slug.'.'.$tenant->release['base_domain'].'/';
        abort_unless($tenant->public_url === $url, 503);
        $username = (string) config('managed_products.products.'.$tenant->product.'.owner_username', 'owner');
        abort_if(strcasecmp($username, 'metta') === 0, 503);
        $now = now()->getTimestamp();
        $ticket = $this->signing->sign([
            'iss' => $this->signing->issuer(), 'aud' => $tenant->id, 'sub' => $customer->id,
            'username' => $username, 'iat' => $now, 'exp' => $now + 60, 'jti' => (string) Str::uuid(),
            'browser_challenge' => $browserChallenge, 'state' => $state,
        ]);
        $this->connection()->transaction(fn () => $this->audit('product-registration.launch', $tenant->id, ['customer_id' => $customer->id]));

        return ['ticket' => $ticket, 'public_url' => $url];
    }

    public function logout(string $token): void
    {
        $this->connection()->transaction(function () use ($token): void {
            $session = ProductCustomerSession::query()->where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->lockForUpdate()->first();
            if ($session !== null) {
                $session->update(['revoked_at' => now()]);
                $this->audit('product-registration.logout', $session->customer_id);
            }
        });
    }

    public function backchannelLogout(string $token): void
    {
        $claims = $this->provider->verifyLogoutToken($token);
        $identity = hash('sha256', json_encode([$this->provider->issuer(), $claims['sub']], JSON_THROW_ON_ERROR));
        $tenants = $this->connection()->transaction(function () use ($identity): array {
            $customer = ProductCustomer::query()->where('identity_hash', $identity)->first();
            if ($customer !== null) {
                ProductCustomerSession::query()->where('customer_id', $customer->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
                $pending = ['customer_id' => $customer->id, 'revoked_before' => now()->getTimestamp()];
                $tenants = ManagedProductTenant::query()->where('owner_customer_id', $customer->id)->lockForUpdate()->get();
                foreach ($tenants as $tenant) {
                    $tenant->update(['pending_sso_revocation' => $pending]);
                    $this->provisioner->audit($tenant, 'product-tenant.sso-revocation.requested', null, $pending);
                }
                $this->audit('product-registration.backchannel-logout', $customer->id);

                return $tenants->modelKeys();
            }

            return [];
        });
        foreach ($tenants as $id) {
            $this->provisioner->reconcileSsoRevocation((string) $id);
        }
    }

    public function trialDays(string $product): int
    {
        return max(1, min(90, (int) config('managed_products.products.'.$product.'.trial_days', 14)));
    }

    private function callback(): string
    {
        return rtrim((string) config('managed_products.registration.public_origin'), '/').'/api/trials/callback';
    }

    private function connection(): ConnectionInterface
    {
        return (new ProductCustomer)->getConnection();
    }

    /** @param array<string, mixed> $details */
    private function audit(string $action, string $subject, array $details = []): void
    {
        $this->connection()->table('operator_audit_events')->insert([
            'id' => (string) Str::ulid(), 'user_id' => null, 'action' => $action,
            'subject_type' => 'product_registration', 'subject_id' => $subject,
            'detail' => json_encode($details, JSON_THROW_ON_ERROR), 'occurred_at' => now(),
        ]);
    }
}
