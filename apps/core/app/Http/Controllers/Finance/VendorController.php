<?php

namespace App\Http\Controllers\Finance;

use App\Actions\Finance\SaveVendor;
use App\Http\Controllers\Controller;
use App\Models\NumberSequenceReference;
use App\Models\Organization;
use App\Models\Party;
use App\Models\TenantMembership;
use App\Models\TenantNumberSequence;
use App\Models\Vendor;
use App\Support\Finance\CoreNumberSequences;
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

    public function index(Request $request): Response
    {
        $membership = $this->currentMembership($request);
        $tenant = $membership->tenant_id;
        $filter = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'legal_entity' => ['nullable', 'string', 'max:26'],
            'status' => ['nullable', Rule::in([Vendor::ACTIVE, Vendor::INACTIVE])],
        ]);
        $kata = trim((string) ($filter['q'] ?? ''));
        $pola = '%'.addcslashes($kata, '\\%_').'%';

        $vendors = Vendor::query()
            ->with('party:id,name,type')
            ->where('tenant_id', $tenant)
            ->when(($filter['legal_entity'] ?? null) !== null, fn ($query) => $query->where('legal_entity_id', $filter['legal_entity']))
            ->when(($filter['status'] ?? null) !== null, fn ($query) => $query->where('status', $filter['status']))
            ->when($kata !== '', fn ($query) => $query->where(fn (QueryBuilder $inner) => $inner
                ->where('number', 'ilike', $pola)
                ->orWhere('tax_number', 'ilike', $pola)
                ->orWhereHas('party', fn ($party) => $party->where('name', 'ilike', $pola))))
            ->orderBy('number')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Vendor $vendor): array => $this->present($vendor));

        return Inertia::render('settings/vendors', [
            'canManage' => $membership->canManageAccess(),
            'filters' => ['q' => $kata, 'legal_entity' => $filter['legal_entity'] ?? null, 'status' => $filter['status'] ?? null],
            'legalEntities' => $this->legalEntities($tenant),
            'vendors' => $vendors,
            'manualNumbers' => $this->nomorManualDiizinkan($tenant),
        ]);
    }

    /** Pihak di buku alamat tenant untuk dipilih sebagai vendor. */
    public function partyOptions(Request $request): JsonResponse
    {
        $membership = $this->admin($request);
        $kata = trim((string) ($request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? ''));

        return response()->json(['data' => Party::query()
            ->where('tenant_id', $membership->tenant_id)
            ->where('status', 'active')
            ->when($kata !== '', fn ($query) => $query->where('search_name', 'like', '%'.addcslashes(Party::searchName($kata), '\\%_').'%'))
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'type'])
            ->map(static fn (Party $party): array => ['id' => $party->id, 'name' => $party->name, 'type' => $party->type])
            ->values()]);
    }

    public function store(Request $request, SaveVendor $simpan): JsonResponse
    {
        $membership = $this->admin($request);
        $data = $request->validate([
            'legal_entity_id' => ['required', 'string', 'size:26'],
            'party_id' => ['nullable', 'required_without:party_name', 'string', 'size:26'],
            'party_name' => ['nullable', 'required_without:party_id', 'string', 'max:200'],
            'party_type' => ['nullable', Rule::in(Party::TYPES)],
            'number' => ['nullable', 'string', 'max:40'],
            'tax_number' => ['nullable', 'string', 'max:32', 'regex:/^[0-9.\- ]+$/'],
            'status' => ['nullable', Rule::in([Vendor::ACTIVE, Vendor::INACTIVE])],
        ], [
            'party_id.required_without' => 'Pilih pihak dari buku alamat atau ketik nama vendor baru.',
            'party_name.required_without' => 'Pilih pihak dari buku alamat atau ketik nama vendor baru.',
            'tax_number.regex' => 'NPWP hanya boleh angka, titik, dan tanda hubung.',
        ]);
        $kunci = $request->header('Idempotency-Key');
        $kunci = is_string($kunci) && preg_match('/^[A-Za-z0-9._:-]{8,120}$/', $kunci) === 1 ? $kunci : (string) Str::ulid();

        $vendor = $simpan->create($membership, [
            'legal_entity_id' => $data['legal_entity_id'],
            'party_id' => $data['party_id'] ?? null,
            'party_type' => $data['party_type'] ?? null,
            'party_name' => $data['party_name'] ?? null,
            'number' => ($data['number'] ?? null) === null ? null : strtoupper(trim((string) $data['number'])),
            'tax_number' => $this->npwp($data['tax_number'] ?? null),
            'status' => $data['status'] ?? Vendor::ACTIVE,
        ], $kunci);

        return response()->json(['data' => $this->present($vendor->load('party'))], $vendor->wasRecentlyCreated ? 201 : 200);
    }

    public function update(Request $request, Vendor $vendor, SaveVendor $simpan): JsonResponse
    {
        $membership = $this->admin($request);
        abort_unless($vendor->tenant_id === $membership->tenant_id, 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'tax_number' => ['nullable', 'string', 'max:32', 'regex:/^[0-9.\- ]+$/'],
            'status' => ['required', Rule::in([Vendor::ACTIVE, Vendor::INACTIVE])],
        ], ['tax_number.regex' => 'NPWP hanya boleh angka, titik, dan tanda hubung.']);

        $vendor = $simpan->update($membership, $vendor, [
            'name' => trim((string) $data['name']),
            'tax_number' => $this->npwp($data['tax_number'] ?? null),
            'status' => (string) $data['status'],
        ]);

        return response()->json(['data' => $this->present($vendor->load('party'))]);
    }

    private function npwp(?string $nilai): ?string
    {
        $bersih = trim((string) $nilai);

        return $bersih === '' ? null : $bersih;
    }

    /**
     * Apakah nomor vendor boleh diketik manual. Urutan yang belum lahir memakai bawaan Core
     * (boleh manual); sesudah lahir, setelan tenant di layar Nomor dokumen yang menentukan.
     */
    private function nomorManualDiizinkan(string $tenantId): bool
    {
        $referensi = NumberSequenceReference::query()
            ->where('app_id', CoreNumberSequences::APP_ID)
            ->where('code', Vendor::NUMBER_SEQUENCE)
            ->value('id');
        // Referensi yang belum ada akan dipasang dengan bawaan Core saat vendor pertama disimpan,
        // dan bawaan itu boleh manual.
        if ($referensi === null) {
            return true;
        }
        $urutan = TenantNumberSequence::query()->where('tenant_id', $tenantId)->where('reference_id', $referensi)->first(['allow_manual']);

        return $urutan === null || $urutan->allow_manual;
    }

    private function admin(Request $request): TenantMembership
    {
        $membership = $this->currentMembership($request);
        abort_unless($membership->canManageAccess(), 403);

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
            ->map(static fn (Organization $organisasi): array => [
                'id' => $organisasi->id,
                'name' => (string) $organisasi->name,
                'company_code' => $organisasi->legalEntity?->company_code,
            ])
            ->all());
    }

    /** @return array<string, mixed> */
    private function present(Vendor $vendor): array
    {
        return [
            'id' => $vendor->id,
            'number' => $vendor->number,
            'name' => (string) $vendor->party->name,
            'party_id' => $vendor->party_id,
            'party_type' => $vendor->party->type,
            'legal_entity_id' => $vendor->legal_entity_id,
            'tax_number' => $vendor->tax_number,
            'status' => $vendor->status,
            'updated_at' => $vendor->updated_at?->toIso8601String(),
        ];
    }
}
