<?php

namespace App\Http\Responses;

use App\Support\CurrentWorkspace;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

class LoginResponse implements LoginResponseContract
{
    public function toResponse($request)
    {
        $user = $request->user();
        if ($user) {
            $workspace = app(CurrentWorkspace::class);
            $memberships = $workspace->memberships($request);

            // Forget url.intended and stale workspace session keys
            $request->session()->forget(['url.intended', 'workspace.membership_id', 'workspace.legal_entity_id', 'workspace.org_unit_id']);

            if ($memberships->count() > 0) {
                return redirect()->route('workspace.select');
            }
        }

        return redirect()->route('workspace.select');
    }
}
