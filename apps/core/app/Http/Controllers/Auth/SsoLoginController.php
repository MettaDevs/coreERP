<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Environment;
use App\Models\ExternalIdentity;
use App\Models\SsoLoginAttempt;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\ControlPlane\EnvironmentAddress;
use App\Support\Sso\SharedIdentityProvider;
use App\Support\Sso\SsoFailure;
use App\Support\Sso\TenantSso;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Masuk lewat penyedia identitas bersama, dari tombol di alamat tenant sampai sesi berdiri di sana.
 *
 * ## Tiga langkah, dua alamat
 *
 * 1. `start` — di alamat tenant. Mencatat upacara, menaruh rahasia peramban sebagai cookie, lalu
 *    mengirim orangnya ke penyedia.
 * 2. `callback` — di alamat pangkal, satu-satunya alamat balik yang terdaftar di penyedia. Menukar
 *    kode, memverifikasi ID token, menautkan akun, memeriksa keanggotaan, lalu menerbitkan token
 *    serah sekali pakai.
 * 3. `handoff` — kembali di alamat tenant. Mencocokkan token serah dengan rahasia peramban dari
 *    langkah 1, lalu mendirikan sesi di alamat itu.
 *
 * Alamat baliknya satu, bukan satu per tenant, karena penyedia mencocokkan alamat balik secara
 * persis — tanpa wildcard — dan subdomain tenant lahir setiap kali ada pelanggan baru. Microsoft
 * menyarankan pola yang sama untuk aplikasi dengan banyak subdomain: satu alamat balik bersama,
 * dan `state` yang membawa ke mana orangnya harus dikembalikan.
 *
 * ## Akun: ditautkan, tidak dibuat
 *
 * Akun CoreERP **tidak** dibuat otomatis dari akun SSO. Penautan pertama hanya terjadi bila penyedia
 * menyatakan emailnya terverifikasi dan email itu sudah milik akun CoreERP; sesudahnya dicocokkan
 * lewat `iss` + `sub`. Jalur undangan tetap satu-satunya pintu masuk ke sebuah tenant. Ini pilihan
 * yang paling ketat dan dapat dilonggarkan kelak — melonggarkan mudah, mengetatkan sesudah akun
 * liar terlanjur lahir tidak.
 */
class SsoLoginController extends Controller
{
    public const ATTEMPT_COOKIE = 'coreerp_sso_attempt';

    /** Satu upacara, dari tombol sampai sesi, harus selesai dalam waktu ini. */
    private const ATTEMPT_MINUTES = 10;

    public function __construct(
        private readonly SharedIdentityProvider $provider,
        private readonly TenantSso $tenantSso,
    ) {}

    public function start(Request $request): RedirectResponse
    {
        $environment = $request->attributes->get('coreerp.environment');

        if (! $environment instanceof Environment || ! $this->tenantSso->availableFor($environment->tenant_id)) {
            abort(404);
        }

        $state = Str::random(64);
        $browserSecret = Str::random(64);
        $nonce = Str::random(64);
        $codeVerifier = Str::random(96);
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
        $redirectUri = $this->callbackUrl($request);

        SsoLoginAttempt::create([
            'state_hash' => hash('sha256', $state),
            'browser_secret_hash' => hash('sha256', $browserSecret),
            'nonce' => $nonce,
            'code_verifier' => $codeVerifier,
            'tenant_id' => $environment->tenant_id,
            'environment_id' => $environment->id,
            'return_origin' => $request->getSchemeAndHttpHost(),
            'redirect_uri' => $redirectUri,
            'expires_at' => now()->addMinutes(self::ATTEMPT_MINUTES),
        ]);

        try {
            $authorizationUrl = $this->provider->authorizationUrl($state, $nonce, $codeChallenge, $redirectUri);
        } catch (SsoFailure $e) {
            Log::warning('SSO: upacara masuk tidak dapat dimulai.', ['alasan' => $e->reason, 'detail' => $e->getMessage()]);

            return redirect()->to('/login?sso_error='.$e->reason);
        }

        return redirect()->away($authorizationUrl)->withCookie(new Cookie(
            name: self::ATTEMPT_COOKIE,
            value: $browserSecret,
            expire: now()->addMinutes(self::ATTEMPT_MINUTES),
            path: '/',
            domain: null,
            secure: $request->isSecure(),
            httpOnly: true,
            raw: false,
            sameSite: Cookie::SAMESITE_LAX,
        ));
    }

    public function callback(Request $request): RedirectResponse
    {
        $state = $request->query('state');
        $attempt = is_string($state) && $state !== ''
            ? SsoLoginAttempt::query()->where('state_hash', hash('sha256', $state))->first()
            : null;

        // Tanpa upacara yang dikenal, tidak ada alamat tenant untuk kembali — dan tidak ada yang
        // boleh ditebak dari permintaan ini. Termasuk panggilan "1-Click Launch" dari portal
        // penyedia, yang membawa `state` buatannya sendiri.
        if (! $attempt instanceof SsoLoginAttempt || $attempt->completed_at !== null) {
            abort(403, 'Upacara masuk SSO ini tidak dikenal atau sudah dipakai.');
        }

        try {
            if ($attempt->hasExpired()) {
                throw new SsoFailure(SsoFailure::EXPIRED, 'Upacara melewati batas waktunya sebelum kembali dari penyedia.');
            }

            if ($request->query('error') !== null) {
                throw new SsoFailure(SsoFailure::REJECTED_BY_PROVIDER, 'Penyedia mengembalikan error: '.(string) $request->query('error'));
            }

            $code = $request->query('code');

            if (! is_string($code) || $code === '') {
                throw new SsoFailure(SsoFailure::INVALID_TOKEN, 'Alamat balik tidak membawa code.');
            }

            $claims = $this->provider->exchangeCode($code, $attempt->code_verifier, $attempt->redirect_uri, $attempt->nonce);
            $user = $this->resolveUser($claims);

            if (! $this->isActiveMember($user, $attempt->tenant_id)) {
                throw new SsoFailure(SsoFailure::NOT_A_MEMBER, sprintf('User %d bukan anggota aktif tenant %s.', $user->id, $attempt->tenant_id));
            }

            $this->linkIfNew($user, $claims);

            $handoffToken = Str::random(64);

            $attempt->update([
                'user_id' => $user->id,
                'handoff_token_hash' => hash('sha256', $handoffToken),
                'completed_at' => now(),
            ]);
        } catch (SsoFailure $e) {
            $attempt->update(['expires_at' => now(), 'completed_at' => now()]);
            Log::info('SSO: upacara masuk berhenti.', ['alasan' => $e->reason, 'detail' => $e->getMessage()]);

            return redirect()->away($attempt->return_origin.'/login?sso_error='.$e->reason);
        }

        return redirect()->away($attempt->return_origin.'/sso/serah?token='.$handoffToken);
    }

    public function handoff(Request $request): RedirectResponse
    {
        $environment = $request->attributes->get('coreerp.environment');
        $token = $request->query('token');
        $browserSecret = $request->cookie(self::ATTEMPT_COOKIE);

        $attempt = $environment instanceof Environment && is_string($token) && $token !== ''
            ? SsoLoginAttempt::query()->where('handoff_token_hash', hash('sha256', $token))->first()
            : null;

        $valid = $attempt instanceof SsoLoginAttempt
            && $attempt->environment_id === $environment->id
            && $attempt->completed_at !== null
            && $attempt->consumed_at === null
            && $attempt->user_id !== null
            && ! $attempt->hasExpired()
            && is_string($browserSecret)
            && hash_equals($attempt->browser_secret_hash, hash('sha256', $browserSecret));

        if (! $valid) {
            return redirect()->to('/login?sso_error='.SsoFailure::EXPIRED)->withoutCookie(self::ATTEMPT_COOKIE);
        }

        // Atomik: dua tab yang menyerahkan token yang sama, hanya satu yang mendapat sesi.
        $claimed = SsoLoginAttempt::query()
            ->whereKey($attempt->id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $user = User::query()->find($attempt->user_id);

        if ($claimed !== 1 || ! $user instanceof User || ! $this->isActiveMember($user, $attempt->tenant_id)) {
            return redirect()->to('/login?sso_error='.SsoFailure::NOT_A_MEMBER)->withoutCookie(self::ATTEMPT_COOKIE);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended('/dashboard')->withoutCookie(self::ATTEMPT_COOKIE);
    }

    /**
     * Alamat balik bersama untuk penempatan ini: domain dasar, dengan skema dan porta permintaan ini.
     *
     * Diturunkan dari permintaan di alamat tenant, bukan dari `APP_URL`: di laptop `APP_URL` berisi
     * `localhost:8000`, sementara alamat yang terdaftar di penyedia `erp.localhost:8000`.
     */
    private function callbackUrl(Request $request): string
    {
        $scheme = $request->getScheme();
        $port = (int) $request->getPort();
        $defaultPort = $scheme === 'https' ? 443 : 80;

        return $scheme.'://'.EnvironmentAddress::baseDomain().($port === $defaultPort ? '' : ':'.$port).'/sso/callback';
    }

    /** @param  array<string, mixed>  $claims */
    private function resolveUser(array $claims): User
    {
        $identity = ExternalIdentity::query()
            ->where('issuer', $this->provider->issuer())
            ->where('subject', (string) $claims['sub'])
            ->first();

        if ($identity instanceof ExternalIdentity) {
            $user = User::query()->find($identity->user_id);

            if ($user instanceof User) {
                return $user;
            }
        }

        $email = $claims['email'] ?? null;

        if (($claims['email_verified'] ?? null) !== true || ! is_string($email) || $email === '') {
            throw new SsoFailure(SsoFailure::ACCOUNT_NOT_FOUND, 'Belum tertaut, dan penyedia tidak menyatakan email terverifikasi.');
        }

        $user = User::query()->whereRaw('lower(email) = ?', [mb_strtolower($email)])->first();

        if (! $user instanceof User) {
            throw new SsoFailure(SsoFailure::ACCOUNT_NOT_FOUND, 'Tidak ada akun CoreERP dengan email itu.');
        }

        // Akun yang sudah tertaut ke subjek LAIN dari penyedia yang sama tidak ditautkan ulang lewat
        // email. Itu bentuk email yang berpindah tangan di penyedia, dan menautkannya berarti
        // menyerahkan akun ini kepada pemilik email yang baru.
        $alreadyLinked = ExternalIdentity::query()
            ->where('issuer', $this->provider->issuer())
            ->where('user_id', $user->id)
            ->exists();

        if ($alreadyLinked) {
            throw new SsoFailure(SsoFailure::ACCOUNT_NOT_FOUND, 'Akun dengan email itu sudah tertaut ke subjek lain di penyedia yang sama.');
        }

        return $user;
    }

    /** @param  array<string, mixed>  $claims */
    private function linkIfNew(User $user, array $claims): void
    {
        ExternalIdentity::query()->firstOrCreate(
            ['issuer' => $this->provider->issuer(), 'subject' => (string) $claims['sub']],
            ['user_id' => $user->id, 'email_at_link' => is_string($claims['email'] ?? null) ? $claims['email'] : null],
        );
    }

    private function isActiveMember(User $user, string $tenantId): bool
    {
        return TenantMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();
    }
}
