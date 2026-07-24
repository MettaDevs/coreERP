<?php

namespace App\Http\Requests\Provider;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AppCatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-app-catalog') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'id' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9][a-z0-9-]*$/', Rule::unique('apps', 'id')],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'version' => ['required', 'string', 'max:40', 'regex:/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.-]+)?$/'],
            'database_name' => ['required', 'string', 'max:120', 'regex:/^[a-z][a-z0-9_]*$/'],
            'ui_entry' => ['nullable', 'string', 'max:2048', 'regex:/^\/(?!\/)/'],
            'repository_url' => ['nullable', 'url', 'max:2048', 'starts_with:https://'],
            'contract_url' => ['nullable', 'url', 'max:2048', 'starts_with:https://'],
        ];
    }

    /** @return array{id:string,name:string,description:?string,version:string,database_name:string,ui_entry:?string,repository_url:?string,contract_url:?string,status:string} */
    public function payload(): array
    {
        return [
            'id' => $this->string('id')->toString(),
            'name' => $this->string('name')->trim()->toString(),
            'description' => $this->string('description')->trim()->toString() ?: null,
            'version' => $this->string('version')->toString(),
            'database_name' => $this->string('database_name')->toString(),
            'ui_entry' => $this->string('ui_entry')->toString() ?: null,
            'repository_url' => $this->string('repository_url')->toString() ?: null,
            'contract_url' => $this->string('contract_url')->toString() ?: null,
            'status' => 'available',
        ];
    }
}
