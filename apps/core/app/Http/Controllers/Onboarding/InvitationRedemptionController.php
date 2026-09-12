<?php

namespace App\Http\Controllers\Onboarding;

use App\Actions\Onboarding\RedeemInvitation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\JoinInvitationRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Satu alamat, dua jalur — dan yang memilihnya adalah apakah pengirimnya sudah masuk.
 *
 * Rutenya sengaja **tidak** lagi dijaga `guest`. Selama ia dijaga begitu, orang yang sudah punya
 * akun bahkan tidak dapat mencapai halaman ini, dan "satu orang di banyak tenant" mustahil lewat
 * jalur mana pun — ia harus keluar dulu, lalu menukar kode dengan email yang pasti ditolak.
 */
class InvitationRedemptionController extends Controller
{
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
