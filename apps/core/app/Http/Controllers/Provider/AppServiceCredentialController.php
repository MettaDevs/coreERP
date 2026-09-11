<?php

namespace App\Http\Controllers\Provider;

use App\Http\Controllers\Controller;
use App\Models\AppServiceCredential;
use App\Models\CoreApp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppServiceCredentialController extends Controller
{
    public function store(Request $request, CoreApp $app): JsonResponse
    {
        abort_unless($request->user()?->can('manage-app-catalog'), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            // Binding a credential to one tenant limits the blast radius of a leaked token to that tenant. Leaving
            // it null preserves the older shared-app behaviour for callers that still need it.
            'tenant_id' => ['nullable', 'string', 'exists:tenants,id'],
        ]);

        [$credential, $token] = AppServiceCredential::issueToken($app->id, $data['name'], $data['tenant_id'] ?? null);

        // The plaintext token is returned exactly once; Core only stores its digest.
        return response()->json(['data' => [
            'id' => $credential->id,
            'tenant_id' => $credential->tenant_id,
            'token' => $token,
        ]], 201);
    }
}
