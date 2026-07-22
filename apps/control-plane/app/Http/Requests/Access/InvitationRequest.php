<?php

namespace App\Http\Requests\Access;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'system_role' => ['required', Rule::in(['user', 'admin'])],
            'role_ids' => ['present', 'array'],
            'role_ids.*' => ['required', 'string'],
            'organization_id' => ['nullable', 'string'],
            'hierarchy_id' => [Rule::requiredIf($this->boolean('include_descendants')), 'nullable', 'string'],
            'include_descendants' => ['required', 'boolean'],
        ];
    }

    /** @return array{system_role:string,role_ids:list<string>,organization_id:?string,hierarchy_id:?string,include_descendants:bool} */
    public function payload(): array
    {
        return [
            'system_role' => $this->string('system_role')->toString(),
            'role_ids' => array_values($this->collect('role_ids')->map(fn (mixed $id): string => (string) $id)->all()),
            'organization_id' => $this->string('organization_id')->toString() ?: null,
            'hierarchy_id' => $this->string('hierarchy_id')->toString() ?: null,
            'include_descendants' => $this->boolean('include_descendants'),
        ];
    }
}
