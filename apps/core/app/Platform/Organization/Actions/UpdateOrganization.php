<?php

namespace App\Platform\Organization\Actions;

use App\Models\TenantMembership;
use App\Platform\Organization\Models\OperatingUnit;
use App\Platform\Organization\Models\Organization;
use App\Support\Access\CoreSecurityCatalog;
use App\Support\Modules\Contracts\RowVersion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class UpdateOrganization
{
    /**
     * Nomor unit hanya berubah bila kuncinya ada di `$data`. Lihat UpdateOrganizationRequest::payload().
     *
     * Nomor boleh diganti. Posting yang sudah terbit menyimpan nomor pada saat terbit, jadi mengganti
     * nomor tidak menulis ulang jurnal lama; yang berubah hanya posting berikutnya.
     *
     * `$expectedVersion` adalah versi organisasi yang dibuka penggunanya; entitas legal atau unitnya
     * ikut terkunci bersamanya.
     *
     * @param  array{name:string,company_code:?string,country_code:?string,timezone?:?string,operating_unit_type:?string,operating_unit_number?:?string}  $data
     */
    public function handle(TenantMembership $actor, Organization $organization, array $data, int $expectedVersion): Organization
    {
        if (! $actor->hasCorePermission(CoreSecurityCatalog::ORGANIZATION_UPDATE) || $organization->tenant_id !== $actor->tenant_id) {
            throw new AuthorizationException;
        }
        $gantiNomor = $organization->classification === 'operating_unit' && array_key_exists('operating_unit_number', $data);
        $number = $gantiNomor ? $data['operating_unit_number'] : null;
        if ($number !== null && OperatingUnit::query()
            ->where('tenant_id', $organization->tenant_id)
            ->where('number', $number)
            ->where('organization_id', '!=', $organization->id)
            ->exists()) {
            throw CreateOrganization::nomorDipakai();
        }

        try {
            return DB::transaction(function () use ($organization, $data, $gantiNomor, $number, $expectedVersion): Organization {
                RowVersion::claim($organization, $expectedVersion);
                $organization->update(['name' => $data['name']]);

                if ($organization->classification === 'legal_entity') {
                    $organization->legalEntity()->update([
                        'company_code' => $data['company_code'],
                        'country_code' => $data['country_code'],
                        ...(($data['timezone'] ?? null) !== null ? ['timezone' => $data['timezone']] : []),
                    ]);
                } else {
                    // `tenant_id` ikut ditulis setiap kali: operating unit yang lahir di rilis
                    // sebelumnya belum punya salinannya, dan indeks unik nomor berdiri di atasnya.
                    $organization->operatingUnit()->update([
                        'tenant_id' => $organization->tenant_id,
                        'type' => $data['operating_unit_type'],
                        ...($gantiNomor ? ['number' => $number] : []),
                    ]);
                }

                return $organization->fresh(['legalEntity', 'operatingUnit']);
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (str_contains($exception->getMessage(), 'operating_units_tenant_number_unique')) {
                throw CreateOrganization::nomorDipakai();
            }

            throw $exception;
        }
    }
}
