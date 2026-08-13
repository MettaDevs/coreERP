<?php

namespace App\Http\Middleware;

use App\Support\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureWorkspaceSelected
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $request->routeIs('workspace.select', 'workspace.select.store', 'logout')) {
            $workspace = app(CurrentWorkspace::class);
            $memberships = $workspace->memberships($request);

            $currentMembershipId = $request->session()->get('workspace.membership_id');
            $currentMembership = $currentMembershipId ? $memberships->firstWhere('id', $currentMembershipId) : null;

            if (! $currentMembership) {
                $request->session()->forget(['workspace.membership_id', 'workspace.legal_entity_id', 'workspace.org_unit_id']);

                if ($memberships->count() > 0) {
                    return redirect()->route('workspace.select');
                }
            }
        }

        return $next($request);
    }
}
