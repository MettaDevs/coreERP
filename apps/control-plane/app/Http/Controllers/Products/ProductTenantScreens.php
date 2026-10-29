<?php

declare(strict_types=1);

namespace ControlPlane\Http\Controllers\Products;

use ControlPlane\Http\Controllers\Controller;
use ControlPlane\Products\ProductTenants;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class ProductTenantScreens extends Controller
{
    public function index(Request $request, ProductTenants $core): Response|JsonResponse
    {
        if ($request->wantsJson()) {
            $input = $request->validate(['search' => ['nullable', 'string', 'max:150'], 'cursor' => ['nullable', 'string', 'max:2048']]);

            return response()->json($core->request('GET', 'product-tenants', [...$input, 'limit' => 50]));
        }

        try {
            $catalog = $core->request('GET', 'managed-products');
        } catch (ValidationException $exception) {
            return Inertia::render('products/index', [
                'products' => [],
                'catalogError' => 'Pilihan produk belum dapat dimuat. Periksa koneksi Core lalu muat ulang halaman.',
            ]);
        }

        return Inertia::render('products/index', ['products' => $catalog['products'] ?? [], 'catalogError' => null]);
    }

    public function show(Request $request, string $tenant, ProductTenants $core): HttpResponse
    {
        Inertia::encryptHistory();
        $response = Inertia::render('products/show', [
            'tenant' => fn (): array => $core->request('GET', 'product-tenants/'.$tenant),
            'products' => fn (): mixed => $core->request('GET', 'managed-products')['products'],
        ])->toResponse($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
