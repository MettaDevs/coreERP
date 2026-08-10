<?php

namespace App\Http\Controllers\transaksi\InventarisasiAset;

use App\Http\Controllers\Controller;
use App\Models\transaksi\InventarisasiAset\Asset;
use App\Models\transaksi\InventarisasiAset\AssetBook;
use App\Services\DepreciationCalculator;
use App\Services\FiscalCalendarClient;
use App\Services\NumberSequenceClient;
use App\Support\AssetAttributeValidator;
use App\Support\OrganizationScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
        $defaults = $this->groupDefaults($tenantId, (string) $data['group_aset_id']);
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
                'group_aset_id' => $data['group_aset_id'],
                'jenis_aset_id' => $data['jenis_aset_id'],
                'kondisi_aset_id' => $data['kondisi_aset_id'] ?? null,
                'pabrikan_aset_id' => $data['pabrikan_aset_id'] ?? null,
                'model_aset_id' => $data['model_aset_id'] ?? null,
                'parent_asset_id' => $data['parent_asset_id'] ?? null,
                'asset_location_id' => $data['asset_location_id'] ?? null,
                'financial_dimension_org_unit_id' => $this->locationDimension($tenantId, $data['asset_location_id'] ?? null)
                    ?? $data['usage_org_unit_id'],
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
                'id' => (string) Str::ulid(),
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
            $this->createBooks($asset, $data, $tenantId, $profileId, $bookCode);
            $this->saveAttributes($asset, $data, $tenantId);

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
                'id' => (string) Str::ulid(), 'tenant_id' => $tenantId, 'asset_id' => $asset->id,
                'usage_org_unit_id' => $data['usage_org_unit_id'],
                'custodian_user_id' => $data['custodian_user_id'] ?? null,
                'asset_location_id' => $data['asset_location_id'] ?? null,
                'effective_on' => $data['effective_on'], 'reason' => $data['reason'], 'created_at' => now(), 'updated_at' => now(),
            ]);
            // Mutasi ikut memperbarui dimensi keuangan: memindahkan aset ke lokasi yang
            // dipetakan ke unit lain berarti pembebanannya juga pindah.
            $locationId = $data['asset_location_id'] ?? $asset->asset_location_id;
            $asset->update([
                'asset_location_id' => $locationId,
                'responsible_org_unit_id' => $data['usage_org_unit_id'],
                'financial_dimension_org_unit_id' => $this->locationDimension($tenantId, $locationId) ?? $data['usage_org_unit_id'],
                'lifecycle_state' => 'in_use',
            ]);
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
            // Dua sumbu wajib dan sejajar: group membawa perlakuan finansial,
            // jenis membawa perlakuan teknis. Tidak ada yang menyaring yang lain.
            'group_aset_id' => ['required', 'ulid', $sameTenant('m_group_aset')],
            'jenis_aset_id' => ['required', 'ulid', $sameTenant('m_jenis_aset')],
            'kondisi_aset_id' => ['nullable', 'ulid', $sameTenant('m_kondisi_aset')],
            'pabrikan_aset_id' => ['nullable', 'ulid', $sameTenant('m_pabrikan_aset')],
            'model_aset_id' => ['nullable', 'ulid', $sameTenant('m_model_aset')],
            'parent_asset_id' => ['nullable', 'ulid', Rule::exists('tr_penerimaan_aset', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'asset_location_id' => ['nullable', 'ulid', $sameTenant('m_lokasi_aset')],
            'serial_number' => ['nullable', 'string', 'max:150'], 'model_number' => ['nullable', 'string', 'max:150'],
            'acquired_on' => ['required', 'date'], 'placed_in_service_on' => ['nullable', 'date'],
            'acquisition_value' => ['required', 'numeric', 'min:0'], 'currency_code' => ['required', 'string', 'size:3'],
            'receiving_org_unit_id' => ['nullable', 'ulid'], 'usage_org_unit_id' => ['required', 'ulid'],
            'received_by_user_id' => ['nullable', 'string', 'max:64'], 'custodian_user_id' => ['nullable', 'string', 'max:64'],
            'keterangan' => ['nullable', 'string', 'max:2000'],
            // Bentuk atribut divalidasi di sini; isinya divalidasi terhadap definisi
            // milik jenis aset, yang hanya diketahui saat berjalan.
            'atribut' => ['sometimes', 'array'],
            'atribut.*.tipe_atribut_id' => ['required', 'ulid'],
            'atribut.*.nilai' => ['present'],
            'depreciation_profile_id' => ['nullable', 'ulid', $sameTenant('m_profil_penyusutan')],
            'book_code' => ['nullable', 'string', 'max:50'],
            'residual_value' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    private function requirePermission(Request $request, string $action): void
    {
        abort_unless(in_array('management-aset.'.self::RESOURCE.'.'.$action, $request->attributes->get('coreerp.permissions', []), true), 403);
    }

    private function tenantId(Request $request): string
    {
        return (string) $request->attributes->get('coreerp.tenant_id');
    }

    /**
     * Menyimpan nilai atribut aset. Atribut diwarisi dari jenis aset, jadi yang
     * diperiksa adalah definisi milik jenis yang dipilih, bukan daftar tetap di kode.
     *
     * @param  array<string, mixed>  $data
     */
    private function saveAttributes(Asset $asset, array $data, string $tenantId): void
    {
        $rows = app(AssetAttributeValidator::class)->rowsFor(
            $tenantId,
            (string) $data['jenis_aset_id'],
            $data['atribut'] ?? [],
        );
        if ($rows === []) {
            return;
        }

        DB::table('tr_aset_atribut')->insert(array_map(
            fn (array $row): array => [...$row, 'asset_id' => $asset->id],
            $rows,
        ));
    }

    /**
     * Buku aset dibentuk dari matriks group x buku: satu baris matriks menghasilkan satu
     * buku, sehingga aset dapat menyusut komersial dan fiskal sekaligus dengan aturannya
     * masing-masing. Aturan matriks disalin ke buku, bukan dirujuk hidup-hidup, supaya
     * perubahan matriks kelak tidak menulis ulang aset yang sudah berjalan.
     *
     * Bila matriks belum diisi, penerimaan aset tetap membentuk satu buku dari default
     * group. Sebelumnya buku bisa gagal terbentuk tanpa jejak apa pun, dan aset lolos
     * tercatat tetapi diam-diam tidak pernah menyusut.
     *
     * @param  array<string, mixed>  $data
     */
    private function createBooks(Asset $asset, array $data, string $tenantId, ?string $profileId, string $bookCode): void
    {
        $threshold = DB::table('m_group_aset')
            ->where(['tenant_id' => $tenantId, 'id' => $data['group_aset_id']])
            ->value('capitalization_threshold');
        // Perolehan di bawah ambang kapitalisasi tetap dicatat sebagai aset, tetapi
        // bukunya tidak menyusut. Ini perilaku yang sama dengan F&O.
        $capitalized = $threshold === null || (float) $data['acquisition_value'] >= (float) $threshold;

        $rows = DB::table('m_group_buku_penyusutan as matrix')
            ->join('m_buku_penyusutan as buku', function ($join): void {
                $join->on('buku.id', '=', 'matrix.buku_id')->on('buku.tenant_id', '=', 'matrix.tenant_id');
            })
            ->where(['matrix.tenant_id' => $tenantId, 'matrix.group_aset_id' => $data['group_aset_id']])
            ->whereNull('matrix.deleted_at')
            ->whereNull('buku.deleted_at')
            ->where('buku.aktif', true)
            ->select(
                'matrix.buku_id',
                'matrix.useful_life_periods',
                'matrix.convention',
                'matrix.depreciate',
                DB::raw('coalesce(matrix.round_off_depreciation, buku.round_off_depreciation) as round_off_depreciation'),
                DB::raw('coalesce(matrix.depreciation_profile_id, buku.depreciation_profile_id) as depreciation_profile_id'),
                DB::raw('coalesce(matrix.alternative_profile_id, buku.alternative_profile_id) as alternative_profile_id'),
                'buku.kode as buku_code',
            )
            ->get();

        $calculator = app(DepreciationCalculator::class);
        // F&O menghitung penyusutan dari tanggal aset mulai digunakan, bukan tanggal
        // perolehan. Bila belum diisi, tanggal perolehan menjadi cadangannya.
        $placedInService = $data['placed_in_service_on'] ?? $data['acquired_on'];

        if ($rows->isEmpty()) {
            if (empty($profileId)) {
                return;
            }
            $rows = collect([(object) [
                'buku_id' => null,
                'useful_life_periods' => null,
                'convention' => null,
                'depreciate' => true,
                'round_off_depreciation' => 0,
                'depreciation_profile_id' => $profileId,
                'alternative_profile_id' => null,
            ]]);
        }

        foreach ($rows as $row) {
            if (empty($row->depreciation_profile_id)) {
                continue;
            }
            AssetBook::query()->create([
                'tenant_id' => $tenantId,
                'asset_id' => $asset->id,
                'buku_id' => $row->buku_id,
                'depreciation_profile_id' => $row->depreciation_profile_id,
                'alternative_profile_id' => $row->alternative_profile_id,
                'book_code' => $row->buku_code ?? $bookCode,
                'useful_life_periods' => $row->useful_life_periods,
                'convention' => $row->convention,
                'depreciation_start_on' => $calculator->startDate(
                    $placedInService,
                    $row->convention,
                    $this->fiscalYear($tenantId, (string) $data['legal_entity_id'], $placedInService, $row->depreciation_profile_id, $row->convention),
                )->toDateString(),
                'depreciate' => $capitalized && (bool) $row->depreciate,
                'round_off_depreciation' => $row->round_off_depreciation ?? 0,
                'acquisition_value' => $data['acquisition_value'],
                'residual_value' => $data['residual_value'] ?? 0,
                'accumulated_depreciation' => 0,
                'net_book_value' => $data['acquisition_value'],
                'status' => 'active',
            ]);
        }
    }

    /**
     * Tahun buku yang memuat tanggal mulai digunakan, dibaca dari Core.
     *
     * Hanya dipanggil bila memang menentukan hasil: profil berdasar tahun fiskal dan
     * konvensinya bergeser mengikuti batas tahun. Untuk kombinasi lain, batas tahun
     * tidak dipakai sama sekali sehingga tidak perlu memanggil Core.
     *
     * Kalender yang belum disiapkan tenant tidak menggagalkan penerimaan aset:
     * perhitungan jatuh ke tahun kalender, sama seperti sebelum kalender diisi.
     *
     * @return array{starts_on: string, ends_on: string}|null
     */
    private function fiscalYear(string $tenantId, string $legalEntityId, string $placedInService, ?string $profileId, ?string $convention): ?array
    {
        $yearBoundConventions = ['half_year', 'half_year_start_of_year', 'half_year_next_year'];
        if (! $profileId || ! in_array($convention, $yearBoundConventions, true)) {
            return null;
        }
        $yearBasis = DB::table('m_profil_penyusutan')
            ->where(['tenant_id' => $tenantId, 'id' => $profileId])
            ->value('year_basis');
        if ($yearBasis !== 'fiscal') {
            return null;
        }

        try {
            $fiscal = app(FiscalCalendarClient::class)->resolve($tenantId, $legalEntityId, $placedInService);
        } catch (RuntimeException) {
            return null;
        }

        return $fiscal['year'] ?? null;
    }

    /**
     * Dimensi keuangan yang diwarisi aset dari lokasi fisiknya; padanan toggle
     * "Update asset dimension" pada Functional location type di F&O. Lokasi yang tidak
     * dipetakan ke unit organisasi mengembalikan null, dan aset tetap memakai unit
     * penggunanya sendiri.
     */
    private function locationDimension(string $tenantId, ?string $locationId): ?string
    {
        if (! $locationId) {
            return null;
        }

        return DB::table('m_lokasi_aset')
            ->where(['tenant_id' => $tenantId, 'id' => $locationId])
            ->value('org_unit_id');
    }

    /**
     * Group ditunjuk langsung oleh aset, jadi default penyusutan dibaca dengan satu
     * lookup. Sebelumnya nilai ini diraih dengan menyusuri jenis -> kategori -> group,
     * yang membuat rantai klasifikasi wajib ada semata-mata sebagai jalur lookup.
     */
    private function groupDefaults(string $tenantId, string $groupAsetId): ?object
    {
        return DB::table('m_group_aset')
            ->where(['tenant_id' => $tenantId, 'id' => $groupAsetId])
            ->select('default_depreciation_profile_id', 'default_book_code')
            ->first();
    }

    /** @return array<string, mixed> */
    private function present(Asset $asset): array
    {
        return $asset->only(['id', 'kode', 'legal_entity_id', 'responsible_org_unit_id', 'group_aset_id', 'jenis_aset_id', 'kondisi_aset_id', 'pabrikan_aset_id', 'model_aset_id', 'parent_asset_id', 'asset_location_id', 'financial_dimension_org_unit_id', 'serial_number', 'model_number', 'acquired_on', 'placed_in_service_on', 'acquisition_value', 'currency_code', 'lifecycle_state', 'keterangan']);
    }
}
