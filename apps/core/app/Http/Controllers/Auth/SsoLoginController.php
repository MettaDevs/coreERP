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
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Masuk lewat penyedia identitas bersama — dan menghubungkan akun SSO ke akun CoreERP.
 *
 * ## Tiga langkah, dua alamat
 *
 * 1. `start` / `connect` — di alamat tenant. Mencatat upacara, menaruh rahasia peramban sebagai
 *    cookie, lalu mengirim orangnya ke penyedia.
 * 2. `callback` — di alamat pangkal, satu-satunya alamat balik yang terdaftar di penyedia. Menukar
 *    kode, memverifikasi ID token, lalu menerbitkan token serah sekali pakai.
 * 3. `handoff` — kembali di alamat tenant. Mencocokkan token serah dengan rahasia peramban dari
 *    langkah 1, lalu mendirikan sesi (masuk) atau kembali ke layar keamanan (hubungkan).
 *
 * Alamat baliknya satu, bukan satu per tenant, karena penyedia mencocokkan alamat balik secara
 * persis — tanpa wildcard — dan subdomain tenant lahir setiap kali ada pelanggan baru.
 *
 * ## Akun: tidak pernah ditautkan lewat email
 *
 * Upacara masuk hanya menerima akun SSO yang **sudah** terhubung, lewat `iss` + `sub`. Akun SSO yang
 * belum terhubung ditolak, sekalipun emailnya sama persis dengan akun CoreERP dan penyedia
 * menyatakannya terverifikasi.
 *
 * Alasannya diukur, bukan diduga. Pendaftaran mandiri di penyedia menulis `email_verified_at = now()`
 * tanpa memverifikasi apa pun (diperiksa di kodenya, 14 September 2026). Menautkan lewat email
 * berarti siapa pun yang mendaftar di sana dengan email admin sebuah tenant — sebelum admin itu
 * sendiri punya akun SSO — masuk ke CoreERP sebagai admin itu.
 *
 * Hubungan hanya lahir lewat `connect`: orangnya sudah masuk dengan kata sandi CoreERP, sudah
 * mengonfirmasinya lagi di layar keamanan, lalu masuk di penyedia dengan akun SSO miliknya. Dua
 * pembuktian kepemilikan, satu di tiap sisi. Barisnya ditulis di `handoff`, bukan di `callback` —
 * alasannya di migration `create_sso_tables`, kolom `subject`.
 */
class SsoLoginController extends Controller
{
    public const ATTEMPT_COOKIE = 'coreerp_sso_attempt';

    /** Satu upacara, dari tombol sampai sesi, harus selesai dalam waktu ini. */
    private const ATTEMPT_MINUTES = 10;

    private const SECURITY_PAGE = '/settings/security';

    public function __construct(
        private readonly SharedIdentityProvider $provider,
        private readonly TenantSso $tenantSso,
    ) {}

    /** Upacara masuk. Tamu saja. */
    public function start(Request $request): SymfonyResponse
    {
        return $this->begin($request, linkUserId: null);
    }

    /** Upacara menghubungkan akun SSO ke akun yang sedang masuk. */
    public function connect(Request $request): SymfonyResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->begin($request, linkUserId: $user->id);
    }

    /** Memutus hubungan akun yang sedang masuk dengan akun SSO-nya. */
    public function disconnect(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $removed = ExternalIdentity::query()
            ->where('user_id', $user->id)
            ->where('issuer', $this->provider->issuer())
            ->delete();

        Log::info('SSO: hubungan akun diputus.', ['user_id' => $user->id, 'baris' => $removed]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Akun SSO diputus dari akun ini.']);

        return redirect()->to(self::SECURITY_PAGE);
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
            $subject = (string) $claims['sub'];

            $user = $attempt->isLinking()
                ? $this->assertLinkable((int) $attempt->link_user_id, $subject)
                : $this->linkedUser($subject);

            if (! $this->isActiveMember($user, $attempt->tenant_id)) {
                throw new SsoFailure(SsoFailure::NOT_A_MEMBER, sprintf('User %d bukan anggota aktif tenant %s.', $user->id, $attempt->tenant_id));
            }

            $handoffToken = Str::random(64);

            $attempt->update([
                'user_id' => $user->id,
                'subject' => $subject,
                'subject_email' => is_string($claims['email'] ?? null) ? $claims['email'] : null,
                'handoff_token_hash' => hash('sha256', $handoffToken),
                'completed_at' => now(),
            ]);
        } catch (SsoFailure $e) {
            $attempt->update(['expires_at' => now(), 'completed_at' => now()]);
            Log::info('SSO: upacara berhenti.', ['alasan' => $e->reason, 'hubungkan' => $attempt->isLinking(), 'detail' => $e->getMessage()]);

            $page = $attempt->isLinking() ? self::SECURITY_PAGE : '/login';

            return redirect()->away($attempt->return_origin.$page.'?sso_error='.$e->reason);
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

        $failurePage = $attempt instanceof SsoLoginAttempt && $attempt->isLinking() ? self::SECURITY_PAGE : '/login';

        if (! $valid) {
            return redirect()->to($failurePage.'?sso_error='.SsoFailure::EXPIRED)->withoutCookie(self::ATTEMPT_COOKIE);
        }

        // Upacara "hubungkan" hanya boleh diselesaikan akun yang memulainya, di sesi yang sama.
        if ($attempt->isLinking() && (int) Auth::id() !== (int) $attempt->link_user_id) {
            return redirect()->to('/login?sso_error='.SsoFailure::EXPIRED)->withoutCookie(self::ATTEMPT_COOKIE);
        }

        // Atomik: dua tab yang menyerahkan token yang sama, hanya satu yang berhasil.
        $claimed = SsoLoginAttempt::query()
            ->whereKey($attempt->id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $user = User::query()->find($attempt->user_id);

        if ($claimed !== 1 || ! $user instanceof User || ! $this->isActiveMember($user, $attempt->tenant_id)) {
            return redirect()->to($failurePage.'?sso_error='.SsoFailure::NOT_A_MEMBER)->withoutCookie(self::ATTEMPT_COOKIE);
        }

        if ($attempt->isLinking()) {
            // Baru di sini, sesudah cookie peramban dan akun yang sedang masuk sama-sama cocok.
            try {
                $this->assertLinkable($user->id, (string) $attempt->subject);

                // Di dalam transaksi supaya pelanggaran unik dari upacara yang berbalapan hanya
                // membatalkan savepoint-nya sendiri. Pada PostgreSQL, galat di dalam transaksi yang
                // lebih luar membatalkan seluruhnya, termasuk query sesudahnya.
                DB::transaction(fn () => ExternalIdentity::create([
                    'user_id' => $user->id,
                    'issuer' => $this->provider->issuer(),
                    'subject' => (string) $attempt->subject,
                    'email_at_link' => $attempt->subject_email,
                ]));
            } catch (SsoFailure|UniqueConstraintViolationException) {
                return redirect()->to(self::SECURITY_PAGE.'?sso_error='.SsoFailure::LINKED_ELSEWHERE)->withoutCookie(self::ATTEMPT_COOKIE);
            }

            Inertia::flash('toast', ['type' => 'success', 'message' => 'Akun SSO terhubung. Berikutnya Anda dapat masuk lewat SSO.']);

            return redirect()->to(self::SECURITY_PAGE)->withoutCookie(self::ATTEMPT_COOKIE);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended('/dashboard')->withoutCookie(self::ATTEMPT_COOKIE);
    }

    private function begin(Request $request, ?int $linkUserId): SymfonyResponse
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
            'link_user_id' => $linkUserId,
            'expires_at' => now()->addMinutes(self::ATTEMPT_MINUTES),
        ]);

        try {
            $authorizationUrl = $this->provider->authorizationUrl($state, $nonce, $codeChallenge, $redirectUri);
        } catch (SsoFailure $e) {
            Log::warning('SSO: upacara tidak dapat dimulai.', ['alasan' => $e->reason, 'detail' => $e->getMessage()]);

            return redirect()->to(($linkUserId !== null ? self::SECURITY_PAGE : '/login').'?sso_error='.$e->reason);
        }

        // Tombol "Hubungkan" dikirim lewat Inertia, yaitu XHR — dan XHR tidak dapat mengikuti
        // pengalihan ke domain penyedia. `Inertia::location` menyuruh peramban berpindah halaman
        // sendiri. Cookie-nya tetap menempel di jawaban itu, jadi pengikat peramban tidak hilang.
        $response = $request->header('X-Inertia')
            ? Inertia::location($authorizationUrl)
            : redirect()->away($authorizationUrl);

        $response->headers->setCookie(new Cookie(
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

        return $response;
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

    /** Akun CoreERP yang sudah terhubung ke subjek ini. Tidak ada jalan lain untuk masuk. */
    private function linkedUser(string $subject): User
    {
        $userId = ExternalIdentity::query()
            ->where('issuer', $this->provider->issuer())
            ->where('subject', $subject)
            ->value('user_id');

        $user = $userId !== null ? User::query()->find($userId) : null;

        if (! $user instanceof User) {
            throw new SsoFailure(SsoFailure::NOT_LINKED, 'Subjek ini belum terhubung ke akun CoreERP mana pun.');
        }

        return $user;
    }

    /**
     * Memastikan subjek boleh dihubungkan ke akun ini — tanpa menulis apa pun.
     *
     * Satu akun CoreERP satu akun SSO per penerbit, dan sebaliknya. Menimpa hubungan yang ada — ke
     * arah mana pun — berarti menyerahkan akun kepada pemilik akun SSO yang lain; yang benar
     * memutusnya lebih dulu, dengan sadar, dari layar keamanan. Hubungan yang sudah persis sama
     * juga ditolak: tidak ada yang perlu dihubungkan ulang.
     */
    private function assertLinkable(int $userId, string $subject): User
    {
        $user = User::query()->find($userId);

        if (! $user instanceof User) {
            throw new SsoFailure(SsoFailure::NOT_A_MEMBER, 'Akun yang memulai upacara hubungkan sudah tidak ada.');
        }

        $taken = ExternalIdentity::query()
            ->where('issuer', $this->provider->issuer())
            ->where(fn ($query) => $query->where('subject', $subject)->orWhere('user_id', $user->id))
            ->exists();

        if ($taken) {
            throw new SsoFailure(SsoFailure::LINKED_ELSEWHERE, sprintf('Subjek ini atau user %d sudah punya hubungan.', $user->id));
        }

        return $user;
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
