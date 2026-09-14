<?php

declare(strict_types=1);

namespace ControlPlane\Http\Controllers;

use ControlPlane\Models\ExternalIdentity;
use ControlPlane\Models\User;
use ControlPlane\Sso\Ceremony;
use ControlPlane\Sso\IdentityProvider;
use ControlPlane\Sso\SsoFailure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Halaman Akun operator: menghubungkan dan memutus akun SSO.
 *
 * Kedua tindakan menuntut kata sandi diketik ulang di permintaan itu sendiri, bukan cukup sedang
 * masuk. Sesi yang tertinggal terbuka di meja orang lain tidak boleh cukup untuk menautkan akun
 * SSO milik orang yang duduk di sana — sesudahnya orang itu dapat masuk ke konsol kapan saja, dari
 * mana saja, dengan akunnya sendiri.
 */
class Account extends Controller
{
    public function __construct(
        private readonly IdentityProvider $provider,
        private readonly Ceremony $ceremony,
    ) {}

    public function show(Request $request): InertiaResponse
    {
        /** @var User $user */
        $user = $request->user();

        $identity = $this->provider->isConfigured()
            ? ExternalIdentity::query()
                ->where('user_id', $user->id)
                ->where('issuer', $this->provider->issuer())
                ->first()
            : null;

        return Inertia::render('account', [
            'sso' => $this->provider->isConfigured() ? [
                'linked' => $identity instanceof ExternalIdentity,
                'emailAtLink' => $identity?->email_at_link,
                'linkedAt' => $identity?->created_at?->diffForHumans(),
            ] : null,
            'ssoError' => SsoFailure::messageFor($request->query('sso_error')),
        ]);
    }

    public function connect(Request $request): Response
    {
        abort_unless($this->provider->isConfigured(), 404);

        /** @var User $user */
        $user = $this->confirmPassword($request);

        try {
            return $this->ceremony->begin($request, Ceremony::CONNECT, $user->id);
        } catch (SsoFailure $e) {
            Log::warning('Konsol SSO: upacara hubungkan tidak dapat dimulai.', ['alasan' => $e->reason, 'detail' => $e->getMessage()]);

            return redirect('/akun?sso_error='.$e->reason);
        }
    }

    public function disconnect(Request $request): RedirectResponse
    {
        abort_unless($this->provider->isConfigured(), 404);

        $user = $this->confirmPassword($request);

        $removed = ExternalIdentity::query()
            ->where('user_id', $user->id)
            ->where('issuer', $this->provider->issuer())
            ->delete();

        Log::info('Konsol SSO: hubungan akun diputus.', ['user_id' => $user->id, 'baris' => $removed]);

        return redirect('/akun')->with('message', 'Akun SSO diputus dari akun ini.');
    }

    private function confirmPassword(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        $request->validate(['password' => ['required', 'string']]);

        if (! Hash::check((string) $request->input('password'), (string) $user->getAuthPassword())) {
            throw ValidationException::withMessages(['password' => 'Kata sandi tidak cocok.']);
        }

        return $user;
    }
}
