<?php

declare(strict_types=1);

namespace ControlPlane\Http\Controllers\Products;

use ControlPlane\Http\Controllers\Controller;
use ControlPlane\Models\User;
use ControlPlane\Products\ProductTenants;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ProductTenantActions extends Controller
{
    public function store(Request $request, ProductTenants $core): RedirectResponse
    {
        $input = $request->validate([
            'product' => ['required', 'string', 'max:80'],
            'profile' => ['required', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['required', 'string', 'min:3', 'max:63', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'owner_name' => ['required', 'string', 'max:100'],
            'owner_email' => ['required', 'email', 'max:250'],
            'expires_at' => ['prohibited'],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        $operator = $request->user();
        abort_unless($operator instanceof User, 404);
        $row = $core->request('POST', 'product-tenants', [...$input, 'requested_by' => (int) $operator->id]);

        if (! is_string($row['id'] ?? null)) {
            throw ValidationException::withMessages(['core' => 'Tenant mungkin sudah dibuat. Periksa daftar tenant produk sebelum mencoba lagi.']);
        }

        return redirect('/produk/tenant/'.$row['id']);
    }

    public function retry(Request $request, string $tenant, ProductTenants $core): RedirectResponse
    {
        $operator = $request->user();
        abort_unless($operator instanceof User, 404);
        $core->request('POST', 'product-tenants/'.$tenant.'/retry', ['requested_by' => (int) $operator->id]);

        return redirect('/produk/tenant/'.$tenant)->with('message', 'Penyiapan tenant diminta kembali.');
    }
}
