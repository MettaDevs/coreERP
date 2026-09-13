<?php

declare(strict_types=1);

namespace App\Support\Sso;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use stdClass;
use Throwable;

/**
 * Penyedia identitas bersama: satu klien OIDC untuk semua tenant bermode `bersama`.
 *
 * ## Yang dipercaya, dan yang diperiksa sendiri
 *
 * Alamat endpoint dibaca dari dokumen penemuan penyedia, bukan ditulis di sini — tetapi `issuer`
 * di dokumen itu **wajib sama persis** dengan yang disetel. OpenID Connect Discovery §4.3 menuntut
 * itu, dan alasannya nyata: dokumen penemuan yang dipalsukan dapat menunjuk JWKS milik orang lain,
 * lalu token yang ditandatangani orang lain terbaca sah.
 *
 * ID token diverifikasi di sini, bukan dipercaya karena datang dari endpoint token lewat TLS:
 * tanda tangan terhadap JWKS, `iss`, `aud`, masa berlaku, dan `nonce` milik upacara ini. Penyedia
 * yang sama punya halaman uji yang melaporkan setiap tanda tangan "terverifikasi" tanpa memeriksa
 * apa pun — alasan yang cukup untuk tidak mengandalkan pemeriksaan di sisi mana pun selain sisi ini.
 *
 * ## Kunci yang berganti
 *
 * JWKS disimpan satu jam. Token yang memuat `kid` yang tidak dikenal memaksa satu pengambilan ulang
 * sebelum ditolak — penyedia yang memutar kuncinya tidak boleh membuat semua orang gagal masuk
 * sampai simpanannya habis, dan sebaliknya satu token asing tidak boleh memicu pengambilan tanpa
 * batas.
 */
class SharedIdentityProvider
{
    /** Kelonggaran jam antara server ini dan penyedia, dalam detik. */
    private const CLOCK_LEEWAY = 60;

    private const BACKCHANNEL_LOGOUT_EVENT = 'http://schemas.openid.net/event/backchannel-logout';

    public function isConfigured(): bool
    {
        return $this->issuer() !== '' && $this->clientId() !== '' && $this->clientSecret() !== '';
    }

    public function issuer(): string
    {
        return rtrim($this->setting('issuer'), '/');
    }

    public function clientId(): string
    {
        return $this->setting('client_id');
    }

    /**
     * Alamat yang dibuka peramban untuk memulai masuk.
     *
     * `S256` saja, walau penyedia juga mengiklankan `plain`: `plain` mengirim verifier apa adanya,
     * dan kode yang tercegat bersama challenge-nya cukup untuk ditukar. `code` saja, walau penyedia
     * juga mengiklankan alur implisit — RFC 9700 menyarankan alur itu tidak dipakai.
     */
    public function authorizationUrl(string $state, string $nonce, string $codeChallenge, string $redirectUri): string
    {
        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId(),
            'redirect_uri' => $redirectUri,
            'scope' => 'openid profile email',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);

        return $this->endpoint('authorization_endpoint').'?'.$query;
    }

    /**
     * Menukar kode otorisasi dengan ID token, lalu memverifikasinya.
     *
     * @return array<string, mixed> klaim ID token yang sudah terverifikasi
     */
    public function exchangeCode(string $code, string $codeVerifier, string $redirectUri, string $nonce): array
    {
        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout(15)
                ->withBasicAuth($this->clientId(), $this->clientSecret())
                ->post($this->endpoint('token_endpoint'), [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $redirectUri,
                    'code_verifier' => $codeVerifier,
                ]);
        } catch (ConnectionException $e) {
            throw new SsoFailure(SsoFailure::PROVIDER_UNREACHABLE, 'Endpoint token tidak terjangkau: '.$e->getMessage());
        }

        if (! $response->successful()) {
            throw new SsoFailure(SsoFailure::REJECTED_BY_PROVIDER, sprintf(
                'Endpoint token menjawab %d: %s',
                $response->status(),
                $this->safeError($response),
            ));
        }

        $idToken = $response->json('id_token');

        if (! is_string($idToken) || $idToken === '') {
            throw new SsoFailure(SsoFailure::INVALID_TOKEN, 'Jawaban endpoint token tidak memuat id_token.');
        }

        $claims = $this->decode($idToken);

        if (! isset($claims['nonce']) || ! is_string($claims['nonce']) || ! hash_equals($nonce, $claims['nonce'])) {
            throw new SsoFailure(SsoFailure::INVALID_TOKEN, 'nonce ID token tidak cocok dengan upacara ini.');
        }

        if (! isset($claims['sub']) || ! is_string($claims['sub']) || $claims['sub'] === '') {
            throw new SsoFailure(SsoFailure::INVALID_TOKEN, 'ID token tidak memuat sub.');
        }

        return $claims;
    }

    /**
     * Memverifikasi logout token dari logout back-channel (OpenID Connect Back-Channel Logout 1.0 §2.6).
     *
     * @return array<string, mixed>
     */
    public function verifyLogoutToken(string $logoutToken): array
    {
        $claims = $this->decode($logoutToken);

        $events = $claims['events'] ?? null;

        if (! is_array($events) || ! array_key_exists(self::BACKCHANNEL_LOGOUT_EVENT, $events)) {
            throw new SsoFailure(SsoFailure::INVALID_TOKEN, 'Logout token tidak memuat event back-channel logout.');
        }

        // §2.4: logout token DILARANG memuat nonce — yang memuatnya bukan logout token, dan dapat
        // berupa ID token curian yang dikirim ulang ke endpoint ini.
        if (array_key_exists('nonce', $claims)) {
            throw new SsoFailure(SsoFailure::INVALID_TOKEN, 'Logout token memuat nonce.');
        }

        if (! isset($claims['sub']) || ! is_string($claims['sub']) || $claims['sub'] === '') {
            throw new SsoFailure(SsoFailure::INVALID_TOKEN, 'Logout token tidak memuat sub.');
        }

        return $claims;
    }

    /**
     * Tanda tangan, `iss`, `aud`, dan masa berlaku. Sisanya milik pemanggil.
     *
     * @return array<string, mixed>
     */
    private function decode(string $jwt): array
    {
        $previousLeeway = JWT::$leeway;
        JWT::$leeway = self::CLOCK_LEEWAY;

        try {
            $payload = $this->decodeWithKeys($jwt, refresh: false);
        } catch (SsoFailure $e) {
            if ($e->reason !== 'kid-tidak-dikenal') {
                throw $e;
            }

            $payload = $this->decodeWithKeys($jwt, refresh: true);
        } finally {
            JWT::$leeway = $previousLeeway;
        }

        /** @var array<string, mixed> $claims */
        $claims = json_decode((string) json_encode($payload), true);

        if (($claims['iss'] ?? null) !== $this->issuer()) {
            throw new SsoFailure(SsoFailure::INVALID_TOKEN, 'iss token tidak sama dengan penyedia yang disetel.');
        }

        $audience = $claims['aud'] ?? null;
        $audiences = is_array($audience) ? $audience : [$audience];

        if (! in_array($this->clientId(), $audiences, true)) {
            throw new SsoFailure(SsoFailure::INVALID_TOKEN, 'aud token bukan klien ini.');
        }

        return $claims;
    }

    private function decodeWithKeys(string $jwt, bool $refresh): stdClass
    {
        $keys = JWK::parseKeySet($this->jwks($refresh), 'RS256');

        try {
            return JWT::decode($jwt, $keys);
        } catch (\UnexpectedValueException $e) {
            // `kid` yang tidak ada di set memberi pesan ini. Hanya itu yang layak dicoba ulang.
            if (! $refresh && str_contains($e->getMessage(), '"kid" invalid')) {
                throw new SsoFailure('kid-tidak-dikenal', $e->getMessage());
            }

            throw new SsoFailure(SsoFailure::INVALID_TOKEN, 'Token tidak lolos verifikasi: '.$e->getMessage());
        } catch (Throwable $e) {
            throw new SsoFailure(SsoFailure::INVALID_TOKEN, 'Token tidak lolos verifikasi: '.$e->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function jwks(bool $refresh): array
    {
        $cacheKey = 'coreerp.sso.jwks.'.sha1($this->issuer());

        if ($refresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, 3600, function (): array {
            $document = $this->fetchJson($this->endpoint('jwks_uri'));

            if (! isset($document['keys']) || ! is_array($document['keys']) || $document['keys'] === []) {
                throw new SsoFailure(SsoFailure::PROVIDER_UNREACHABLE, 'JWKS penyedia tidak memuat kunci.');
            }

            return $document;
        });
    }

    private function endpoint(string $name): string
    {
        $value = $this->discovery()[$name] ?? null;

        if (! is_string($value) || $value === '') {
            throw new SsoFailure(SsoFailure::PROVIDER_UNREACHABLE, sprintf('Dokumen penemuan tidak memuat %s.', $name));
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private function discovery(): array
    {
        return Cache::remember('coreerp.sso.discovery.'.sha1($this->issuer()), 3600, function (): array {
            $document = $this->fetchJson($this->issuer().'/.well-known/openid-configuration');

            if (($document['issuer'] ?? null) !== $this->issuer()) {
                throw new SsoFailure(SsoFailure::PROVIDER_UNREACHABLE, 'issuer di dokumen penemuan tidak sama dengan yang disetel.');
            }

            return $document;
        });
    }

    /** @return array<string, mixed> */
    private function fetchJson(string $url): array
    {
        try {
            $response = Http::acceptJson()->timeout(10)->get($url);
        } catch (ConnectionException $e) {
            throw new SsoFailure(SsoFailure::PROVIDER_UNREACHABLE, sprintf('%s tidak terjangkau: %s', $url, $e->getMessage()));
        }

        $document = $response->json();

        if (! $response->successful() || ! is_array($document)) {
            throw new SsoFailure(SsoFailure::PROVIDER_UNREACHABLE, sprintf('%s menjawab %d.', $url, $response->status()));
        }

        /** @var array<string, mixed> $document */
        return $document;
    }

    /** Hanya `error` dan `error_description` — jawaban lain dapat memuat apa saja. */
    private function safeError(Response $response): string
    {
        $error = $response->json('error');
        $description = $response->json('error_description');

        return trim((is_string($error) ? $error : 'tanpa-kode').' '.(is_string($description) ? $description : ''));
    }

    private function setting(string $key): string
    {
        $value = config('coreerp.sso.'.$key);

        return is_string($value) ? trim($value) : '';
    }

    private function clientSecret(): string
    {
        return $this->setting('client_secret');
    }
}
