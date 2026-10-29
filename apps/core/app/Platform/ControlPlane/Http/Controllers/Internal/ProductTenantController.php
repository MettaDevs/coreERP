<?php

declare(strict_types=1);

namespace App\Platform\ControlPlane\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Platform\ControlPlane\Models\ManagedProductTenant;
use App\Platform\ControlPlane\Products\CreateProductTenant;
use App\Platform\ControlPlane\Products\ProductTenantProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;

final class ProductTenantController extends Controller
{
    public function catalog(): JsonResponse
    {
        $products = [];
        foreach (config('managed_products.products', []) as $code => $product) {
            $profiles = [];
            foreach ($product['profiles'] as $profile => $definition) {
                $profiles[] = array_merge(['code' => $profile], $definition);
            }
            $products[] = ['code' => $code, 'name' => $product['name'], 'profiles' => $profiles];
        }

        return response()->json(['products' => $products]);
    }

    public function index(Request $request): JsonResponse
    {
        $input = $request->validate(['search' => ['nullable', 'string', 'max:150'], 'cursor' => ['nullable', 'string', 'max:500'], 'limit' => ['nullable', 'integer', 'min:1', 'max:50']]);
        $query = ManagedProductTenant::query()->orderByDesc('created_at')->orderByDesc('id');
        if (! empty($input['search'])) {
            $query->where(fn ($query) => $query->where('name', 'ilike', '%'.$input['search'].'%')->orWhere('slug', 'ilike', '%'.$input['search'].'%'));
        }
        $page = $query->cursorPaginate($input['limit'] ?? 50, ['*'], 'cursor', $input['cursor'] ?? null);

        return response()->json(['data' => $page->getCollection()->map(fn (ManagedProductTenant $tenant) => $tenant->publicData())->all(), 'next_cursor' => $page->nextCursor()?->encode()]);
    }

    public function show(string $id): JsonResponse
    {
        $tenant = ManagedProductTenant::query()->findOrFail($id);
        $data = $tenant->publicData();
        $secret = $tenant->getConnection()->table('console_settings')->where('key', 'product-tenant:'.$id)->value('value');
        if (is_string($secret)) {
            $data['credentials'] = json_decode(Crypt::decryptString($secret), true, 512, JSON_THROW_ON_ERROR);
        }

        return response()->json($data)->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, ProductTenantProvisioner $provisioner, CreateProductTenant $creator): JsonResponse
    {
        $products = config('managed_products.products', []);
        $input = $request->validate([
            'product' => ['required', 'string', Rule::in(array_keys($products))],
            'profile' => ['required', 'string'],
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['required', 'string', 'min:3', 'max:63', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::notIn(['www', 'admin', 'api', 'auth', 'sso', 'mail', 'demo', 'support', 'trial-demo'])],
            'owner_name' => ['required', 'string', 'max:100'],
            'owner_email' => ['required', 'email', 'max:250'],
            'idempotency_key' => ['required', 'uuid'],
            'expires_at' => ['prohibited'],
            'requested_by' => ['required', 'integer', 'exists:users,id'],
        ]);
        $this->operator($input['requested_by']);
        $tenant = $creator->handle($input, $input['requested_by']);
        $provisioner->reconcile($tenant->id);

        return response()->json($tenant->fresh()->publicData(), 202);
    }

    public function retry(Request $request, string $id, ProductTenantProvisioner $provisioner): JsonResponse
    {
        $input = $request->validate(['requested_by' => ['required', 'integer', 'exists:users,id']]);
        $this->operator($input['requested_by']);
        $tenant = (new ManagedProductTenant)->getConnection()->transaction(function () use ($id, $input, $provisioner): ManagedProductTenant {
            $tenant = ManagedProductTenant::query()->lockForUpdate()->findOrFail($id);
            abort_unless($tenant->status === 'failed', 409, 'Hanya penyiapan yang gagal yang dapat dicoba lagi.');
            $tenant->update(['status' => 'queued', 'failure_message' => null, 'retry_requested' => true, 'lease_until' => null]);
            $provisioner->audit($tenant, 'product-tenant.retry', $input['requested_by']);

            return $tenant;
        });
        $provisioner->reconcile($tenant->id);

        return response()->json($tenant->fresh()->publicData(), 202);
    }

    private function operator(int $id): void
    {
        abort_unless((new ManagedProductTenant)->getConnection()->table('provider_access')->where('user_id', $id)->where('role', 'provider_admin')->exists(), 404);
    }
}
