<?php

namespace App\Http\Controllers;

use App\Services\ProvisionIndonesiaStarterData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class TenantProvisioningController
{
    public function store(Request $request, ProvisionIndonesiaStarterData $provisioner): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'string', 'max:80'],
            'type' => ['required', Rule::in(['core.tenant.provisioned.v1'])],
            'occurred_at' => ['required', 'date'],
            'tenant_id' => ['required', 'ulid'],
            'correlation_id' => ['required', 'ulid'],
            'data' => ['sometimes', 'array'],
            'data.app_ids' => ['required', 'array'],
            'data.app_ids.*' => ['string', 'max:80'],
            'data.template_key' => ['sometimes', 'string', 'max:160'],
        ]);

        if (! in_array((string) config('services.coreerp.app_id'), $data['data']['app_ids'], true)) {
            return response()->json(['data' => [
                'tenant_id' => $data['tenant_id'],
                'skipped' => true,
            ]]);
        }

        $result = $provisioner->forTenant($data['tenant_id'], $data['data']['template_key'] ?? null);

        return response()->json(['data' => [
            'tenant_id' => $data['tenant_id'],
            ...$result,
        ]]);
    }
}
