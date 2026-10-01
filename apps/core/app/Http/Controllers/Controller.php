<?php

namespace App\Http\Controllers;

use App\Platform\Environment\Support\CurrentWorkspace;
use App\Platform\Tenant\Models\TenantMembership;
use Illuminate\Http\Request;

abstract class Controller
{
    protected function currentMembership(Request $request): TenantMembership
    {
        $membership = app(CurrentWorkspace::class)->membership($request);
        abort_unless($membership !== null, 403);

        return $membership;
    }
}
