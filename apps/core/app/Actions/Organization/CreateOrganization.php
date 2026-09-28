<?php

namespace App\Actions\Organization;

use App\Models\LegalEntity;
use App\Models\OperatingUnit;
use App\Models\Organization;
use App\Models\TenantMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateOrganization
{
    /** @param array{classification:string,name:string,company_code:?string,country_code:?string,operating_unit_type:?string,operating_unit_number?:?string} $data */
    public function handle(TenantMembership $actor, array $data): Organization
    {
        if (! $actor->canManageAccess()) {
            throw new AuthorizationException;
        }
        if ($data['classification'] === 'legal_entity'
            && LegalEntity::query()->where('tenant_id', $actor->tenant_id)->where('company_code', $data['company_code'])->exists()) {
            throw ValidationException::withMessages(['company_code' => 'Kode perusahaan sudah digunakan pada tenant ini.']);
        }
        $number = $data['classification'] === 'operating_unit' ? ($data['operating_unit_number'] ?? null) : null;
        if ($number !== null && OperatingUnit::query()->where('tenant_id', $actor->tenant_id)->where('number', $number)->exists()) {
            throw self::nomorDipakai();
        }

        try {
            return DB::transaction(function () use ($actor, $data, $number): Organization {
                $organization = Organization::create([
                    'tenant_id' => $actor->tenant_id,
                    'classification' => $data['classification'],
                    'name' => $data['name'],
                    'status' => 'active',
                ]);

                if ($data['classification'] === 'legal_entity') {
                    $organization->legalEntity()->create([
                        'tenant_id' => $actor->tenant_id,
                        'company_code' => $data['company_code'],
                        'country_code' => strtoupper((string) $data['country_code']),
                    ]);
                } else {
                    $organization->operatingUnit()->create([
                        'tenant_id' => $actor->tenant_id,
                        'type' => $data['operating_unit_type'],
                        'number' => $number,
                    ]);
                }

                return $organization->load(['legalEntity', 'operatingUnit']);
            });
        } catch (UniqueConstraintViolationException $exception) {
            // Dua permintaan yang lolos pemeriksaan di atas bersamaan: indeks unik yang memutuskan.
            // Transaksinya sudah dibatalkan seluruhnya, jadi tidak ada organisasi setengah jadi.
            if (str_contains($exception->getMessage(), 'operating_units_tenant_number_unique')) {
                throw self::nomorDipakai();
            }

            throw $exception;
        }
    }

    public static function nomorDipakai(): ValidationException
    {
        return ValidationException::withMessages([
            'operating_unit_number' => 'Nomor unit sudah dipakai operating unit lain pada tenant ini.',
        ]);
    }
}
