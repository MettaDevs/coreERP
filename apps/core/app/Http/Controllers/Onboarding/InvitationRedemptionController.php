<?php

namespace App\Http\Controllers\Onboarding;

use App\Actions\Access\CreateInvitation;
use App\Actions\Onboarding\RedeemInvitation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\JoinInvitationRequest;
use App\Models\InvitationCode;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Sso\SsoFailure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Satu alamat, dua jalur — dan yang memilihnya adalah apakah pengirimnya sudah masuk.
 *
 * Rutenya sengaja **tidak** lagi dijaga `guest`. Selama ia dijaga begitu, orang yang sudah punya
 * akun bahkan tidak dapat mencapai halaman ini, dan "satu orang di banyak tenant" mustahil lewat
 * jalur mana pun — ia harus keluar dulu, lalu menukar kode dengan email yang pasti ditolak.
 */
class InvitationRedemptionController extends Controller
{
    /**
     * Halaman tukar kode, dalam dua bentuk.
     *
     * Bentuknya ditentukan `?kode=` di alamat: kode yang menunjuk undangan terikat SSO yang masih
     * terbuka menampilkan satu tombol masuk SSO, selainnya menampilkan formulir seperti biasa.
     *
     * `?kode=` sengaja **hanya** dipakai untuk undangan terikat. Membuatnya juga mengisi kode anonim
     * akan menjadikan alamat halaman ini tempat menitipkan rahasia yang benar-benar membuka akses;
     * untuk undangan terikat, kodenya tidak membuka apa pun tanpa akun SSO yang diundang.
     */
    public function show(Request $request): Response
    {
        $code = $request->query('kode');

        $invitation = is_string($code) && $code !== ''
            ? InvitationCode::query()->where('code_hash', CreateInvitation::hash($code))->first()
            : null;

        $bound = $invitation instanceof InvitationCode && $invitation->isSsoBound() && $invitation->isOpen()
            ? $invitation
            : null;

        return Inertia::render('auth/join', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            'authenticated' => $request->user() !== null,
            'mode' => $bound === null ? 'kata-sandi' : 'sso',
            'code' => $bound === null ? null : (string) $code,
            'invitedName' => $bound?->sso_name_at_invite,
            'invitedEmail' => $bound?->sso_email_at_invite,
            'tenantName' => $bound === null ? null : Tenant::query()->whereKey($bound->tenant_id)->value('name'),
            // Dicetak dari daftar tertutup, bukan dari teks yang dibawa alamat.
            'ssoError' => SsoFailure::messageFor($request->query('sso_error')),
        ]);
    }

    public function store(JoinInvitationRequest $request, RedeemInvitation $action): JsonResponse|RedirectResponse
    {
        $user = $request->user();

        if ($user instanceof User) {
            $action->handleForUser($user, $request->string('code')->toString());

            if ($request->is('api/*')) {
                return response()->json(['data' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email]], 201);
            }

            /*
             * Tanpa `Auth::login()` dan tanpa `session()->regenerate()`.
             *
             * Sesinya sudah miliknya dan sudah sah; memutarnya di sini hanya akan membuang keadaan
             * yang sedang dipegangnya. Regenerasi ada untuk mencegah penetapan sesi ketika
             * identitas **berubah** — dan di jalur ini identitasnya tidak berubah sama sekali.
             */
            return redirect()->route('dashboard')->with(
                'message',
                'Kode diterima. Akun ini sekarang punya satu tempat kerja tambahan — pilih lewat pengalih tenant.',
            );
        }

        $user = $action->handle($request->payload());
        Auth::login($user);
        $request->session()->regenerate();

        if ($request->is('api/*')) {
            return response()->json(['data' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email]], 201);
        }

        return redirect()->route('dashboard');
    }
}
