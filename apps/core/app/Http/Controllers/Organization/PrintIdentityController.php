<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Support\Reporting\PrintIdentityStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Identitas cetak satu organisasi: kop, footer, dan logo. Dibaca semua anggota tenant
 * (dialog cetak dan pratinjau memerlukannya), diubah oleh admin tenant. Alamat dan
 * kontak pada jawabannya hanya bacaan dari buku alamat organisasi.
 */
class PrintIdentityController extends Controller
{
    public function __construct(private readonly PrintIdentityStore $identities) {}

    public function show(Request $request, Organization $organization): JsonResponse
    {
        $this->guardOrganization($request, $organization);

        return response()->json([
            'data' => $this->identities->get($organization->tenant_id, $organization->id)
                ?? $this->identities->resolve($organization->tenant_id, $organization->id, null),
            'meta' => ['positions' => PrintIdentityStore::POSITIONS, 'max_logos' => PrintIdentityStore::MAX_LOGOS, 'placeholders' => $this->identities->catalog()],
        ]);
    }

    public function update(Request $request, Organization $organization): JsonResponse
    {
        $this->guardOrganization($request, $organization, manage: true);
        $data = $request->validate([
            'display_name' => ['nullable', 'string', 'max:200'],
            'parent_lines' => ['nullable', 'array', 'max:3'],
            'parent_lines.*' => ['nullable', 'string', 'max:200'],
            // Alamat dan kontak tidak diterima di sini; ubah lewat bagian Alamat dan
            // Informasi kontak organisasi.
            'tax_id' => ['nullable', 'string', 'max:60'],
            'registration_id' => ['nullable', 'string', 'max:60'],
            'footer_text' => ['nullable', 'string', 'max:1000'],
        ]);

        return response()->json(['data' => $this->identities->save($organization, $data)]);
    }

    public function storeLogo(Request $request, Organization $organization): JsonResponse
    {
        $this->guardOrganization($request, $organization, manage: true);
        $data = $request->validate([
            // PNG dan JPEG saja: keduanya yang dimengerti Word, Excel, dan LibreOffice tanpa
            // konversi, dan tidak dapat membawa skrip seperti SVG.
            'file' => ['required', 'file', 'mimes:png,jpg,jpeg', 'max:2048'],
            'position' => ['required', Rule::in(PrintIdentityStore::POSITIONS)],
            'width_mm' => ['nullable', 'integer', 'min:8', 'max:80'],
        ]);

        return response()->json([
            'data' => $this->identities->addLogo($organization, $request->file('file'), $data['position'], (int) ($data['width_mm'] ?? PrintIdentityStore::DEFAULT_WIDTH_MM)),
        ], 201);
    }

    public function updateLogo(Request $request, Organization $organization, string $logo): JsonResponse
    {
        $this->guardOrganization($request, $organization, manage: true);
        $data = $request->validate([
            'position' => ['nullable', Rule::in(PrintIdentityStore::POSITIONS)],
            'width_mm' => ['nullable', 'integer', 'min:8', 'max:80'],
        ]);

        return response()->json(['data' => $this->identities->updateLogo($organization, $logo, $data['position'] ?? null, isset($data['width_mm']) ? (int) $data['width_mm'] : null)]);
    }

    public function destroyLogo(Request $request, Organization $organization, string $logo): JsonResponse
    {
        $this->guardOrganization($request, $organization, manage: true);

        return response()->json(['data' => $this->identities->removeLogo($organization, $logo)]);
    }

    public function logo(Request $request, Organization $organization, string $logo): StreamedResponse
    {
        $this->guardOrganization($request, $organization);
        $file = $this->identities->logoFile($organization->tenant_id, $organization->id, $logo);
        abort_if($file === null, 404);

        return $this->identities->disk()->response($file['path'], null, ['Content-Type' => $file['mime'], 'Cache-Control' => 'private, max-age=300']);
    }

    private function guardOrganization(Request $request, Organization $organization, bool $manage = false): void
    {
        $membership = $this->currentMembership($request);
        abort_unless($organization->tenant_id === $membership->tenant_id, 404);
        if ($manage) {
            abort_unless($membership->canManageAccess(), 403);
        }
    }
}
