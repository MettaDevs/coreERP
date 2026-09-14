<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use App\Http\Requests\Settings\TwoFactorAuthenticationRequest;
use App\Models\ExternalIdentity;
use App\Models\User;
use App\Support\Sso\SharedIdentityProvider;
use App\Support\Sso\SsoFailure;
use App\Support\Sso\TenantSso;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;

class SecurityController extends Controller
{
    /**
     * Show the user's security settings page.
     */
    public function edit(TwoFactorAuthenticationRequest $request): Response
    {
        $props = [
            /* @chisel-2fa */
            'canManageTwoFactor' => Features::canManageTwoFactorAuthentication(),
            /* @end-chisel-2fa */
            /* @chisel-passkeys */
            'canManagePasskeys' => Features::canManagePasskeys(),
            'passkeys' => Features::canManagePasskeys()
                ? $request->user()
                    ->passkeys()
                    ->select(['id', 'name', 'credential', 'created_at', 'last_used_at'])
                    ->latest()
                    ->get()
                    ->map(fn ($passkey) => [
                        'id' => $passkey->id,
                        'name' => $passkey->name,
                        'authenticator' => $passkey->authenticator,
                        'created_at_diff' => $passkey->created_at->diffForHumans(),
                        'last_used_at_diff' => $passkey->last_used_at?->diffForHumans(),
                    ])
                    ->values()
                    ->all()
                : [],
            /* @end-chisel-passkeys */
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            'sso' => $this->ssoState($request->user(), $request),
            'ssoError' => SsoFailure::messageFor($request->query('sso_error')),
        ];

        /* @chisel-2fa */
        if (Features::canManageTwoFactorAuthentication()) {
            $request->ensureStateIsValid();

            $props['twoFactorEnabled'] = $request->user()->hasEnabledTwoFactorAuthentication();
            $props['requiresConfirmation'] = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
        }
        /* @end-chisel-2fa */

        return Inertia::render('settings/security', $props);
    }

    /**
     * Keadaan hubungan akun ini dengan penyedia identitas bersama, untuk layar keamanan.
     *
     * Null bila penempatan ini tidak menyetel penyedia — bagiannya tidak tampil sama sekali.
     * `canConnect` hanya benar di alamat tenant yang memilih SSO: upacara hubungkan harus kembali
     * ke alamat tenant, dan di alamat lain tidak ada tempat kembali.
     *
     * @return array{linked: bool, emailAtLink: ?string, linkedAt: ?string, canConnect: bool}|null
     */
    private function ssoState(User $user, Request $request): ?array
    {
        $provider = app(SharedIdentityProvider::class);

        if (! $provider->isConfigured()) {
            return null;
        }

        $identity = ExternalIdentity::query()
            ->where('user_id', $user->id)
            ->where('issuer', $provider->issuer())
            ->first();

        return [
            'linked' => $identity instanceof ExternalIdentity,
            'emailAtLink' => $identity?->email_at_link,
            'linkedAt' => $identity?->created_at?->diffForHumans(),
            'canConnect' => app(TenantSso::class)->loginUrlFor($request) !== null,
        ];
    }

    /**
     * Update the user's password.
     */
    public function update(PasswordUpdateRequest $request): RedirectResponse
    {
        $pengguna = $request->user();

        $pengguna->update([
            'password' => $request->password,
        ]);

        // Penandanya dilepas di sini, dan hanya di sini. Yang membuktikan kata sandi sementara
        // sudah tidak beredar bukan kunjungan ke layar ini melainkan pergantiannya — penjaga yang
        // melepas penanda begitu layarnya dibuka akan melepaskannya pada orang yang menutup tab.
        if ($pengguna->must_change_password) {
            $pengguna->forceFill(['must_change_password' => false])->save();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Password updated.')]);

        return back();
    }
}
