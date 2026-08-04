<?php

namespace App\Http\Controllers\transaksi\InventarisasiAset;

use App\Http\Controllers\Controller;
use App\Models\transaksi\InventarisasiAset\Asset;
use App\Models\transaksi\InventarisasiAset\AssetBook;
use App\Services\NumberSequenceClient;
use App\Support\OrganizationScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use RuntimeException;

class AssetController extends Controller
{
    private const RESOURCE = 'aset';

    public function index(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'read');
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $query = app(OrganizationScope::class)->assetQuery(Asset::query()->where('tenant_id', $this->tenantId($request)), $request);
        if (($q = trim((string) ($data['q'] ?? ''))) !== '') {
            $query->where(fn ($builder) => $builder->whereRaw('LOWER(kode) LIKE ?', ['%'.mb_strtolower($q).'%'])->orWhereRaw('LOWER(serial_number) LIKE ?', ['%'.mb_strtolower($q).'%']));
        }

        return response()->json(['data' => $query->orderByDesc('created_at')->get()->map($this->present(...))->values()]);
    }

    public function store(Request $request, NumberSequenceClient $numbers): JsonResponse
    {
        $this->requirePermission($request, 'create');
        $key = (string) $request->header('Idempotency-Key');
        validator(['key' => $key], ['key' => ['required', 'string', 'max:154', 'regex:/^[A-Za-z0-9._:-]+$/']])->validate();
        $tenantId = $this->tenantId($request);
        $data = $request->validate($this->rules($tenantId));
        app(OrganizationScope::class)->require($request, $data['legal_entity_id'], $data['usage_org_unit_id']);
        $legalEntityId = (string) $data['legal_entity_id'];
        $defaults = $this->groupDefaults($tenantId, (string) $data['jenis_aset_id']);
        $profileId = $data['depreciation_profile_id'] ?? $defaults?->default_depreciation_profile_id;
        $bookCode = $data['book_code'] ?? $defaults?->default_book_code ?? 'PRIMARY';
        if ($existing = Asset::withTrashed()->where(['tenant_id' => $tenantId, 'creation_key' => $key])->first()) {
            return response()->json(['data' => $this->present($existing)], 200, ['Idempotent-Replayed' => 'true']);
        }
        try {
            $kode = $numbers->issue('management-aset.aset', $tenantId, 'aset:'.$key, $legalEntityId);
        } catch (RuntimeException $exception) {
            return response()->json(['error' => ['code' => 'number_sequence_unavailable', 'message' => $exception->getMessage()]], 503);
        }

        $asset = DB::transaction(function () use ($data, $tenantId, $key, $kode, $profileId, $bookCode): Asset {
            $asset = Asset::query()->create([
                'tenant_id' => $tenantId,
                'creation_key' => $key,
                'kode' => $kode,
                'legal_entity_id' => $data['legal_entity_id'],
                'responsible_org_unit_id' => $data['usage_org_unit_id'],
                'jenis_aset_id' => $data['jenis_aset_id'],
                'kondisi_aset_id' => $data['kondisi_aset_id'] ?? null,
                'pabrikan_aset_id' => $data['pabrikan_aset_id'] ?? null,
                'asset_location_id' => $data['asset_location_id'] ?? null,
                'serial_number' => $data['serial_number'] ?? null,
                'model_number' => $data['model_number'] ?? null,
                'acquired_on' => $data['acquired_on'],
                'placed_in_service_on' => $data['placed_in_service_on'] ?? null,
                'acquisition_value' => $data['acquisition_value'],
                'currency_code' => strtoupper($data['currency_code']),
                'lifecycle_state' => 'received',
                'keterangan' => $data['keterangan'] ?? null,
            ]);
            DB::table('tr_penempatan_aset')->insert([
                'id' => (string) \Illuminate\Support\Str::ulid(),
                'tenant_id' => $tenantId,
                'asset_id' => $asset->id,
                'receiving_org_unit_id' => $data['receiving_org_unit_id'] ?? null,
                'usage_org_unit_id' => $data['usage_org_unit_id'] ?? null,
                'received_by_user_id' => $data['received_by_user_id'] ?? null,
                'custodian_user_id' => $data['custodian_user_id'] ?? null,
                'asset_location_id' => $data['asset_location_id'] ?? null,
                'effective_on' => $data['acquired_on'],
                'reason' => 'Penerimaan aset',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if (!empty($profileId)) {
                AssetBook::query()->create([
                    'tenant_id' => $tenantId,
                    'asset_id' => $asset->id,
                    'depreciation_profile_id' => $profileId,
                    'book_code' => $bookCode,
                    'acquisition_value' => $data['acquisition_value'],
                    'residual_value' => $data['residual_value'] ?? 0,
                    'accumulated_depreciation' => 0,
                    'net_book_value' => $data['acquisition_value'],
                    'status' => 'active',
                ]);
            }

            return $asset;
        });

        return response()->json(['data' => $this->present($asset)], 201);
    }

    public function place(Request $request, string $id): JsonResponse
    {
        $this->requirePermission($request, 'mutate');
        $tenantId = $this->tenantId($request);
        $asset = app(OrganizationScope::class)->assetQuery(Asset::query()->where('tenant_id', $tenantId), $request)->findOrFail($id);
        abort_if(in_array($asset->lifecycle_state, ['decommissioned', 'disposed'], true), 409, 'Aset yang tidak aktif atau sudah dilepas tidak dapat dimutasi.');
        $data = $request->validate([
            'effective_on' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:250'],
            'usage_org_unit_id' => ['required', 'ulid'],
            'custodian_user_id' => ['nullable', 'string', 'max:64'],
            'asset_location_id' => ['nullable', 'ulid', Rule::exists('m_lokasi_aset', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
        ]);
        app(OrganizationScope::class)->require($request, $asset->legal_entity_id, $data['usage_org_unit_id']);
        DB::transaction(function () use ($asset, $tenantId, $data): void {
            DB::table('tr_penempatan_aset')->insert([
                'id' => (string) \Illuminate\Support\Str::ulid(), 'tenant_id' => $tenantId, 'asset_id' => $asset->id,
                'usage_org_unit_id' => $data['usage_org_unit_id'],
                'custodian_user_id' => $data['custodian_user_id'] ?? null,
                'asset_location_id' => $data['asset_location_id'] ?? null,
                'effective_on' => $data['effective_on'], 'reason' => $data['reason'], 'created_at' => now(), 'updated_at' => now(),
            ]);
            $asset->update(['asset_location_id' => $data['asset_location_id'] ?? $asset->asset_location_id, 'responsible_org_unit_id' => $data['usage_org_unit_id'], 'lifecycle_state' => 'in_use']);
        });

        return response()->json(['data' => $this->present($asset->fresh())]);
    }

    public function history(Request $request, string $id): JsonResponse
    {
        $this->requirePermission($request, 'read');
        $asset = app(OrganizationScope::class)->assetQuery(Asset::query()->where('tenant_id', $this->tenantId($request)), $request)->findOrFail($id);
        $placements = DB::table('tr_penempatan_aset')
            ->where(['tenant_id' => $this->tenantId($request), 'asset_id' => $asset->id])
            ->orderBy('effective_on')->orderBy('created_at')->get();

        return response()->json(['data' => ['asset' => $this->present($asset), 'placements' => $placements]]);
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(string $tenantId): array
    {
        $sameTenant = fn (string $table) => Rule::exists($table, 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at');
        return [
            'legal_entity_id' => ['required', 'ulid'],
            'jenis_aset_id' => ['required', 'ulid', $sameTenant('m_jenis_aset')],
            'kondisi_aset_id' => ['nullable', 'ulid', $sameTenant('m_kondisi_aset')],
            'pabrikan_aset_id' => ['nullable', 'ulid', $sameTenant('m_pabrikan_aset')],
            'asset_location_id' => ['nullable', 'ulid', $sameTenant('m_lokasi_aset')],
            'serial_number' => ['nullable', 'string', 'max:150'], 'model_number' => ['nullable', 'string', 'max:150'],
            'acquired_on' => ['required', 'date'], 'placed_in_service_on' => ['nullable', 'date'],
            'acquisition_value' => ['required', 'numeric', 'min:0'], 'currency_code' => ['required', 'string', 'size:3'],
            'receiving_org_unit_id' => ['nullable', 'ulid'], 'usage_org_unit_id' => ['required', 'ulid'],
            'received_by_user_id' => ['nullable', 'string', 'max:64'], 'custodian_user_id' => ['nullable', 'string', 'max:64'],
            'keterangan' => ['nullable', 'string', 'max:2000'],
            'depreciation_profile_id' => ['nullable', 'ulid', $sameTenant('m_profil_penyusutan')],
            'book_code' => ['nullable', 'string', 'max:50'],
            'residual_value' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    private function requirePermission(Request $request, string $action): void
    {
        abort_unless(in_array('management-aset.'.self::RESOURCE.'.'.$action, $request->attributes->get('coreerp.permissions', []), true), 403);
    }

    private function tenantId(Request $request): string { return (string) $request->attributes->get('coreerp.tenant_id'); }

    private function groupDefaults(string $tenantId, string $jenisAsetId): ?object
    {
        return DB::table('m_jenis_aset as jenis')
            ->join('m_kategori_aset as kategori', function ($join): void { $join->on('kategori.id', '=', 'jenis.kategori_aset_id')->on('kategori.tenant_id', '=', 'jenis.tenant_id'); })
            ->join('m_group_aset as grup', function ($join): void { $join->on('grup.id', '=', 'kategori.group_aset_id')->on('grup.tenant_id', '=', 'kategori.tenant_id'); })
            ->where(['jenis.tenant_id' => $tenantId, 'jenis.id' => $jenisAsetId])
            ->select('grup.default_depreciation_profile_id', 'grup.default_book_code')->first();
    }

    /** @return array<string, mixed> */
    private function present(Asset $asset): array
    {
        return $asset->only(['id', 'kode', 'legal_entity_id', 'responsible_org_unit_id', 'jenis_aset_id', 'kondisi_aset_id', 'pabrikan_aset_id', 'asset_location_id', 'serial_number', 'model_number', 'acquired_on', 'placed_in_service_on', 'acquisition_value', 'currency_code', 'lifecycle_state', 'keterangan']);
    }
}
