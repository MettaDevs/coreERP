<?php

namespace App\Http\Requests\Access;

use Illuminate\Foundation\Http\FormRequest;

class InvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-access') ?? false;
    }

    /**
     * Menerima dua bentuk. Bentuk tunggal (`system_role` + `assignments`)
     * dipertahankan untuk API. Grid pada UI mengirim `codes`: satu baris satu
     * kode undangan, sehingga admin tidak perlu membuatnya satu per satu.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $prefix = $this->has('codes') ? 'codes.*.' : '';

        return array_merge($this->has('codes') ? ['codes' => ['required', 'array', 'min:1']] : [], [
            $prefix.'system_role' => ['required', 'in:user,admin'],
            $prefix.'label' => ['nullable', 'string', 'max:120'],
            // Kosong berarti kode anonim seperti sebelum undangan SSO ada. Terisi berarti
            // undangan untuk satu orang, dan `CreateInvitation` yang memastikan orangnya memang
            // ada di penyedia — di sini hanya bentuknya yang diperiksa.
            $prefix.'sso_email' => ['nullable', 'string', 'lowercase', 'email', 'max:255'],
            $prefix.'assignments' => ['present', 'array'],
            $prefix.'assignments.*.role_id' => ['required', 'string'],
            $prefix.'assignments.*.policy_scopes' => ['present', 'array'],
            $prefix.'assignments.*.policy_scopes.*.policy_code' => ['required', 'string'],
            $prefix.'assignments.*.policy_scopes.*.legal_entity_id' => ['nullable', 'string'],
            $prefix.'assignments.*.policy_scopes.*.organization_id' => ['nullable', 'string'],
            $prefix.'assignments.*.policy_scopes.*.hierarchy_id' => ['nullable', 'string'],
            $prefix.'assignments.*.policy_scopes.*.include_descendants' => ['required', 'boolean'],
            $prefix.'assignments.*.policy_scopes.*.unrestricted' => ['nullable', 'boolean'],
        ]);
    }

    /** @return array{system_role:string,sso_email:?string,assignments:list<array<string, mixed>>} */
    public function payload(): array
    {
        return $this->payloads()[0];
    }

    /**
     * Seluruh kode yang diminta. Bentuk tunggal menghasilkan satu elemen.
     *
     * @return list<array{system_role:string,sso_email:?string,assignments:list<array<string, mixed>>}>
     */
    public function payloads(): array
    {
        $rows = $this->has('codes') ? $this->collect('codes')->all() : [$this->all()];

        return collect($rows)->map(fn (mixed $row): array => [
            'system_role' => (string) data_get($row, 'system_role'),
            'label' => data_get($row, 'label') ?: null,
            'sso_email' => data_get($row, 'sso_email') ?: null,
            'assignments' => collect(data_get($row, 'assignments', []))->map(fn (mixed $assignment): array => [
                'role_id' => (string) data_get($assignment, 'role_id'),
                'policy_scopes' => collect(data_get($assignment, 'policy_scopes', []))->map(fn (mixed $scope): array => [
                    'policy_code' => (string) data_get($scope, 'policy_code'),
                    'legal_entity_id' => data_get($scope, 'legal_entity_id') ?: null,
                    'organization_id' => data_get($scope, 'organization_id') ?: null,
                    'hierarchy_id' => data_get($scope, 'hierarchy_id') ?: null,
                    'include_descendants' => (bool) data_get($scope, 'include_descendants'),
                    'unrestricted' => (bool) data_get($scope, 'unrestricted'),
                ])->values()->all(),
            ])->values()->all(),
        ])->values()->all();
    }
}
