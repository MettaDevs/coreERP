<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use App\Models\SecurityActivity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;

class SecurityController extends Controller
{
    /**
     * Show the user's security settings page.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();

        // Real-time User-Agent parsing
        $userAgent = $request->header('User-Agent', '');
        $browser = 'Chrome';
        if (str_contains($userAgent, 'Firefox')) $browser = 'Firefox';
        elseif (str_contains($userAgent, 'Safari') && !str_contains($userAgent, 'Chrome')) $browser = 'Safari';
        elseif (str_contains($userAgent, 'Edg')) $browser = 'Edge';

        $os = 'Windows';
        if (str_contains($userAgent, 'Macintosh') || str_contains($userAgent, 'Mac OS')) $os = 'macOS';
        elseif (str_contains($userAgent, 'Linux')) $os = 'Linux';
        elseif (str_contains($userAgent, 'Android')) $os = 'Android';
        elseif (str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad')) $os = 'iOS';

        $passwordChangedAt = $user->password_changed_at;
        $lastPasswordUpdatedWita = $passwordChangedAt 
            ? $this->formatWita($passwordChangedAt, true) 
            : $this->formatWita($user->created_at, true);

        // Fetch real sessions from database
        $currentSessionId = $request->session()->getId();
        $dbSessions = DB::table('sessions')
            ->where('user_id', $user->id)
            ->orderBy('last_activity', 'desc')
            ->get();

        if ($dbSessions->isEmpty()) {
            $sessions = [
                [
                    'id' => $currentSessionId,
                    'browser' => $browser,
                    'os' => $os,
                    'location' => "Perangkat Ini • {$request->ip()}",
                    'isCurrent' => true,
                    'lastActive' => 'Aktif sekarang',
                    'deviceType' => 'desktop',
                ],
            ];
        } else {
            $sessions = $dbSessions->map(function ($sess) use ($currentSessionId) {
                $agent = $sess->user_agent ?? '';
                $b = 'Chrome';
                if (str_contains($agent, 'Firefox')) $b = 'Firefox';
                elseif (str_contains($agent, 'Safari') && !str_contains($agent, 'Chrome')) $b = 'Safari';
                elseif (str_contains($agent, 'Edg')) $b = 'Edge';

                $o = 'Windows';
                if (str_contains($agent, 'Macintosh') || str_contains($agent, 'Mac OS')) $o = 'macOS';
                elseif (str_contains($agent, 'Linux')) $o = 'Linux';
                elseif (str_contains($agent, 'Android')) $o = 'Android';
                elseif (str_contains($agent, 'iPhone') || str_contains($agent, 'iPad')) $o = 'iOS';

                $isCurrent = $sess->id === $currentSessionId;
                $deviceType = (str_contains($agent, 'Android') || str_contains($agent, 'iPhone') || str_contains($agent, 'Mobile')) ? 'mobile' : 'desktop';
                $lastActiveCarbon = Carbon::createFromTimestamp($sess->last_activity);

                return [
                    'id' => $sess->id,
                    'browser' => $b,
                    'os' => $o,
                    'location' => ($isCurrent ? 'Perangkat Ini' : 'Sesi Terdaftar') . ' • ' . ($sess->ip_address ?? '127.0.0.1'),
                    'isCurrent' => $isCurrent,
                    'lastActive' => $isCurrent ? 'Aktif sekarang' : $this->formatWita($lastActiveCarbon, true),
                    'deviceType' => $deviceType,
                ];
            })->all();
        }

        // Fetch real persistent security activities from database
        $activities = SecurityActivity::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get();

        if ($activities->isEmpty()) {
            // Seed initial activity logs for user if table is empty
            SecurityActivity::create([
                'user_id' => $user->id,
                'type' => 'login',
                'title' => 'Login Berhasil',
                'detail' => "{$browser} • {$os} ({$request->ip()})",
                'status' => 'success',
            ]);
            SecurityActivity::create([
                'user_id' => $user->id,
                'type' => 'account',
                'title' => 'Akun Terdaftar',
                'detail' => 'Registrasi akun PT Sanata System berhasil',
                'status' => 'blue',
            ]);

            $activities = SecurityActivity::where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->get();
        }

        $formattedActivities = $activities->map(function (SecurityActivity $act) {
            return [
                'id' => $act->id,
                'type' => $act->type,
                'title' => $act->title,
                'date' => $this->formatWita($act->created_at),
                'detail' => $act->detail,
                'status' => $act->status,
            ];
        })->all();

        $passkeysList = Features::canManagePasskeys()
            ? $request->user()
                ->passkeys()
                ->select(['id', 'name', 'credential', 'created_at', 'last_used_at'])
                ->latest()
                ->get()
                ->map(fn ($passkey) => [
                    'id' => $passkey->id,
                    'name' => $passkey->name,
                    'authenticator' => $passkey->authenticator,
                    'created_at_diff' => $this->formatWita($passkey->created_at),
                    'last_used_at_diff' => $passkey->last_used_at ? $this->formatWita($passkey->last_used_at) : 'Belum pernah digunakan',
                ])
                ->values()
                ->all()
            : [];

        $lastPasskeyUsedWita = count($passkeysList) > 0 
            ? ($passkeysList[0]['last_used_at_diff'] ?? 'Belum pernah digunakan')
            : 'Belum pernah digunakan';

        $props = [
            'lastPasswordUpdatedWita' => $lastPasswordUpdatedWita,
            'lastPasskeyUsedWita' => $lastPasskeyUsedWita,
            'sessions' => $sessions,
            'currentSession' => $sessions[0],
            'securityActivities' => $formattedActivities,
            /* @chisel-2fa */
            'canManageTwoFactor' => Features::canManageTwoFactorAuthentication(),
            /* @end-chisel-2fa */
            /* @chisel-passkeys */
            'canManagePasskeys' => Features::canManagePasskeys(),
            'passkeys' => $passkeysList,
            /* @end-chisel-passkeys */
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ];

        /* @chisel-2fa */
        if (Features::canManageTwoFactorAuthentication()) {
            $props['twoFactorEnabled'] = $request->user()->hasEnabledTwoFactorAuthentication();
            $props['requiresConfirmation'] = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
        }
        /* @end-chisel-2fa */

        return Inertia::render('settings/security', $props);
    }

    /**
     * Helper to format datetime consistently in Asia/Makassar (WITA, UTC+8)
     */
    private function formatWita(mixed $date, bool $withRelative = false): string
    {
        if (! $date) {
            return 'Belum pernah';
        }

        $parsed = Carbon::parse($date)->setTimezone('Asia/Makassar');
        $formatted = $parsed->translatedFormat('d F Y, H:i') . ' WITA';
        if ($withRelative) {
            $formatted .= ' · ' . $parsed->diffForHumans();
        }

        return $formatted;
    }

    /**
     * Update the user's password.
     */
    public function update(PasswordUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $user->update([
            'password' => $request->password,
            'password_changed_at' => now(),
        ]);

        SecurityActivity::create([
            'user_id' => $user->id,
            'type' => 'password',
            'title' => 'Kata Sandi Diperbarui',
            'detail' => 'Kata sandi diubah dari panel keamanan',
            'ip_address' => $request->ip(),
            'user_agent' => $request->header('User-Agent'),
            'status' => 'blue',
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Kata sandi berhasil diperbarui.']);

        return back();
    }

    /**
     * Revoke single session from database.
     */
    public function destroySession(Request $request, string $sessionId): RedirectResponse
    {
        $userId = $request->user()->id;
        $currentSessionId = $request->session()->getId();

        if ($sessionId !== $currentSessionId) {
            DB::table('sessions')
                ->where('id', $sessionId)
                ->where('user_id', $userId)
                ->delete();

            SecurityActivity::create([
                'user_id' => $userId,
                'type' => 'session_revoked',
                'title' => 'Sesi Perangkat Dikeluarkan',
                'detail' => 'Sesi perangkat tertentu berhasil dihentikan',
                'ip_address' => $request->ip(),
                'user_agent' => $request->header('User-Agent'),
                'status' => 'warning',
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Sesi perangkat berhasil dikeluarkan.']);

        return back();
    }

    /**
     * Revoke all other sessions from database except current session.
     */
    public function destroyOtherSessions(Request $request): RedirectResponse
    {
        $userId = $request->user()->id;
        $currentSessionId = $request->session()->getId();

        DB::table('sessions')
            ->where('user_id', $userId)
            ->where('id', '!=', $currentSessionId)
            ->delete();

        SecurityActivity::create([
            'user_id' => $userId,
            'type' => 'session_revoked_all',
            'title' => 'Keluar dari Seluruh Perangkat Lain',
            'detail' => 'Seluruh sesi di perangkat lain telah dihentikan',
            'ip_address' => $request->ip(),
            'user_agent' => $request->header('User-Agent'),
            'status' => 'warning',
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Seluruh perangkat lain berhasil dikeluarkan.']);

        return back();
    }

    /**
     * Delete single security activity record from database.
     */
    public function destroyActivity(Request $request, int $id): RedirectResponse
    {
        SecurityActivity::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Aktivitas keamanan berhasil dihapus.']);

        return back();
    }

    /**
     * Delete all security activity records from database for current user.
     */
    public function destroyAllActivities(Request $request): RedirectResponse
    {
        SecurityActivity::where('user_id', $request->user()->id)->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Seluruh aktivitas keamanan berhasil dihapus.']);

        return back();
    }
}
