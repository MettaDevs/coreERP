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
        $identifier = ['string', 'max:120', 'regex:/^[a-z0-9][a-z0-9-]*$/'];

        return [
            'version' => ['required', 'string', 'max:40', 'regex:/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.-]+)?$/'],
            'manifest_sha256' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
            // Satu image edisi menggantikan pasangan image API dan UI: modul berjalan di
            // dalam runtime Core dan UI-nya ikut dibangun ke dalam shell, jadi hanya ada
            // satu artifact yang bisa disebut sidik jarinya.
            'edition_image' => ['required', 'string', 'max:500', 'regex:/^.+@sha256:[a-f0-9]{64}$/'],
            'bundle_path' => ['required', 'string', 'max:255', 'regex:#^(?!.*\.\.)[A-Za-z0-9_./-]+$#'],
            'compose_file' => ['required', 'string', 'max:120', 'regex:#^(?!.*\.\.)[A-Za-z0-9_./-]+\.ya?ml$#'],
            'compose_project' => ['required', ...$identifier],
            // Nama layanan tidak lagi wajib. Ia hanya berarti untuk app yang masih berjalan
            // sebagai container sendiri; edisi satu image tidak punya layanan API, UI,
            // maupun database yang terpisah untuk disebut namanya.
            'api_service' => ['nullable', ...$identifier],
            'ui_service' => ['nullable', ...$identifier],
            'database_service' => ['nullable', ...$identifier],
        ];
    }

    /** @return array<string, string|null> */
    public function payload(): array
    {
        return [
            'version' => $this->string('version')->toString(),
            'manifest_sha256' => $this->string('manifest_sha256')->lower()->toString(),
            'edition_image' => $this->string('edition_image')->toString(),
            'bundle_path' => trim($this->string('bundle_path')->toString(), '/'),
            'compose_file' => $this->string('compose_file')->toString(),
            'compose_project' => $this->string('compose_project')->toString(),
            'api_service' => $this->namaLayanan('api_service'),
            'ui_service' => $this->namaLayanan('ui_service'),
            'database_service' => $this->namaLayanan('database_service'),
        ];
    }

    /** Nama layanan yang tidak disebutkan disimpan sebagai null, bukan string kosong. */
    private function namaLayanan(string $kunci): ?string
    {
        $nilai = $this->string($kunci)->trim()->toString();

        return $nilai === '' ? null : $nilai;
    }
}
