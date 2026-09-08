<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Apperp\ManagementAset\Services\ProvisionIndonesiaStarterData;

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
        ]);

        if (! in_array((string) config('services.coreerp.app_id'), $data['data']['app_ids'], true)) {
            return response()->json(['data' => [
                'tenant_id' => $data['tenant_id'],
                'skipped' => true,
            ]]);
        }

        // Versi template tidak diambil dari payload. `core.tenant.provisioned.v1`
        // mendeklarasikan `data` dengan `additionalProperties: false` dan hanya
        // `app_ids`, jadi field lain tidak akan pernah tiba lewat jalur yang sah;
        // menerimanya di sini hanya mengiklankan kemampuan yang tidak ada. Pemilihan
        // template menunggu versi envelope berikutnya, dan saat itu kontrak Core dan
        // kontrak app ini harus berubah bersama.
        $result = $provisioner->forTenant($data['tenant_id']);

        return response()->json(['data' => [
            'tenant_id' => $data['tenant_id'],
            ...$result,
        ]]);
    }
}
