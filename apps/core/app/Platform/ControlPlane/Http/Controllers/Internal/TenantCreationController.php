<?php

declare(strict_types=1);

namespace App\Platform\ControlPlane\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Platform\ControlPlane\Models\ProductCustomer;
use App\Platform\ControlPlane\Products\ProductRegistration;
use App\Platform\ControlPlane\Products\ResolveProductPrincipal;
use App\Platform\ControlPlane\Tenants\CreateTenant;
use App\Platform\Identity\Models\User;
use App\Platform\Tenant\Support\TenantCreation;
use App\Platform\Tenant\Support\TenantCreator;
use App\Platform\Tenant\Support\TenantDetails;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class TenantCreationController extends Controller
{
    public function operator(Request $request, CreateTenant $tenants): JsonResponse
    {
        $input = $request->validate([
            ...$this->rules(),
            'requested_by' => ['required', 'integer', Rule::exists(User::class, 'id')],
            'owner_user_id' => ['required', 'integer', Rule::exists(User::class, 'id')],
        ]);
        $operator = User::query()->findOrFail((int) $input['requested_by']);
        $owner = User::query()->findOrFail((int) $input['owner_user_id']);

        return $this->respond($tenants->handle(TenantCreator::operator($operator, $owner), $this->details($input)));
    }

    public function customer(Request $request, ProductRegistration $registration, CreateTenant $tenants, ResolveProductPrincipal $principals): JsonResponse
    {
        $customer = $registration->customer($request->header('X-Registration-Session'));
        abort_unless($customer !== null, 401, 'Silakan masuk dengan SSO.');
        $input = $request->validate([
            ...$this->rules(),
            'requested_by' => ['prohibited'], 'owner_user_id' => ['prohibited'],
        ]);

        try {
            $creation = (new ProductCustomer)->getConnection()->transaction(function () use ($customer, $tenants, $principals, $input): TenantCreation {
                $principal = $principals->handle($customer);

                return $tenants->handle(TenantCreator::customer($principal, $customer->id), $this->details($input));
            });
        } catch (UniqueConstraintViolationException) {
            abort(409, 'Identitas berubah saat diproses. Muat ulang lalu coba kembali.');
        }

        return $this->respond($creation);
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'not_regex:/[\x00-\x1F\x7F]/'],
            'slug' => ['required', 'string', 'min:3', 'max:63', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
            'idempotency_key' => ['required', 'uuid'],
            'tenant_id' => ['prohibited'], 'client_id' => ['prohibited'], 'user_id' => ['prohibited'],
            'owner_customer_id' => ['prohibited'], 'provider_access' => ['prohibited'],
            'system_role' => ['prohibited'], 'role_ids' => ['prohibited'], 'status' => ['prohibited'],
            'app_ids' => ['prohibited'], 'first_environment' => ['prohibited'],
            'first_environment_hosting' => ['prohibited'], 'first_environment_expires_at' => ['prohibited'],
        ];
    }

    /** @param array<string, mixed> $input */
    private function details(array $input): TenantDetails
    {
        return new TenantDetails((string) $input['name'], (string) $input['idempotency_key'], (string) $input['slug']);
    }

    private function respond(TenantCreation $creation): JsonResponse
    {
        return response()->json([
            'tenant_id' => $creation->tenant->id,
            'membership_id' => $creation->membership->id,
            'principal_id' => $creation->principal->id,
            'replayed' => $creation->replayed,
        ], $creation->replayed ? 200 : 201);
    }
}
