<?php

namespace App\Foundation\AddressBook\Http\Controllers;

use App\Foundation\AddressBook\Models\LocationPurpose;
use App\Foundation\AddressBook\Support\OrganizationAddressBook;
use App\Foundation\Geography\Models\CountryRegion;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Support\Access\CoreSecurityCatalog;
use App\Support\Modules\Contracts\RowVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

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
        $search = trim((string) ($request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? ''));

        return response()->json(['data' => $this->addressBook->sharableLocations($organization->tenant_id, $search)]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        // Kode negara dinormalkan sebelum diperiksa ke tabel, supaya "id" dan "ID" sama.
        if ($request->filled('country_region_code')) {
            $request->merge(['country_region_code' => strtoupper((string) $request->input('country_region_code'))]);
        }
        // Satu kegunaan (`purpose`) tetap diterima; ia kegunaan pertama alamat itu.
        if (! $request->has('purposes') && $request->filled('purpose')) {
            $request->merge(['purposes' => [$request->input('purpose')]]);
        }
        $linking = $request->filled('location_id');

        $data = $request->validate([
            'location_id' => ['nullable', 'string', 'size:26'],
            'name' => [$linking ? 'nullable' : 'required', 'string', 'max:120'],
            'purposes' => ['required', 'array', 'min:1'],
            'purposes.*' => ['string', 'distinct', Rule::exists('location_purposes', 'code')],
            'is_primary' => ['sometimes', 'boolean'],
            'country_region_code' => [$linking ? 'nullable' : 'required', 'string', 'size:2', Rule::exists('country_regions', 'code')],
            'province' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'street' => ['nullable', 'string', 'max:250'],
            'building' => ['nullable', 'string', 'max:120'],
            'postbox' => ['nullable', 'string', 'max:60'],
            'postal_code' => ['nullable', 'string', 'max:20'],
        ]);
        $data['purposes'] = array_values(array_unique($data['purposes']));

        return $data;
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
