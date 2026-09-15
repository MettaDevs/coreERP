<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Feature\Sites;

use ControlPlane\Models\Site;
use ControlPlane\Models\SiteEnrollmentToken;
use ControlPlane\Models\User;
use ControlPlane\Sites\SignedAgentRequest;
use ControlPlane\Tests\CoreSchema;
use ControlPlane\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Perkakas bersama untuk test situs: operator, tenant, situs, kunci RSA, dan permintaan agen yang
 * ditandatangani.
 *
 * Tanda tangannya disusun dengan `SignedAgentRequest::signatureBase()` — fungsi yang sama yang dipakai
 * pemeriksanya. Satu fungsi untuk kedua sisi berarti test tidak dapat hijau karena menyepakati salinan
 * aturan yang keliru; kesepakatan dengan agen bash dibuktikan terpisah oleh test agennya sendiri.
 */
abstract class SiteTestCase extends TestCase
{
    use CoreSchema;

    /** @var array<int, array{private: string, public: string}> */
    private static array $keys = [];

    protected function operator(string $email = 'operator@contoh.test'): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'Operator '.$email,
            'email' => $email,
            'password' => bcrypt('rahasia'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('provider_access')->insert([
            'user_id' => $id,
            'role' => 'provider_admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::query()->findOrFail($id);
    }

    protected function tenant(string $name = 'PT Klinik Uji'): string
    {
        $clientId = (string) Str::ulid();
        DB::table('clients')->insert([
            'id' => $clientId,
            'legal_name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tenantId = (string) Str::ulid();
        DB::table('tenants')->insert([
            'id' => $tenantId,
            'client_id' => $clientId,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tenantId;
    }

    /** @param  array<string, mixed>  $attributes */
    protected function site(array $attributes = []): Site
    {
        return Site::query()->create([
            'tenant_id' => $attributes['tenant_id'] ?? $this->tenant(),
            'name' => 'Situs Uji',
            'profile' => 'managed_on_prem',
            'edition' => 'apotek-sejahtera',
            'timezone' => 'Asia/Jakarta',
            ...$attributes,
        ]);
    }

    /** @return array{private: string, public: string} */
    protected function rsaKey(int $slot = 0, int $bits = 2048): array
    {
        if (isset(self::$keys[$slot * 10000 + $bits])) {
            return self::$keys[$slot * 10000 + $bits];
        }

        $options = ['private_key_bits' => $bits, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $key = openssl_pkey_new($options);

        // PHP untuk Windows tidak menemukan `openssl.cnf`-nya sendiri. Berkasnya ikut terpasang di
        // samping binary PHP; di Linux cabang ini tidak pernah diambil.
        $bundledConfig = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';
        $extra = is_file($bundledConfig) ? ['config' => $bundledConfig] : [];

        if ($key === false && $extra !== []) {
            $key = openssl_pkey_new($options + $extra);
        }

        $this->assertNotFalse($key, 'Kunci RSA uji tidak dapat dibuat.');
        openssl_pkey_export($key, $private, null, $extra);
        $details = openssl_pkey_get_details($key);
        $this->assertIsArray($details);

        return self::$keys[$slot * 10000 + $bits] = ['private' => (string) $private, 'public' => (string) $details['key']];
    }

    /** @param  array<string, mixed>  $attributes */
    protected function enrolledSite(int $keySlot = 0, array $attributes = []): Site
    {
        return $this->site([
            'public_key' => $this->rsaKey($keySlot)['public'],
            'enrolled_at' => now(),
            ...$attributes,
        ]);
    }

    protected function enrollmentToken(Site $site, ?\DateTimeInterface $expiresAt = null): string
    {
        $token = Str::random(48);

        SiteEnrollmentToken::query()->create([
            'site_id' => $site->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => $expiresAt ?? now()->addHour(),
        ]);

        return $token;
    }

    /**
     * Permintaan agen bertanda tangan.
     *
     * @param  array<mixed>|string|null  $body
     * @param  array<string, mixed>  $tamper  pengubah untuk test penolakan: `body`, `created`, `keyid`,
     *                                        `components`, `params_suffix`, `signed_path`, `private_key`
     * @return TestResponse<Response>
     */
    protected function agent(string $method, string $path, Site $site, array|object|string|null $body = null, array $tamper = []): TestResponse
    {
        $content = is_string($body) ? $body : ($body === null ? '' : (string) json_encode($body, JSON_UNESCAPED_SLASHES));
        $digest = 'sha-256=:'.base64_encode(hash('sha256', $content, true)).':';
        $created = $tamper['created'] ?? now()->getTimestamp();
        $keyid = $tamper['keyid'] ?? $site->id;
        $components = $tamper['components'] ?? '("@method" "@path" "content-digest")';
        $params = sprintf('%s;created=%d;keyid="%s";alg="rsa-v1_5-sha256"%s', $components, $created, $keyid, $tamper['params_suffix'] ?? '');
        $base = SignedAgentRequest::signatureBase($method, $tamper['signed_path'] ?? $path, $digest, $params);
        $private = $tamper['private_key'] ?? $this->rsaKey((int) ($tamper['key_slot'] ?? 0))['private'];

        openssl_sign($base, $signature, $private, OPENSSL_ALGO_SHA256);

        $sent = array_key_exists('body', $tamper) ? (string) $tamper['body'] : $content;

        return $this->call($method, $path, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_CONTENT_DIGEST' => $digest,
            'HTTP_SIGNATURE_INPUT' => 'sig1='.$params,
            'HTTP_SIGNATURE' => 'sig1=:'.base64_encode((string) $signature).':',
        ], $sent);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function report(Site $site, array $overrides = []): array
    {
        return [
            'site_id' => $site->id,
            'agent_version' => '0.1.0',
            'created_at' => now()->toIso8601String(),
            'server_time' => now()->toIso8601String(),
            'edition' => 'apotek-sejahtera',
            'release' => '0.1.0',
            'image' => 'ghcr.io/mettadevs/coreerp/edisi-apotek-sejahtera@sha256:'.str_repeat('a', 64),
            'digest' => 'sha256:'.str_repeat('b', 64),
            'containers' => [
                ['service' => 'core-app', 'state' => 'running', 'health' => 'healthy'],
            ],
            'disk' => ['data_free_bytes' => 1000, 'backup_free_bytes' => 2000],
            'last_backup' => null,
            'last_operation' => null,
            'certificate_expires_at' => null,
            'license_expires_at' => '2027-01-01',
            ...$overrides,
        ];
    }
}
