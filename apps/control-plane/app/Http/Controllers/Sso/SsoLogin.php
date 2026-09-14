<?php

declare(strict_types=1);

namespace ControlPlane\Http\Controllers\Sso;

use ControlPlane\Http\Controllers\Controller;
use ControlPlane\Models\ExternalIdentity;
use ControlPlane\Models\User;
use ControlPlane\Sso\Ceremony;
use ControlPlane\Sso\IdentityProvider;
use ControlPlane\Sso\SsoFailure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Masuk ke konsol operator lewat SSO, dan menyelesaikan upacara "hubungkan".
 *
 * ## Akun: tidak pernah ditautkan lewat email
 *
 * Aturannya sama dengan Core, dan alasannya sama: pendaftaran mandiri di penyedia menandai email
 * terverifikasi tanpa memverifikasinya. Upacara masuk hanya menerima akun SSO yang sudah terhubung
 * lewat `iss` + `sub`. Hubungan lahir hanya dari halaman Akun, oleh operator yang sedang masuk dan
 * baru saja mengetik ulang kata sandinya.
 *
 * ## Terhubung belum berarti operator
 *
 * Akun yang terhubung tetapi tidak bertanda `provider_admin` ditolak di sini, bukan dibiarkan masuk
 * lalu dijawab 404 oleh `OperatorOnly` di setiap halaman. Sesi yang berdiri untuk akun yang tidak
 * boleh memakai apa pun hanya menambah satu sesi yang harus diakhiri.
 */
class SsoLogin extends Controller
{
    public function __construct(
        private readonly IdentityProvider $provider,
        private readonly Ceremony $ceremony,
    ) {}

    public function start(Request $request): Response
    {
        abort_unless($this->provider->isConfigured(), 404);

        try {
            return $this->ceremony->begin($request, Ceremony::LOGIN);
        } catch (SsoFailure $e) {
            Log::warning('Konsol SSO: upacara masuk tidak dapat dimulai.', ['alasan' => $e->reason, 'detail' => $e->getMessage()]);

            return redirect('/login?sso_error='.$e->reason);
        }
    }

    public function callback(Request $request): RedirectResponse
    {
        abort_unless($this->provider->isConfigured(), 404);

        $ceremony = $this->ceremony->pull($request, $request->query('state'));

        // Tanpa upacara yang cocok di sesi ini: tautan yang dikirim orang lain, `state` buatan
        // portal penyedia, atau upacara yang sudah kedaluwarsa. Tidak ada yang ditebak dari sini.
        if ($ceremony === null) {
            return redirect((Auth::check() ? '/akun' : '/login').'?sso_error='.SsoFailure::EXPIRED);
        }

        $connecting = $ceremony['mode'] === Ceremony::CONNECT;
        $failurePage = $connecting ? '/akun' : '/login';

        try {
            if ($request->query('error') !== null) {
                throw new SsoFailure(SsoFailure::REJECTED_BY_PROVIDER, 'Penyedia mengembalikan error: '.(string) $request->query('error'));
            }

            $code = $request->query('code');

            if (! is_string($code) || $code === '') {
                throw new SsoFailure(SsoFailure::INVALID_TOKEN, 'Alamat balik tidak membawa code.');
            }

            $claims = $this->provider->exchangeCode($code, $ceremony['verifier'], $ceremony['redirect_uri'], $ceremony['nonce']);
            $subject = (string) $claims['sub'];

            return $connecting
                ? $this->finishConnect($ceremony['user_id'], $subject, $claims)
                : $this->finishLogin($request, $subject);
        } catch (SsoFailure $e) {
            Log::info('Konsol SSO: upacara berhenti.', ['alasan' => $e->reason, 'hubungkan' => $connecting, 'detail' => $e->getMessage()]);

            return redirect($failurePage.'?sso_error='.$e->reason);
        }
    }

    private function finishLogin(Request $request, string $subject): RedirectResponse
    {
        $userId = ExternalIdentity::query()
            ->where('issuer', $this->provider->issuer())
            ->where('subject', $subject)
            ->value('user_id');

        $user = $userId !== null ? User::query()->find($userId) : null;

        if (! $user instanceof User) {
            throw new SsoFailure(SsoFailure::NOT_LINKED, 'Subjek ini belum terhubung ke akun mana pun.');
        }

        if (! $user->operator()) {
            throw new SsoFailure(SsoFailure::NOT_AN_OPERATOR, sprintf('User %d terhubung tetapi bukan operator.', $user->id));
        }

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('login_at', now()->getTimestamp());

        return redirect()->intended('/lingkungan');
    }

    /** @param  array<string, mixed>  $claims */
    private function finishConnect(?int $userId, string $subject, array $claims): RedirectResponse
    {
        // Upacara "hubungkan" hanya boleh diselesaikan akun yang memulainya, di sesi yang sama.
        if ($userId === null || (int) Auth::id() !== $userId) {
            throw new SsoFailure(SsoFailure::EXPIRED, 'Upacara hubungkan diselesaikan akun yang berbeda dari pemulainya.');
        }

        $taken = ExternalIdentity::query()
            ->where('issuer', $this->provider->issuer())
            ->where(fn ($query) => $query->where('subject', $subject)->orWhere('user_id', $userId))
            ->exists();

        if ($taken) {
            throw new SsoFailure(SsoFailure::LINKED_ELSEWHERE, sprintf('Subjek ini atau user %d sudah punya hubungan.', $userId));
        }

        try {
            // Savepoint: pelanggaran unik dari upacara yang berbalapan tidak membatalkan transaksi
            // yang lebih luar.
            DB::transaction(fn () => ExternalIdentity::create([
                'user_id' => $userId,
                'issuer' => $this->provider->issuer(),
                'subject' => $subject,
                'email_at_link' => is_string($claims['email'] ?? null) ? $claims['email'] : null,
            ]));
        } catch (UniqueConstraintViolationException) {
            throw new SsoFailure(SsoFailure::LINKED_ELSEWHERE, 'Hubungan yang sama lahir di upacara lain lebih dulu.');
        }

        return redirect('/akun')->with('message', 'Akun SSO terhubung. Berikutnya Anda dapat masuk lewat SSO.');
    }
}
