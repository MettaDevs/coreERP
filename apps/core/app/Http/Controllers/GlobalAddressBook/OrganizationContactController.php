<?php

namespace App\Http\Controllers\GlobalAddressBook;

use App\Http\Controllers\Controller;
use App\Models\ElectronicAddress;
use App\Models\Organization;
use App\Support\AddressBook\OrganizationAddressBook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Informasi kontak satu organisasi (padanan Contact information Dynamics 365): email,
 * telepon, WhatsApp, faks, dan laman. Kontak utama tiap jenis yang tampil pada kop.
 */
class OrganizationContactController extends Controller
{
    public function __construct(private readonly OrganizationAddressBook $addressBook) {}

    public function index(Request $request, Organization $organization): JsonResponse
    {
        $this->guardOrganization($request, $organization);

        return response()->json([
            'data' => $this->addressBook->contacts($organization),
            'meta' => ['types' => ElectronicAddress::TYPES],
        ]);
    }

    public function store(Request $request, Organization $organization): JsonResponse
    {
        $this->guardOrganization($request, $organization, manage: true);

        return response()->json(['data' => $this->addressBook->saveContact($organization, $this->validated($request))], 201);
    }

    public function update(Request $request, Organization $organization, string $contact): JsonResponse
    {
        $this->guardOrganization($request, $organization, manage: true);

        return response()->json(['data' => $this->addressBook->saveContact($organization, $this->validated($request), $contact)]);
    }

    public function destroy(Request $request, Organization $organization, string $contact): Response
    {
        $this->guardOrganization($request, $organization, manage: true);
        $this->addressBook->deleteContact($organization, $contact);

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'type' => ['required', Rule::in(ElectronicAddress::TYPES)],
            'value' => ['required', 'string', 'max:250', ...($request->input('type') === 'email' ? ['email'] : [])],
            'purpose' => ['nullable', 'string', 'max:30'],
            'is_primary' => ['sometimes', 'boolean'],
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
