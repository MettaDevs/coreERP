<?php

namespace App\Reporting;

use Illuminate\Http\Request;

/**
 * Konteks tepercaya yang dibawa satu permintaan laporan: tenant, organisasi aktif,
 * pengguna, hak akses, dan data policy dari token Web Shell.
 *
 * Ia dapat dibekukan ke array dan dihidupkan kembali di worker. Dataset tetap dijalankan
 * lewat {@see OrganizationScope} yang membaca `Request`, jadi kelas ini juga dapat
 * menyusun `Request` tiruan dengan atribut yang sama seperti yang dipasang middleware.
 */
final class ReportContext
{
    /**
     * @param  list<string>  $permissions
     * @param  array<string, mixed>  $dataPolicies
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly ?string $legalEntityId,
        public readonly ?string $orgUnitId,
        public readonly string $userId,
        public readonly array $permissions,
        public readonly array $dataPolicies,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            (string) $request->attributes->get('coreerp.tenant_id'),
            $request->attributes->get('coreerp.legal_entity_id'),
            $request->attributes->get('coreerp.org_unit_id'),
            (string) $request->attributes->get('coreerp.user_id'),
            $request->attributes->get('coreerp.permissions', []),
            $request->attributes->get('coreerp.data_policies', []),
        );
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['tenant_id'],
            $data['legal_entity_id'] ?? null,
            $data['org_unit_id'] ?? null,
            (string) $data['user_id'],
            array_values(array_filter($data['permissions'] ?? [], 'is_string')),
            is_array($data['data_policies'] ?? null) ? $data['data_policies'] : [],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'legal_entity_id' => $this->legalEntityId,
            'org_unit_id' => $this->orgUnitId,
            'user_id' => $this->userId,
            'permissions' => $this->permissions,
            'data_policies' => $this->dataPolicies,
        ];
    }

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    /** Request tiruan dengan atribut persis seperti yang dipasang `RequireCoreErpContext`. */
    public function request(): Request
    {
        $request = new Request;
        $request->attributes->set('coreerp.tenant_id', $this->tenantId);
        $request->attributes->set('coreerp.legal_entity_id', $this->legalEntityId);
        $request->attributes->set('coreerp.org_unit_id', $this->orgUnitId);
        $request->attributes->set('coreerp.user_id', $this->userId);
        $request->attributes->set('coreerp.permissions', $this->permissions);
        $request->attributes->set('coreerp.data_policies', $this->dataPolicies);

        return $request;
    }
}
