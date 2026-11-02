<?php

declare(strict_types=1);

namespace ControlPlane\Customers;

use ControlPlane\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class CreateOrganizationTenant
{
    /** @return array{tenant_id:string,membership_id:string,principal_id:int,replayed:bool} */
    public function __invoke(User $operator, int $ownerUserId, string $name, string $slug, string $idempotencyKey): array
    {
        try {
            $response = Http::withToken((string) config('core.token'))->acceptJson()
                ->timeout(max(1, (int) config('core.timeout')))
                ->post(rtrim((string) config('core.base_url'), '/').'/api/internal/v1/tenant-registrations', [
                    'requested_by' => (int) $operator->getAuthIdentifier(), 'owner_user_id' => $ownerUserId,
                    'name' => $name, 'slug' => $slug, 'idempotency_key' => $idempotencyKey,
                ]);
        } catch (ConnectionException $failure) {
            throw new CoreUnreachable('Core belum dapat dihubungi. Periksa koneksi lalu coba kembali.', previous: $failure);
        }

        if (! in_array($response->status(), [200, 201], true)) {
            throw new CustomerRejected('Organisasi belum dapat dibuat. Periksa akun pemilik, hak operator dan alamat organisasi.');
        }

        $data = $response->json();

        if (! is_array($data) || ! is_string($data['tenant_id'] ?? null) || ! Str::isUlid($data['tenant_id'])
            || ! is_string($data['membership_id'] ?? null) || ! Str::isUlid($data['membership_id'])
            || ($data['principal_id'] ?? null) !== $ownerUserId || ! is_bool($data['replayed'] ?? null)) {
            throw new CustomerRejected('Jawaban Core belum lengkap. Periksa hasil permintaan sebelum mencoba kembali.');
        }

        return ['tenant_id' => $data['tenant_id'], 'membership_id' => $data['membership_id'], 'principal_id' => $ownerUserId, 'replayed' => $data['replayed']];
    }
}
