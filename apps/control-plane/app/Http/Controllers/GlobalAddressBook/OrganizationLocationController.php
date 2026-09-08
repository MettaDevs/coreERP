<?php

namespace App\Http\Controllers\GlobalAddressBook;

use App\Http\Controllers\Controller;
use App\Models\CountryRegion;
use App\Models\Organization;
use App\Models\PartyLocation;
use App\Support\AddressBook\OrganizationAddressBook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Alamat utama dan cabang satu organisasi (padanan Addresses pada legal entity
 * Dynamics 365). Dibaca semua anggota tenant, diubah oleh admin tenant. Alamat utama
 * yang tersimpan di sini adalah yang tampil pada kop dokumen.
 */
class OrganizationLocationController extends Controller
{
    public function __construct(private readonly OrganizationAddressBook $addressBook) {}

    public function index(Request $request, Organization $organization): JsonResponse
    {
        $this->guardOrganization($request, $organization);

        return response()->json([
            'data' => $this->addressBook->locations($organization),
            'meta' => [
                'purposes' => PartyLocation::PURPOSES,
                'countries' => CountryRegion::query()->orderBy('name')->get(['code', 'name']),
            ],
        ]);
    }

    public function store(Request $request, Organization $organization): JsonResponse
    {
        $this->guardOrganization($request, $organization, manage: true);

        return response()->json(['data' => $this->addressBook->saveLocation($organization, $this->validated($request))], 201);
    }

    public function update(Request $request, Organization $organization, string $location): JsonResponse
    {
        $this->guardOrganization($request, $organization, manage: true);

        return response()->json(['data' => $this->addressBook->saveLocation($organization, $this->validated($request), $location)]);
    }

    public function destroy(Request $request, Organization $organization, string $location): Response
    {
        $this->guardOrganization($request, $organization, manage: true);
        $this->addressBook->deleteLocation($organization, $location);

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        // Kode negara dinormalkan sebelum diperiksa ke tabel, supaya "id" dan "ID" sama.
        $request->merge(['country_region_code' => strtoupper((string) $request->input('country_region_code'))]);

        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'purpose' => ['required', Rule::in(PartyLocation::PURPOSES)],
            'is_primary' => ['sometimes', 'boolean'],
            'country_region_code' => ['required', 'string', 'size:2', Rule::exists('country_regions', 'code')],
            'province' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'street' => ['nullable', 'string', 'max:250'],
            'building' => ['nullable', 'string', 'max:120'],
            'postbox' => ['nullable', 'string', 'max:60'],
            'postal_code' => ['nullable', 'string', 'max:20'],
        ]);
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
