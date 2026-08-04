<?php

namespace App\Http\Requests\Access;

use Illuminate\Foundation\Http\FormRequest;

class InvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-access') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'system_role' => ['required', 'in:user,admin'],
            'assignments' => ['present', 'array'],
            'assignments.*.role_id' => ['required', 'string'],
            'assignments.*.policy_scopes' => ['present', 'array'],
            'assignments.*.policy_scopes.*.policy_code' => ['required', 'string'],
            'assignments.*.policy_scopes.*.legal_entity_id' => ['nullable', 'string'],
            'assignments.*.policy_scopes.*.organization_id' => ['nullable', 'string'],
            'assignments.*.policy_scopes.*.hierarchy_id' => ['nullable', 'string'],
            'assignments.*.policy_scopes.*.include_descendants' => ['required', 'boolean'],
        ];
    }

    /** @return array{system_role:string,assignments:list<array{role_id:string,policy_scopes:list<array{policy_code:string,legal_entity_id:?string,organization_id:?string,hierarchy_id:?string,include_descendants:bool}>}>} */
    public function payload(): array
    {
        return [
            'system_role' => $this->string('system_role')->toString(),
            'assignments' => $this->collect('assignments')->map(fn (mixed $assignment): array => [
                'role_id' => (string) data_get($assignment, 'role_id'),
                'policy_scopes' => collect(data_get($assignment, 'policy_scopes', []))->map(fn (mixed $scope): array => [
                    'policy_code' => (string) data_get($scope, 'policy_code'),
                    'legal_entity_id' => data_get($scope, 'legal_entity_id') ?: null,
                    'organization_id' => data_get($scope, 'organization_id') ?: null,
                    'hierarchy_id' => data_get($scope, 'hierarchy_id') ?: null,
                    'include_descendants' => (bool) data_get($scope, 'include_descendants'),
                ])->values()->all(),
            ])->values()->all(),
        ];
    }
}
