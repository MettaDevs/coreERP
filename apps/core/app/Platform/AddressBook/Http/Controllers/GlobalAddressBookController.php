<?php

namespace App\Platform\AddressBook\Http\Controllers;

use App\Foundation\NumberSequence\Support\CoreNumberSequences;
use App\Foundation\Vendor\Models\Vendor;
use App\Http\Controllers\Controller;
use App\Platform\Access\Support\CoreSecurityCatalog;
use App\Platform\AddressBook\Actions\SaveParty;
use App\Platform\AddressBook\Models\AddressBook;
use App\Platform\AddressBook\Models\ElectronicAddress;
use App\Platform\AddressBook\Models\LocationPurpose;
use App\Platform\AddressBook\Models\Party;
use App\Platform\AddressBook\Models\PartyAddressBook;
use App\Platform\AddressBook\Models\PartyRelationship;
use App\Platform\AddressBook\Models\PartyType;
use App\Platform\AddressBook\Support\OrganizationAddressBook;
use App\Platform\Geography\Models\CountryRegion;
use App\Platform\Modules\Contracts\RowVersion;
use App\Platform\Organization\Models\OrganizationParty;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

final class GlobalAddressBookController extends Controller
{
    public function __construct(private readonly OrganizationAddressBook $addresses, private readonly SaveParty $saveParty) {}

    public function index(Request $request): InertiaResponse
    {
        $membership = $this->currentMembership($request);
        $tenant = $membership->tenant_id;
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'type' => ['nullable', 'string', Rule::exists('party_types', 'code')],
            'status' => ['nullable', Rule::in(['active', 'inactive'])], 'party' => ['nullable', 'ulid'],
            'address_book' => ['nullable', 'ulid'], 'role' => ['nullable', 'string', 'max:40'],
            'archived' => ['nullable', Rule::in(['only'])],
        ]);
        $query = Party::query()->where('tenant_id', $tenant)->with(['locations.location.postalAddress', 'roleRegistrations.legalEntity']);
        if (($filters['archived'] ?? null) === 'only') {
            $query->onlyTrashed();
        }
        if (! empty($filters['q'])) {
            $pattern = '%'.addcslashes(trim($filters['q']), '\\%_').'%';
            $query->where(fn ($inner) => $inner->where('name', 'ilike', $pattern)->orWhere('number', 'ilike', $pattern)->orWhere('search_name', 'ilike', $pattern));
        }
        foreach (['type', 'status'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (! empty($filters['role'])) {
            $query->whereHas('roleRegistrations', fn ($roles) => $roles->where('role_code', $filters['role']));
        }
        if (! empty($filters['address_book'])) {
            $query->whereIn('id', PartyAddressBook::query()->where('tenant_id', $tenant)->where('address_book_id', $filters['address_book'])->select('party_id'));
        }

        return Inertia::render('platform/address-book/index', [
            'parties' => $query->orderBy('name')->paginate(50)->withQueryString()->through(fn (Party $party): array => $this->present($party)),
            'filters' => $filters, 'canManage' => $membership->hasCorePermission(CoreSecurityCatalog::ORGANIZATION_UPDATE),
            'canManageVendors' => $membership->hasCorePermission(CoreSecurityCatalog::VENDOR_UPDATE),
            'canReadVendors' => $membership->hasCorePermission(CoreSecurityCatalog::VENDOR_READ),
            'partyTypes' => PartyType::query()->get(['code', 'name']),
            'referenceValues' => DB::table('party_reference_values')->orderBy('name')->get()->groupBy('category'),
            'relationshipTypes' => DB::table('party_relationship_types')->get(),
            'addressBooks' => AddressBook::query()->where('tenant_id', $tenant)->orderBy('name')->get(['id', 'name', 'version']),
        ]);
    }

    public function show(Request $request, Party $party): JsonResponse
    {
        $this->guardParty($request, $party);
        $tenant = $party->tenant_id;
        $canReadVendors = $this->currentMembership($request)->hasCorePermission(CoreSecurityCatalog::VENDOR_READ);
        $relationships = PartyRelationship::query()->where('tenant_id', $tenant)
            ->where(fn ($query) => $query->where('from_party_id', $party->id)->orWhere('to_party_id', $party->id))->get();
        $relatedIds = $relationships->flatMap(fn ($row) => [$row->from_party_id, $row->to_party_id])->unique();
        $names = Party::query()->where('tenant_id', $tenant)->whereIn('id', $relatedIds)->pluck('name', 'id');

        return response()->json(['data' => [
            ...$this->present($party),
            'address_book_ids' => PartyAddressBook::query()->where('tenant_id', $tenant)->where('party_id', $party->id)->pluck('address_book_id'),
            'organization_id' => OrganizationParty::query()->where('tenant_id', $tenant)->where('party_id', $party->id)->value('organization_id'),
            'relationships' => $relationships->map(fn ($row) => [...$row->toArray(), 'from_name' => $names[$row->from_party_id] ?? 'Pihak terarsip', 'to_name' => $names[$row->to_party_id] ?? 'Pihak terarsip']),
            'vendors' => $canReadVendors ? Vendor::query()->where('tenant_id', $tenant)->where('party_id', $party->id)->with('legalEntity')->get()
                ->map(fn ($vendor) => ['id' => $vendor->id, 'number' => $vendor->number, 'status' => $vendor->status, 'legal_entity_id' => $vendor->legal_entity_id, 'company' => $vendor->legalEntity?->name]) : [],
        ]]);
    }

    public function options(Request $request): JsonResponse
    {
        $membership = $this->currentMembership($request);
        abort_unless($membership->hasCorePermission(CoreSecurityCatalog::ORGANIZATION_READ), 403);
        $filter = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $pattern = '%'.addcslashes(trim($filter['q'] ?? ''), '\\%_').'%';

        return response()->json(['data' => Party::query()->where('tenant_id', $membership->tenant_id)->where('status', 'active')
            ->where(fn ($query) => $query->where('name', 'ilike', $pattern)->orWhere('number', 'ilike', $pattern))
            ->orderBy('name')->limit(30)->get(['id', 'number', 'name', 'type'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $membership = $this->currentMembership($request);
        abort_unless($membership->hasCorePermission(CoreSecurityCatalog::ORGANIZATION_UPDATE), 403);
        $data = $this->validated($request, $membership->tenant_id);
        $key = $request->header('Idempotency-Key');
        $key = is_string($key) && preg_match('/^[A-Za-z0-9._:-]{8,120}$/D', $key) ? $key : (string) Str::ulid();
        $key = 'directory:'.$key;
        app(CoreNumberSequences::class)->ensure($membership->tenant_id, 'core.party');
        $party = DB::transaction(function () use ($membership, $data, $key): Party {
            $party = $this->saveParty->create($membership->tenant_id, array_diff_key($data, ['address_book_ids' => true]), $key);
            if ($party->wasRecentlyCreated) {
                $this->syncBooks($party, $data['address_book_ids'] ?? []);
            }

            return $party;
        });

        $created = $party->wasRecentlyCreated;

        return response()->json(['data' => $this->present($party->refresh())], $created ? 201 : 200);
    }

    public function update(Request $request, Party $party): JsonResponse
    {
        $this->guardParty($request, $party, true);
        $data = $this->validated($request, $party->tenant_id, $party);
        $party = DB::transaction(function () use ($request, $party, $data): Party {
            $party = Party::query()->where('tenant_id', $party->tenant_id)->lockForUpdate()->findOrFail($party->id);
            RowVersion::claim($party, RowVersion::expected($request));
            $organization = OrganizationParty::query()->where('tenant_id', $party->tenant_id)->where('party_id', $party->id)->exists();
            if ($organization && ($data['name'] !== $party->name || $data['type'] !== $party->type)) {
                throw ValidationException::withMessages(['name' => 'Ubah nama organisasi melalui halaman Organisasi agar identitasnya tetap sama.']);
            }
            if ($data['type'] !== $party->type) {
                throw ValidationException::withMessages(['type' => 'Jenis pihak yang sudah tersimpan tidak dapat diganti. Buat pihak yang sesuai dan tautkan akun bisnisnya.']);
            }
            $party->forceFill(array_diff_key($data, ['address_book_ids' => true]))->save();
            if (array_key_exists('address_book_ids', $data)) {
                $this->syncBooks($party, $data['address_book_ids']);
            }
            // Pembaca vendor yang lama juga menyaring lewat waktu perubahan akun vendor.
            Vendor::query()->where('tenant_id', $party->tenant_id)->where('party_id', $party->id)->update(['updated_at' => now()]);

            return $party->refresh();
        });

        return response()->json(['data' => $this->present($party)]);
    }

    public function destroy(Request $request, Party $party): Response
    {
        $this->guardParty($request, $party, true);
        DB::transaction(function () use ($request, $party): void {
            $party = Party::query()->where('tenant_id', $party->tenant_id)->lockForUpdate()->findOrFail($party->id);
            RowVersion::claim($party, RowVersion::expected($request));
            if ($party->roleRegistrations()->exists() || OrganizationParty::query()->where('tenant_id', $party->tenant_id)->where('party_id', $party->id)->exists()) {
                throw ValidationException::withMessages(['party' => 'Pihak ini masih dipakai akun bisnis atau organisasi. Nonaktifkan untuk menghentikan pemakaian baru.']);
            }
            $party->delete();
        });

        return response()->noContent();
    }

    public function assignNumbers(Request $request): JsonResponse
    {
        $membership = $this->currentMembership($request);
        abort_unless($membership->hasCorePermission(CoreSecurityCatalog::ORGANIZATION_UPDATE), 403);
        $count = 0;
        foreach (Party::query()->where('tenant_id', $membership->tenant_id)->whereNull('number')->lazyById(100) as $party) {
            $this->saveParty->ensureNumber($party);
            $count++;
        }

        return response()->json(['data' => ['numbered_count' => $count]]);
    }

    public function restore(Request $request, Party $party): JsonResponse
    {
        $this->guardParty($request, $party, true);
        try {
            DB::transaction(function () use ($request, $party): void {
                $query = Party::withTrashed()->where('tenant_id', $party->tenant_id)->whereKey($party->id);
                $query->lockForUpdate()->firstOrFail();
                RowVersion::claim($query, RowVersion::expected($request));
                $party->restore();
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['party' => 'Nomor pihak sudah dipakai record aktif lain. Periksa sebelum memulihkan.']);
        }

        return response()->json(['data' => $this->present($party->refresh())]);
    }

    public function storeBook(Request $request): JsonResponse
    {
        $membership = $this->currentMembership($request);
        abort_unless($membership->hasCorePermission(CoreSecurityCatalog::ORGANIZATION_UPDATE), 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:120', 'regex:/\S/u']]);
        try {
            $book = AddressBook::query()->create(['tenant_id' => $membership->tenant_id, 'name' => trim($data['name'])]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'Buku alamat dengan nama ini sudah ada.']);
        }

        return response()->json(['data' => $book->refresh()], 201);
    }

    public function updateBook(Request $request, AddressBook $addressBook): JsonResponse
    {
        $membership = $this->currentMembership($request);
        abort_unless($addressBook->tenant_id === $membership->tenant_id, 404);
        abort_unless($membership->hasCorePermission(CoreSecurityCatalog::ORGANIZATION_UPDATE), 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:120', 'regex:/\S/u']]);
        try {
            DB::transaction(function () use ($request, $addressBook, $data): void {
                RowVersion::claim($addressBook, RowVersion::expected($request));
                $addressBook->fill(['name' => trim($data['name'])])->save();
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'Buku alamat dengan nama ini sudah ada.']);
        }

        return response()->json(['data' => $addressBook->refresh()]);
    }

    public function destroyBook(Request $request, AddressBook $addressBook): Response
    {
        $membership = $this->currentMembership($request);
        abort_unless($addressBook->tenant_id === $membership->tenant_id, 404);
        abort_unless($membership->hasCorePermission(CoreSecurityCatalog::ORGANIZATION_UPDATE), 403);
        DB::transaction(function () use ($request, $addressBook): void {
            RowVersion::claim($addressBook, RowVersion::expected($request));
            PartyAddressBook::query()->where('tenant_id', $addressBook->tenant_id)->where('address_book_id', $addressBook->id)->delete();
            $addressBook->delete();
        });

        return response()->noContent();
    }

    public function storeRelationship(Request $request, Party $party, ?string $relationship = null): JsonResponse
    {
        $this->guardParty($request, $party, true);
        $data = $request->validate([
            'from_party_id' => ['required', 'ulid'], 'to_party_id' => ['required', 'ulid', 'different:from_party_id'],
            'relationship_type_code' => ['required', Rule::exists('party_relationship_types', 'code')],
            'valid_from' => ['required', 'date_format:Y-m-d'], 'valid_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
        ]);
        abort_unless(in_array($party->id, [$data['from_party_id'], $data['to_party_id']], true), 422);
        $participants = Party::query()->where('tenant_id', $party->tenant_id)->whereIn('id', [$data['from_party_id'], $data['to_party_id']])->get()->keyBy('id');
        abort_unless($participants->count() === 2, 404);
        $type = DB::table('party_relationship_types')->where('code', $data['relationship_type_code'])->first();
        if ($participants[$data['from_party_id']]->identityType() !== $type->from_type || $participants[$data['to_party_id']]->identityType() !== $type->to_type) {
            throw ValidationException::withMessages(['relationship_type_code' => 'Jenis kedua pihak tidak sesuai dengan hubungan yang dipilih.']);
        }
        try {
            $row = DB::transaction(function () use ($request, $party, $data, $relationship) {
                DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['party-relationship|'.$party->tenant_id.'|'.$data['from_party_id'].'|'.$data['to_party_id'].'|'.$data['relationship_type_code']]);
                $row = $relationship === null ? new PartyRelationship(['tenant_id' => $party->tenant_id]) : PartyRelationship::query()->where('tenant_id', $party->tenant_id)->where(fn ($query) => $query->where('from_party_id', $party->id)->orWhere('to_party_id', $party->id))->findOrFail($relationship);
                if ($row->exists) {
                    RowVersion::claim($row, RowVersion::expected($request));
                }
                $overlap = PartyRelationship::query()->where('tenant_id', $party->tenant_id)->where('from_party_id', $data['from_party_id'])->where('to_party_id', $data['to_party_id'])->where('relationship_type_code', $data['relationship_type_code'])
                    ->when($row->exists, fn ($query) => $query->whereKeyNot($row->id))
                    ->where('valid_from', '<=', $data['valid_to'] ?? '9999-12-31')
                    ->where(fn ($query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', $data['valid_from']))->exists();
                if ($overlap) {
                    throw ValidationException::withMessages(['valid_from' => 'Hubungan yang sama sudah berlaku pada rentang tanggal ini.']);
                }
                $row->fill($data)->save();

                return $row->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['relationship_type_code' => 'Hubungan ini sudah tercatat pada tanggal yang sama.']);
        }

        return response()->json(['data' => $row], $relationship === null ? 201 : 200);
    }

    public function destroyRelationship(Request $request, Party $party, string $relationship): Response
    {
        $this->guardParty($request, $party, true);
        DB::transaction(function () use ($request, $party, $relationship): void {
            $row = PartyRelationship::query()->where('tenant_id', $party->tenant_id)->where(fn ($query) => $query->where('from_party_id', $party->id)->orWhere('to_party_id', $party->id))->findOrFail($relationship);
            RowVersion::claim($row, RowVersion::expected($request));
            $row->delete();
        });

        return response()->noContent();
    }

    public function locations(Request $request, Party $party): JsonResponse
    {
        $this->guardParty($request, $party);

        return response()->json(['data' => $this->addresses->locations($party), 'meta' => [
            'purposes' => LocationPurpose::query()->orderBy('sort_order')->get(['code', 'name']),
            'countries' => CountryRegion::query()->orderBy('name')->get(['code', 'name']),
        ]]);
    }

    public function sharableLocations(Request $request, Party $party): JsonResponse
    {
        $this->guardParty($request, $party, true);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        return response()->json(['data' => $this->addresses->sharableLocations($party->tenant_id, trim($data['q'] ?? ''))]);
    }

    public function saveLocation(Request $request, Party $party, ?string $location = null): JsonResponse
    {
        $this->guardParty($request, $party, true);
        $request->merge(['country_region_code' => strtoupper((string) $request->input('country_region_code'))]);
        $linking = $request->filled('location_id');
        $data = $request->validate([
            'location_id' => ['nullable', 'ulid'], 'name' => [$linking ? 'nullable' : 'required', 'string', 'max:120'],
            'purposes' => ['required', 'array', 'min:1'], 'purposes.*' => ['string', 'distinct', Rule::exists('location_purposes', 'code')],
            'is_primary' => ['sometimes', 'boolean'],
            'country_region_code' => [$linking ? 'nullable' : 'required', 'string', 'size:2', Rule::exists('country_regions', 'code')],
            'province' => ['nullable', 'string', 'max:100'], 'city' => ['nullable', 'string', 'max:100'], 'district' => ['nullable', 'string', 'max:100'],
            'village' => ['nullable', 'string', 'max:100'], 'rt' => ['nullable', 'string', 'regex:/^[0-9]{1,3}$/D'], 'rw' => ['nullable', 'string', 'regex:/^[0-9]{1,3}$/D'],
            'street' => ['nullable', 'string', 'max:250'], 'building' => ['nullable', 'string', 'max:120'], 'postbox' => ['nullable', 'string', 'max:60'], 'postal_code' => ['nullable', 'string', 'max:20'],
        ]);

        return response()->json(['data' => $this->addresses->saveLocation($party, $data, $location, $location === null ? 0 : RowVersion::expected($request))], $location === null ? 201 : 200);
    }

    public function destroyLocation(Request $request, Party $party, string $location): Response
    {
        $this->guardParty($request, $party, true);
        $this->addresses->deleteLocation($party, $location, RowVersion::expected($request));

        return response()->noContent();
    }

    public function contacts(Request $request, Party $party): JsonResponse
    {
        $this->guardParty($request, $party);

        return response()->json(['data' => $this->addresses->contacts($party), 'meta' => ['types' => ElectronicAddress::TYPES]]);
    }

    public function saveContact(Request $request, Party $party, ?string $contact = null): JsonResponse
    {
        $this->guardParty($request, $party, true);
        $data = $request->validate([
            'type' => ['required', Rule::in(ElectronicAddress::TYPES)],
            'value' => ['required', 'string', 'max:250', ...($request->input('type') === 'email' ? ['email'] : [])],
            'purpose' => ['nullable', 'string', 'max:30'], 'is_primary' => ['sometimes', 'boolean'], 'address_id' => ['sometimes', 'nullable', 'ulid'],
        ]);

        return response()->json(['data' => $this->addresses->saveContact($party, $data, $contact, $contact === null ? 0 : RowVersion::expected($request))], $contact === null ? 201 : 200);
    }

    public function destroyContact(Request $request, Party $party, string $contact): Response
    {
        $this->guardParty($request, $party, true);
        $this->addresses->deleteContact($party, $contact, RowVersion::expected($request));

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, string $tenant, ?Party $party = null): array
    {
        $rules = ['name' => ['required', 'string', 'max:200', 'regex:/\S/u'],
            'type' => ['required', Rule::in($party === null ? ['person', 'organization'] : [$party->type])],
            'search_name' => ['nullable', 'string', 'max:200'], 'status' => ['required', Rule::in(['active', 'inactive'])],
            'address_book_ids' => ['sometimes', 'array'], 'address_book_ids.*' => ['ulid', 'distinct', Rule::exists('address_books', 'id')->where('tenant_id', $tenant)->whereNull('deleted_at')]];
        foreach (Party::PROFILE_FIELDS as $field) {
            $rules[$field] = ['sometimes', 'nullable', 'string', 'max:200'];
        }
        foreach (['gender', 'marital_status', 'abc_code', 'language'] as $field) {
            $rules[$field] = ['sometimes', 'nullable', 'string', Rule::exists('party_reference_values', 'code')->where('category', $field)];
        }
        foreach (['birth_date', 'anniversary_date'] as $field) {
            $rules[$field] = ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'];
        }
        foreach (['number_of_children', 'number_of_employees'] as $field) {
            $rules[$field] = ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000'];
        }
        $rules['memo'] = ['sometimes', 'nullable', 'string', 'max:10000'];
        $rules['interests'] = ['sometimes', 'nullable', 'string', 'max:2000'];
        $data = $request->validate($rules, [], [
            'name' => 'nama lengkap', 'type' => 'jenis pihak', 'status' => 'status', 'search_name' => 'nama pencarian',
            'birth_date' => 'tanggal lahir', 'anniversary_date' => 'tanggal pernikahan', 'number_of_children' => 'jumlah anak',
            'number_of_employees' => 'jumlah karyawan', 'organization_number' => 'nomor registrasi organisasi',
            'duns_number' => 'nomor DUNS', 'gender' => 'jenis kelamin', 'marital_status' => 'status pernikahan',
            'abc_code' => 'klasifikasi ABC', 'language' => 'bahasa', 'address_book_ids' => 'buku alamat', 'address_book_ids.*' => 'buku alamat',
        ]);
        $data['name'] = trim($data['name']);
        $data['search_name'] = Party::searchName(trim((string) ($data['search_name'] ?? '')) ?: $data['name']);

        return $data;
    }

    /** @param list<string> $ids */
    private function syncBooks(Party $party, array $ids): void
    {
        $available = AddressBook::query()->where('tenant_id', $party->tenant_id)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->pluck('id')->all();
        if (count($available) !== count($ids)) {
            throw ValidationException::withMessages(['address_book_ids' => 'Salah satu buku alamat sudah diarsipkan. Muat ulang pilihannya.']);
        }
        $links = PartyAddressBook::query()->where('tenant_id', $party->tenant_id)->where('party_id', $party->id)->get();
        foreach ($links as $link) {
            if (! in_array($link->address_book_id, $ids, true)) {
                $link->delete();
            }
        }
        foreach (array_diff($ids, $links->pluck('address_book_id')->all()) as $id) {
            PartyAddressBook::query()->create(['tenant_id' => $party->tenant_id, 'party_id' => $party->id, 'address_book_id' => $id]);
        }
    }

    /** @return array<string, mixed> */
    private function present(Party $party): array
    {
        $party->loadMissing(['locations.location.postalAddress', 'roleRegistrations.legalEntity']);
        $address = $party->locations->where('is_primary', true)->first()?->location?->postalAddress;

        return [...Arr::only($party->attributesToArray(), ['id', 'number', 'type', 'name', 'search_name', 'status', 'version', 'deleted_at', ...Party::PROFILE_FIELDS]),
            'city' => $address?->city, 'address' => $address?->formatted,
            'roles' => $party->roleRegistrations->map(fn ($role) => ['code' => $role->role_code, 'company' => $role->legalEntity?->name, 'legal_entity_id' => $role->legal_entity_id]),
        ];
    }

    private function guardParty(Request $request, Party $party, bool $manage = false): void
    {
        $membership = $this->currentMembership($request);
        abort_unless($party->tenant_id === $membership->tenant_id, 404);
        abort_unless($membership->hasCorePermission($manage ? CoreSecurityCatalog::ORGANIZATION_UPDATE : CoreSecurityCatalog::ORGANIZATION_READ), 403);
    }
}
