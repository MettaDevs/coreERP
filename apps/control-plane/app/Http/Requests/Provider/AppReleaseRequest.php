<?php

namespace App\Http\Requests\Provider;

use Illuminate\Foundation\Http\FormRequest;

class AppReleaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-app-catalog') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $identifier = ['required', 'string', 'max:120', 'regex:/^[a-z0-9][a-z0-9-]*$/'];

        return [
            'version' => ['required', 'string', 'max:40', 'regex:/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.-]+)?$/'],
            'manifest_sha256' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
            'api_image' => ['required', 'string', 'max:500', 'regex:/^.+@sha256:[a-f0-9]{64}$/'],
            'ui_image' => ['required', 'string', 'max:500', 'regex:/^.+@sha256:[a-f0-9]{64}$/'],
            'bundle_path' => ['required', 'string', 'max:255', 'regex:#^(?!.*\.\.)[A-Za-z0-9_./-]+$#'],
            'compose_file' => ['required', 'string', 'max:120', 'regex:#^(?!.*\.\.)[A-Za-z0-9_./-]+\.ya?ml$#'],
            'compose_project' => $identifier,
            'api_service' => $identifier,
            'ui_service' => $identifier,
            'database_service' => $identifier,
        ];
    }

    /** @return array<string, string> */
    public function payload(): array
    {
        return [
            'version' => $this->string('version')->toString(),
            'manifest_sha256' => $this->string('manifest_sha256')->lower()->toString(),
            'api_image' => $this->string('api_image')->toString(),
            'ui_image' => $this->string('ui_image')->toString(),
            'bundle_path' => trim($this->string('bundle_path')->toString(), '/'),
            'compose_file' => $this->string('compose_file')->toString(),
            'compose_project' => $this->string('compose_project')->toString(),
            'api_service' => $this->string('api_service')->toString(),
            'ui_service' => $this->string('ui_service')->toString(),
            'database_service' => $this->string('database_service')->toString(),
        ];
    }
}
