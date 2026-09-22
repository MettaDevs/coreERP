<?php

namespace App\Actions\Organization;

use App\Models\OperatingUnit;
use App\Models\Organization;
use App\Models\TenantMembership;
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
     * @param  array{name:string,company_code:?string,country_code:?string,operating_unit_type:?string,operating_unit_number?:?string}  $data
     */
    public function handle(TenantMembership $actor, Organization $organization, array $data): Organization
    {
        if (! $actor->canManageAccess() || $organization->tenant_id !== $actor->tenant_id) {
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
            return DB::transaction(function () use ($organization, $data, $gantiNomor, $number): Organization {
                $organization->update(['name' => $data['name']]);

                if ($organization->classification === 'legal_entity') {
                    $organization->legalEntity()->update([
                        'company_code' => $data['company_code'],
                        'country_code' => $data['country_code'],
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
