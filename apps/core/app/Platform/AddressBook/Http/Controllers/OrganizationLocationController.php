<?php

namespace App\Platform\AddressBook\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Access\Support\CoreSecurityCatalog;
use App\Platform\AddressBook\Models\LocationPurpose;
use App\Platform\AddressBook\Support\OrganizationAddressBook;
use App\Platform\AddressBook\Support\PartyDetailData;
use App\Platform\Geography\Models\CountryRegion;
use App\Platform\Modules\Contracts\RowVersion;
use App\Platform\Organization\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Alamat utama dan cabang satu organisasi (padanan Addresses pada legal entity
 * Dynamics 365). Dibaca semua anggota tenant, diubah oleh admin tenant. Alamat utama
 * yang tersimpan di sini adalah yang tampil pada kop dokumen.
 *
 * Id alamat adalah id tautan organisasi ke tempat. Satu tempat dapat dipakai beberapa
 * organisasi: `location_id` pada permintaan simpan menautkan tempat yang sudah ada.
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
                'purposes' => LocationPurpose::query()->orderBy('sort_order')->get(['code', 'name']),
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

        $data = $this->validated($request);

        return response()->json(['data' => $this->addressBook->saveLocation($organization, $data, $location, RowVersion::expected($request))]);
    }

    public function destroy(Request $request, Organization $organization, string $location): Response
    {
        $this->guardOrganization($request, $organization, manage: true);
        $this->addressBook->deleteLocation($organization, $location, RowVersion::expected($request));

        return response()->noContent();
    }

    /** Tempat beralamat milik tenant yang dapat ditautkan ke organisasi ini. */
    public function sharable(Request $request, Organization $organization): JsonResponse
    {
        $this->guardOrganization($request, $organization, manage: true);
        $search = trim((string) ($request->validate(['q' => ['nullable', 'string', 'max:100'], 'cursor' => ['nullable', 'string', 'max:2000']])['q'] ?? ''));

        return response()->json($this->addressBook->sharableLocations($organization->tenant_id, $search, $this->addressBook->party($organization)->id));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return PartyDetailData::location($request->all());
    }

    private function guardOrganization(Request $request, Organization $organization, bool $manage = false): void
    {
        $membership = $this->currentMembership($request);
        abort_unless($organization->tenant_id === $membership->tenant_id, 404);
        if ($manage) {
            abort_unless($membership->hasCorePermission(CoreSecurityCatalog::ORGANIZATION_UPDATE), 403);
        }
    }
}
