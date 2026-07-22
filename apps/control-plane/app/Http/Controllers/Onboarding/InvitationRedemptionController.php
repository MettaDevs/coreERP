<?php

namespace App\Http\Controllers\Onboarding;

use App\Actions\Onboarding\RedeemInvitation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\JoinInvitationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class InvitationRedemptionController extends Controller
{
    public function store(JoinInvitationRequest $request, RedeemInvitation $action): JsonResponse|RedirectResponse
    {
        $user = $action->handle($request->payload());
        Auth::login($user);
        $request->session()->regenerate();

        if ($request->is('api/*')) {
            return response()->json(['data' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email]], 201);
        }

        return redirect()->route('dashboard');
    }
}
