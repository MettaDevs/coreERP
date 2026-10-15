<?php

namespace App\Foundation\Vendor\Http\Controllers;

use App\Foundation\NumberSequence\Models\NumberSequenceReference;
use App\Foundation\NumberSequence\Models\TenantNumberSequence;
use App\Foundation\NumberSequence\Support\CoreNumberSequences;
use App\Foundation\Vendor\Actions\SaveVendor;
use App\Foundation\Vendor\Models\Vendor;
use App\Foundation\Vendor\Support\SupplierCsvImporter;
use App\Foundation\Vendor\Support\VendorActivity;
use App\Http\Controllers\Controller;
use App\Platform\Access\Support\CoreSecurityCatalog;
use App\Platform\AddressBook\Models\Party;
use App\Platform\Analytics\Query\AnalyticsQueryException;
use App\Platform\Identity\Support\UserClock;
use App\Platform\Modules\Contracts\RowVersion;
use App\Platform\Organization\Models\Organization;
use App\Platform\Tenant\Models\TenantMembership;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Vendor master (K-06, TODO 2.4). Dilihat semua anggota tenant, dibuat dan diubah owner/admin.
 */
final class VendorController extends Controller
{
    private const PER_PAGE = 50;

    public function activity(Request $request, Vendor $vendor, VendorActivity $activity, UserClock $clock): JsonResponse
    {
        $membership = $this->authorizedMembership($request, CoreSecurityCatalog::VENDOR_READ);
        abort_unless($vendor->tenant_id === $membership->tenant_id, 404);

        try {
            return response()->json(['data' => $activity->forVendor($vendor, $membership, $clock->timezone($request))]);
        } catch (AnalyticsQueryException $exception) {
            return $exception->toResponse();
        }
    }

    public function importSuppliers(Request $request, SupplierCsvImporter $importer): JsonResponse
    {
        $membership = $this->authorizedMembership($request, CoreSecurityCatalog::VENDOR_UPDATE);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:2048', 'extensions:csv,txt'],
            'legal_entity_id' => ['required', 'string', 'size:26'],
            'apply' => ['sometimes', 'boolean'],
        ]);
        $file = $request->file('file');
        abort_if($file === null || is_array($file), 422);

        return response()->json(['data' => $importer->run($membership, $data['legal_entity_id'], (string) $file->get(), filter_var($data['apply'] ?? false, FILTER_VALIDATE_BOOL))]);
    }

    public function index(Request $request): Response
    {
        $membership = $this->authorizedMembership($request, CoreSecurityCatalog::VENDOR_READ);
        $tenant = $membership->tenant_id;
        $filter = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'legal_entity' => ['nullable', 'string', 'max:26'],
            'status' => ['nullable', Rule::in([Vendor::ACTIVE, Vendor::INACTIVE])],
            'party' => ['nullable', 'ulid'],
        ]);
        $keyword = trim((string) ($filter['q'] ?? ''));
        $pattern = '%'.addcslashes($keyword, '\\%_').'%';

        $vendors = Vendor::query()
            ->with('party:id,name,type')
            ->where('tenant_id', $tenant)
            ->when(($filter['legal_entity'] ?? null) !== null, fn ($query) => $query->where('legal_entity_id', $filter['legal_entity']))
            ->when(($filter['status'] ?? null) !== null, fn ($query) => $query->where('status', $filter['status']))
            ->when($keyword !== '', fn ($query) => $query->where(fn (QueryBuilder $inner) => $inner
                ->where('number', 'ilike', $pattern)
                ->orWhere('tax_number', 'ilike', $pattern)
                ->orWhereHas('party', fn ($party) => $party->where('name', 'ilike', $pattern))))
            ->orderBy('number')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Vendor $vendor): array => $this->present($vendor));

        $initialParty = empty($filter['party']) ? null : Party::query()->where('tenant_id', $tenant)->where('status', 'active')->whereKey($filter['party'])->firstOrFail(['id', 'name', 'type']);

        return Inertia::render('foundation/vendor/vendors', [
            'canManage' => $membership->hasCorePermission(CoreSecurityCatalog::VENDOR_UPDATE),
            'filters' => ['q' => $keyword, 'legal_entity' => $filter['legal_entity'] ?? null, 'status' => $filter['status'] ?? null],
            'legalEntities' => $this->legalEntities($tenant),
            'vendors' => $vendors,
            'manualNumbers' => $this->manualNumberAllowed($tenant),
            'initialParty' => $initialParty === null ? null : ['id' => $initialParty->id, 'name' => $initialParty->name, 'type' => $initialParty->identityType()],
            'canReadAddressBook' => $membership->hasCorePermission(CoreSecurityCatalog::ORGANIZATION_READ),
            'canManageAddressBook' => $membership->hasCorePermission(CoreSecurityCatalog::ORGANIZATION_UPDATE),
        ]);
    }

    /** Pihak di buku alamat tenant untuk dipilih sebagai vendor. */
    public function partyOptions(Request $request): JsonResponse
    {
        $membership = $this->authorizedMembership($request, CoreSecurityCatalog::VENDOR_UPDATE);
        $input = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'cursor' => ['nullable', 'string', 'max:2000']]);
        $keyword = trim((string) ($input['q'] ?? ''));
        $pattern = '%'.addcslashes($keyword, '\\%_').'%';

        $parties = Party::query()
            ->where('tenant_id', $membership->tenant_id)
            ->where('status', 'active')
            ->when($keyword !== '', fn ($query) => $query->where(fn ($names) => $names
                ->where('search_name', 'like', '%'.addcslashes(Party::searchName($keyword), '\\%_').'%')
                ->orWhere('name', 'ilike', $pattern)
                ->orWhereIn('id', Vendor::query()->where('tenant_id', $membership->tenant_id)
                    ->where(fn ($accounts) => $accounts->where('number', 'ilike', $pattern)
                        ->orWhereHas('legalEntity', fn ($company) => $company->where('name', 'ilike', $pattern)))
                    ->select('party_id'))))
            ->orderBy('name')->orderBy('id')
            ->cursorPaginate(40, ['id', 'name', 'type']);
        $partyRows = $parties->getCollection();
        $accounts = Vendor::query()->where('tenant_id', $membership->tenant_id)
            ->whereIn('party_id', $partyRows->pluck('id')->all())->with('legalEntity:id,name')
            ->orderBy('number')->get()->groupBy('party_id');

        return response()->json(['data' => $partyRows->map(static fn (Party $party): array => [
            'id' => $party->id, 'name' => $party->name, 'type' => $party->identityType(),
            'vendor_accounts' => ($accounts->get($party->id) ?? collect())->map(static fn (Vendor $vendor): array => [
                'id' => $vendor->id, 'number' => $vendor->number, 'legal_entity_id' => $vendor->legal_entity_id,
                'company' => $vendor->legalEntity->name, 'status' => $vendor->status,
            ])->values(),
        ])->values(), 'next_cursor' => $parties->nextCursor()?->encode()]);
    }

    public function lookup(Request $request): JsonResponse
    {
        $membership = $this->authorizedMembership($request, CoreSecurityCatalog::VENDOR_READ);
        $data = $request->validate([
            'legal_entity_id' => ['required', 'ulid'], 'q' => ['nullable', 'string', 'max:100'],
            'party_id' => ['nullable', 'ulid'],
            'cursor' => ['nullable', 'string', 'max:2000'],
        ]);
        $keyword = trim($data['q'] ?? '');
        $pattern = '%'.addcslashes($keyword, '\\%_').'%';
        $vendors = Vendor::query()->where('tenant_id', $membership->tenant_id)
            ->where('legal_entity_id', $data['legal_entity_id'])->with('party')
            ->when(! empty($data['party_id']), fn ($query) => $query->where('party_id', $data['party_id']))
            ->when($keyword !== '', fn ($query) => $query->where(fn ($match) => $match->where('number', 'ilike', $pattern)
                ->orWhereHas('party', fn ($party) => $party->where('name', 'ilike', $pattern))))
            ->orderBy('number')->orderBy('id')->cursorPaginate(40);

        return response()->json(['data' => $vendors->getCollection()->map(fn (Vendor $vendor) => $this->present($vendor)),
            'next_cursor' => $vendors->nextCursor()?->encode()]);
    }

    public function companyOptions(Request $request): JsonResponse
    {
        $membership = $this->authorizedMembership($request, CoreSecurityCatalog::VENDOR_UPDATE);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'cursor' => ['nullable', 'string', 'max:2000']]);
        $keyword = trim($data['q'] ?? '');
        $pattern = '%'.addcslashes($keyword, '\\%_').'%';
        $companies = Organization::query()->where('tenant_id', $membership->tenant_id)->where('classification', 'legal_entity')
            ->with('legalEntity:organization_id,company_code')
            ->when($keyword !== '', fn ($query) => $query->where(fn ($match) => $match->where('name', 'ilike', $pattern)
                ->orWhereHas('legalEntity', fn ($entity) => $entity->where('company_code', 'ilike', $pattern))))
            ->orderBy('name')->orderBy('id')->cursorPaginate(40);

        return response()->json(['data' => $companies->getCollection()->map(static fn (Organization $company) => [
            'id' => $company->id, 'name' => $company->name, 'company_code' => $company->legalEntity?->company_code,
        ]), 'next_cursor' => $companies->nextCursor()?->encode()]);
    }

    public function store(Request $request, SaveVendor $saveVendor): JsonResponse
    {
        $membership = $this->authorizedMembership($request, CoreSecurityCatalog::VENDOR_UPDATE);
        $data = $request->validate([
            'legal_entity_id' => ['required', 'string', 'size:26'],
            'party_id' => ['nullable', 'required_without:party_name', 'string', 'size:26'],
            'party_name' => ['nullable', 'required_without:party_id', 'string', 'max:200'],
            'party_type' => ['nullable', Rule::in(Party::TYPES)],
            'number' => ['nullable', 'string', 'max:40'],
            'tax_number' => ['nullable', 'string', 'max:32', 'regex:/^[0-9.\- ]+$/'],
            'payment_priority' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:2147483647'],
            'status' => ['nullable', Rule::in([Vendor::ACTIVE, Vendor::INACTIVE])],
        ], [
            'party_id.required_without' => 'Pilih pihak dari buku alamat atau ketik nama vendor baru.',
            'party_name.required_without' => 'Pilih pihak dari buku alamat atau ketik nama vendor baru.',
            'tax_number.regex' => 'NPWP hanya boleh angka, titik, dan tanda hubung.',
        ]);
        $key = $request->header('Idempotency-Key');
        $key = is_string($key) && preg_match('/^[A-Za-z0-9._:-]{8,120}$/', $key) === 1 ? $key : (string) Str::ulid();

        $vendor = $saveVendor->create($membership, [
            'legal_entity_id' => $data['legal_entity_id'],
            'party_id' => $data['party_id'] ?? null,
            'party_type' => $data['party_type'] ?? null,
            'party_name' => $data['party_name'] ?? null,
            'number' => ($data['number'] ?? null) === null ? null : strtoupper(trim((string) $data['number'])),
            'tax_number' => $this->npwp($data['tax_number'] ?? null),
            'status' => $data['status'] ?? Vendor::ACTIVE,
            'payment_priority' => $data['payment_priority'] ?? null,
        ], $key);

        return response()->json(['data' => $this->present($vendor->refresh()->load('party'))], $vendor->wasRecentlyCreated ? 201 : 200);
    }

    public function update(Request $request, Vendor $vendor, SaveVendor $saveVendor): JsonResponse
    {
        $membership = $this->authorizedMembership($request, CoreSecurityCatalog::VENDOR_UPDATE);
        abort_unless($vendor->tenant_id === $membership->tenant_id, 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'tax_number' => ['nullable', 'string', 'max:32', 'regex:/^[0-9.\- ]+$/'],
            'status' => ['required', Rule::in([Vendor::ACTIVE, Vendor::INACTIVE])],
            'payment_priority' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:2147483647'],
        ], ['tax_number.regex' => 'NPWP hanya boleh angka, titik, dan tanda hubung.']);

        $vendor = $saveVendor->update($membership, $vendor, [
            'name' => trim((string) $data['name']),
            'tax_number' => array_key_exists('tax_number', $data) ? $this->npwp($data['tax_number']) : $vendor->tax_number,
            'status' => (string) $data['status'],
            'payment_priority' => array_key_exists('payment_priority', $data) ? $data['payment_priority'] : $vendor->payment_priority,
        ], RowVersion::expected($request));

        return response()->json(['data' => $this->present($vendor->load('party'))]);
    }

    private function npwp(?string $value): ?string
    {
        $clean = trim((string) $value);

        return $clean === '' ? null : $clean;
    }

    /**
     * Apakah nomor vendor boleh diketik manual. Urutan yang belum lahir memakai bawaan Core
     * (boleh manual); sesudah lahir, setelan tenant di layar Nomor dokumen yang menentukan.
     */
    private function manualNumberAllowed(string $tenantId): bool
    {
        $reference = NumberSequenceReference::query()
            ->where('app_id', CoreNumberSequences::APP_ID)
            ->where('code', Vendor::NUMBER_SEQUENCE)
            ->value('id');
        // Referensi yang belum ada akan dipasang dengan bawaan Core saat vendor pertama disimpan,
        // dan bawaan itu boleh manual.
        if ($reference === null) {
            return true;
        }
        $sequence = TenantNumberSequence::query()->where('tenant_id', $tenantId)->where('reference_id', $reference)->first(['allow_manual']);

        return $sequence === null || $sequence->allow_manual;
    }

    /** Anggota yang sedang bekerja, bila role-nya memegang permission layar Core itu (SEC-22). */
    private function authorizedMembership(Request $request, string $permission): TenantMembership
    {
        $membership = $this->currentMembership($request);
        abort_unless($membership->hasCorePermission($permission), 403);

        return $membership;
    }

    /** @return list<array{id: string, name: string, company_code: ?string}> */
    private function legalEntities(string $tenantId): array
    {
        return array_values(Organization::query()
            ->where('tenant_id', $tenantId)
            ->where('classification', 'legal_entity')
            ->with('legalEntity:organization_id,company_code')
            ->orderBy('name')
            ->get()
            ->map(static fn (Organization $organization): array => [
                'id' => $organization->id,
                'name' => (string) $organization->name,
                'company_code' => $organization->legalEntity?->company_code,
            ])
            ->all());
    }

    /** @return array<string, mixed> */
    private function present(Vendor $vendor): array
    {
        return [
            'id' => $vendor->id,
            'version' => (int) $vendor->version,
            'number' => $vendor->number,
            'name' => (string) $vendor->party->name,
            'party_id' => $vendor->party_id,
            'party_type' => $vendor->party->identityType(),
            'legal_entity_id' => $vendor->legal_entity_id,
            'tax_number' => $vendor->tax_number,
            'payment_priority' => $vendor->payment_priority,
            'status' => $vendor->status,
            'updated_at' => $vendor->updated_at?->toIso8601String(),
        ];
    }
}
