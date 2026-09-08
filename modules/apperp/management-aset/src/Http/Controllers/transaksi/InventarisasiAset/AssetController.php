<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\transaksi\InventarisasiAset;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Apperp\ManagementAset\Http\Controllers\Controller;
use Modules\Apperp\ManagementAset\Models\master\BukuPenyusutan;
use Modules\Apperp\ManagementAset\Models\master\ProfilPenyusutan;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Asset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\AssetBook;
use Modules\Apperp\ManagementAset\Services\DepreciationCalculator;
use Modules\Apperp\ManagementAset\Services\KalenderFiskalAset;
use Modules\Apperp\ManagementAset\Services\NumberSequenceException;
use Modules\Apperp\ManagementAset\Services\PenerbitNomorAset;
use Modules\Apperp\ManagementAset\Support\AssetAttributeValidator;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;
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
            $query->where(fn ($builder) => $builder
                ->whereRaw('LOWER(kode) LIKE ?', ['%'.mb_strtolower($q).'%'])
                ->orWhereRaw('LOWER(nama) LIKE ?', ['%'.mb_strtolower($q).'%'])
                ->orWhereRaw('LOWER(serial_number) LIKE ?', ['%'.mb_strtolower($q).'%']));
        }

        return response()->json(['data' => $query->orderByDesc('created_at')->get()->map($this->present(...))->values()]);
    }

    public function store(Request $request, PenerbitNomorAset $numbers): JsonResponse
    {
        $this->requirePermission($request, 'create');
        $key = (string) $request->header('Idempotency-Key');
        validator(['key' => $key], ['key' => ['required', 'string', 'max:154', 'regex:/^[A-Za-z0-9._:-]+$/']])->validate();
        $tenantId = $this->tenantId($request);
        $data = $request->validate($this->rules($tenantId));
        app(OrganizationScope::class)->require($request, $data['legal_entity_id'], $data['usage_org_unit_id']);
        $this->assertModelCombination(
            $tenantId,
            $data['jenis_aset_id'],
            $data['pabrikan_aset_id'] ?? null,
            $data['model_aset_id'] ?? null,
        );
        $legalEntityId = (string) $data['legal_entity_id'];
        $defaults = $this->groupDefaults($tenantId, (string) $data['group_aset_id']);
        if ($existing = Asset::withTrashed()->where(['tenant_id' => $tenantId, 'creation_key' => $key])->first()) {
            return response()->json(['data' => $this->present($existing)], 200, ['Idempotent-Replayed' => 'true']);
        }
        try {
            $kode = $numbers->issue('management-aset.aset', $tenantId, 'aset:'.$key, $legalEntityId);
        } catch (NumberSequenceException $exception) {
            return response()->json(['error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()]], $exception->status);
        }

        // Lokasi bawaan group hanya mengisi kekosongan. Begitu aset terbentuk, lokasinya
        // adalah miliknya sendiri: mengubah bawaan group kelak tidak memindahkan aset
        // mana pun, sama seperti perlakuan referensi fiskal di atas.
        $locationId = $data['asset_location_id'] ?? $defaults?->asset_location_id;

        $asset = DB::transaction(function () use ($data, $tenantId, $key, $kode, $defaults, $locationId): Asset {
            $asset = Asset::query()->create([
                'tenant_id' => $tenantId,
                'creation_key' => $key,
                'kode' => $kode,
                'nama' => $data['nama'],
                'legal_entity_id' => $data['legal_entity_id'],
                'responsible_org_unit_id' => $data['usage_org_unit_id'],
                'group_aset_id' => $data['group_aset_id'],
                // Disalin saat penerimaan supaya perubahan pilihan fiskal pada group
                // tidak mengubah jejak aturan aset yang sudah aktif.
                'kelompok_harta_fiskal_id' => $defaults?->kelompok_harta_fiskal_id,
                'jenis_aset_id' => $data['jenis_aset_id'],
                'kondisi_aset_id' => $data['kondisi_aset_id'] ?? null,
                'pabrikan_aset_id' => $data['pabrikan_aset_id'] ?? null,
                'model_aset_id' => $data['model_aset_id'] ?? null,
                'parent_asset_id' => $data['parent_asset_id'] ?? null,
                'asset_location_id' => $locationId,
                'financial_dimension_org_unit_id' => $this->locationDimension($tenantId, $locationId)
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
            DB::table('aset_tr_penempatan_aset')->insert([
                'id' => (string) Str::ulid(),
                'tenant_id' => $tenantId,
                'asset_id' => $asset->id,
                'receiving_org_unit_id' => $data['receiving_org_unit_id'] ?? null,
                'usage_org_unit_id' => $data['usage_org_unit_id'] ?? null,
                'received_by_user_id' => $data['received_by_user_id'] ?? null,
                'custodian_user_id' => $data['custodian_user_id'] ?? null,
                'asset_location_id' => $locationId,
                'effective_on' => $data['acquired_on'],
                'reason' => 'Penerimaan aset',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->createBooks($asset, $data, $tenantId);
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
            'asset_location_id' => ['nullable', 'ulid', Rule::exists('aset_m_lokasi_aset', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
        ]);
        app(OrganizationScope::class)->require($request, $asset->legal_entity_id, $data['usage_org_unit_id']);
        $this->assertDepreciationReady($asset, $tenantId, (string) $data['effective_on']);
        DB::transaction(function () use ($asset, $tenantId, $data): void {
            DB::table('aset_tr_penempatan_aset')->insert([
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

    public function show(Request $request, string $id): JsonResponse
    {
        $this->requirePermission($request, 'read');
        $tenantId = $this->tenantId($request);
        $asset = app(OrganizationScope::class)->assetQuery(Asset::query()->where('tenant_id', $tenantId), $request)->findOrFail($id);

        return response()->json(['data' => [
            ...$this->present($asset),
            'atribut' => $this->attributesOf($tenantId, $asset->id),
        ]]);
    }

    /**
     * Koreksi data aset yang sudah diterima.
     *
     * Register aset sebelumnya hanya dapat ditulis satu kali, sehingga satu salah pilih
     * jenis atau tanggal mulai digunakan hanya bisa diperbaiki dengan membuat aset baru.
     * Yang boleh berubah dibatasi oleh apa yang sudah terlanjur diturunkan darinya:
     * group tidak dapat diganti karena buku aset sudah dibentuk dari matriksnya, dan
     * nilai perolehan tidak dapat diganti setelah ada periode penyusutan yang berjalan.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $this->requirePermission($request, 'update');
        $tenantId = $this->tenantId($request);
        $asset = app(OrganizationScope::class)->assetQuery(Asset::query()->where('tenant_id', $tenantId), $request)->findOrFail($id);
        abort_if(
            in_array($asset->lifecycle_state, ['disposed'], true),
            409,
            'Aset yang sudah dilepas tidak dapat diubah.'
        );

        $rules = $this->rules($tenantId);
        $editable = [
            'nama', 'jenis_aset_id', 'kondisi_aset_id', 'pabrikan_aset_id', 'model_aset_id', 'parent_asset_id',
            'serial_number', 'model_number', 'placed_in_service_on', 'acquisition_value', 'residual_value',
            'keterangan', 'atribut', 'atribut.*.tipe_atribut_id', 'atribut.*.nilai',
        ];
        $data = $request->validate([
            ...array_intersect_key($rules, array_flip($editable)),
            // Seluruh field bersifat opsional pada koreksi; yang tidak dikirim tidak berubah.
            'nama' => ['sometimes', ...$rules['nama']],
            'jenis_aset_id' => ['sometimes', ...$rules['jenis_aset_id']],
            'acquisition_value' => ['sometimes', ...$rules['acquisition_value']],
            // Ditolak lebih awal dengan pesan yang menjelaskan alasannya, bukan diabaikan
            // diam-diam sehingga pengguna mengira group sudah berganti.
            'group_aset_id' => ['prohibited'],
        ], ['group_aset_id.prohibited' => 'Group aset menentukan buku penyusutan yang sudah terbentuk, jadi tidak dapat diganti di sini.']);

        abort_if(
            ($data['parent_asset_id'] ?? null) === $asset->id,
            422,
            'Aset tidak dapat menjadi induk dirinya sendiri.'
        );

        $periods = DB::table('aset_tr_penyusutan_aset as period')
            ->join('aset_tr_buku_aset as book', 'book.id', '=', 'period.asset_book_id')
            ->where(['period.tenant_id' => $tenantId, 'book.asset_id' => $asset->id])
            ->exists();
        $touchesValue = array_key_exists('acquisition_value', $data) || array_key_exists('residual_value', $data);
        abort_if(
            $periods && $touchesValue,
            409,
            'Nilai perolehan dan residu tidak dapat diubah setelah ada periode penyusutan. Balikkan periodenya terlebih dahulu.'
        );

        $asset = DB::transaction(function () use ($asset, $data, $tenantId, $periods, $touchesValue): Asset {
            // Satu aset dapat dikoreksi dari beberapa instance API sekaligus. Kunci
            // register aset lebih dulu agar penggantian baris atribut tidak saling
            // menyelip di antara delete dan insert.
            $lockedAsset = Asset::query()
                ->where(['tenant_id' => $tenantId, 'id' => $asset->id])
                ->lockForUpdate()
                ->firstOrFail();

            abort_if(
                in_array($lockedAsset->lifecycle_state, ['disposed'], true),
                409,
                'Aset yang sudah dilepas tidak dapat diubah.'
            );

            if (array_intersect(array_keys($data), ['jenis_aset_id', 'pabrikan_aset_id', 'model_aset_id']) !== []) {
                $this->assertModelCombination(
                    $tenantId,
                    array_key_exists('jenis_aset_id', $data) ? $data['jenis_aset_id'] : $lockedAsset->jenis_aset_id,
                    array_key_exists('pabrikan_aset_id', $data) ? $data['pabrikan_aset_id'] : $lockedAsset->pabrikan_aset_id,
                    array_key_exists('model_aset_id', $data) ? $data['model_aset_id'] : $lockedAsset->model_aset_id,
                );
            }

            $lockedAsset->update(array_intersect_key($data, array_flip([
                'nama', 'jenis_aset_id', 'kondisi_aset_id', 'pabrikan_aset_id', 'model_aset_id', 'parent_asset_id',
                'serial_number', 'model_number', 'placed_in_service_on', 'acquisition_value', 'residual_value', 'keterangan',
            ])));

            if ($touchesValue) {
                $this->applyValueChange($lockedAsset, $data, $tenantId);
            }
            // Tanggal mulai digunakan hanya boleh menggeser buku yang belum menyusut.
            // Buku yang sudah berjalan memakai tanggal itu sebagai dasar periode yang
            // terlanjur final, jadi menggesernya membuat riwayatnya tidak konsisten.
            if (array_key_exists('placed_in_service_on', $data) && ! $periods) {
                $this->recalculateStartDates($lockedAsset, $tenantId);
            }
            // Mengganti jenis aset mengganti definisi atributnya, jadi nilainya wajib
            // dikirim ulang: nilai lama milik jenis lama tidak dapat dipercaya lagi.
            if (array_key_exists('atribut', $data) || array_key_exists('jenis_aset_id', $data)) {
                DB::table('aset_tr_aset_atribut')->where(['tenant_id' => $tenantId, 'asset_id' => $lockedAsset->id])->delete();
                $this->saveAttributes($lockedAsset, ['jenis_aset_id' => $lockedAsset->jenis_aset_id, 'atribut' => $data['atribut'] ?? []], $tenantId);
            }

            return $lockedAsset;
        });

        $asset->refresh();

        return response()->json(['data' => [
            ...$this->present($asset),
            'atribut' => $this->attributesOf($tenantId, $asset->id),
        ]]);
    }

    public function history(Request $request, string $id): JsonResponse
    {
        $this->requirePermission($request, 'read');
        $asset = app(OrganizationScope::class)->assetQuery(Asset::query()->where('tenant_id', $this->tenantId($request)), $request)->findOrFail($id);
        $placements = DB::table('aset_tr_penempatan_aset')
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
            'nama' => ['required', 'string', 'max:150'],
            // Dua sumbu wajib dan sejajar: group membawa perlakuan finansial,
            // jenis membawa perlakuan teknis. Tidak ada yang menyaring yang lain.
            'group_aset_id' => ['required', 'ulid', $sameTenant('aset_m_group_aset')],
            'jenis_aset_id' => ['required', 'ulid', $sameTenant('aset_m_jenis_aset')],
            'kondisi_aset_id' => ['nullable', 'ulid', $sameTenant('aset_m_kondisi_aset')],
            'pabrikan_aset_id' => ['nullable', 'ulid', $sameTenant('aset_m_pabrikan_aset')],
            'model_aset_id' => ['nullable', 'ulid', $sameTenant('aset_m_model_aset')],
            'parent_asset_id' => ['nullable', 'ulid', Rule::exists('aset_tr_penerimaan_aset', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'asset_location_id' => ['nullable', 'ulid', $sameTenant('aset_m_lokasi_aset')],
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
            // Buku dan profil ditentukan oleh matriks group x book. Field legacy ini
            // sengaja ditolak agar klien lama tidak diam-diam membuat buku bayangan.
            'depreciation_profile_id' => ['prohibited'],
            'book_code' => ['prohibited'],
            'residual_value' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * F&O hanya membolehkan kombinasi model yang memang dikaitkan dengan jenis aset.
     * Model tanpa jenis tetap boleh dipakai selama jenis tersebut belum memiliki model
     * yang dikaitkan. Pabrikan model selalu harus sama dengan pabrikan pada aset.
     */
    private function assertModelCombination(
        string $tenantId,
        ?string $jenisAsetId,
        ?string $pabrikanAsetId,
        ?string $modelAsetId,
    ): void {
        if (! $modelAsetId) {
            return;
        }

        $model = DB::table('aset_m_model_aset')
            ->where(['tenant_id' => $tenantId, 'id' => $modelAsetId])
            ->whereNull('deleted_at')
            ->first(['pabrikan_aset_id', 'jenis_aset_id', 'aktif']);

        if (! $model) {
            return;
        }

        if (! $model->aktif) {
            throw ValidationException::withMessages([
                'model_aset_id' => 'Model yang dipilih sudah tidak aktif.',
            ]);
        }

        if (! $pabrikanAsetId || $model->pabrikan_aset_id !== $pabrikanAsetId) {
            throw ValidationException::withMessages([
                'model_aset_id' => 'Model harus berasal dari pabrikan yang dipilih.',
            ]);
        }

        if (! $jenisAsetId) {
            return;
        }

        $hasConfiguredModels = DB::table('aset_m_model_aset')
            ->where(['tenant_id' => $tenantId, 'jenis_aset_id' => $jenisAsetId, 'aktif' => true])
            ->whereNull('deleted_at')
            ->exists();

        if ($hasConfiguredModels && $model->jenis_aset_id !== $jenisAsetId) {
            throw ValidationException::withMessages([
                'model_aset_id' => 'Model ini belum dikaitkan dengan jenis aset yang dipilih.',
            ]);
        }

        if (! $hasConfiguredModels && $model->jenis_aset_id !== null) {
            throw ValidationException::withMessages([
                'model_aset_id' => 'Model ini hanya dapat dipakai pada jenis aset yang sudah dikaitkan dengannya.',
            ]);
        }
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

        DB::table('aset_tr_aset_atribut')->insert(array_map(
            fn (array $row): array => [...$row, 'asset_id' => $asset->id],
            $rows,
        ));
        DB::table('aset_m_tipe_atribut')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', collect($rows)->pluck('tipe_atribut_id')->unique()->all())
            ->update(['data_type_locked' => true, 'updated_at' => now()]);
    }

    /**
     * Nilai atribut satu aset, lengkap dengan definisinya.
     *
     * Nilainya disimpan pada kolom bertipe agar PostgreSQL dapat menegakkan tipe dan
     * meng-index-nya, lalu disatukan kembali menjadi satu kunci `nilai` di sini supaya
     * klien tidak perlu tahu kolom mana yang terpakai untuk tipe data mana.
     *
     * @return list<array<string, mixed>>
     */
    private function attributesOf(string $tenantId, string $assetId): array
    {
        return DB::table('aset_tr_aset_atribut as nilai')
            ->join('aset_m_tipe_atribut as tipe', function ($join): void {
                $join->on('tipe.id', '=', 'nilai.tipe_atribut_id')->on('tipe.tenant_id', '=', 'nilai.tenant_id');
            })
            ->where(['nilai.tenant_id' => $tenantId, 'nilai.asset_id' => $assetId])
            ->orderBy('tipe.nama')
            ->get(['tipe.id as tipe_atribut_id', 'tipe.kode', 'tipe.nama', 'tipe.data_type', 'tipe.satuan',
                'nilai.nilai_text', 'nilai.nilai_number', 'nilai.nilai_boolean', 'nilai.nilai_date', 'nilai.tipe_atribut_nilai_id'])
            ->map(fn (object $row): array => [
                'tipe_atribut_id' => $row->tipe_atribut_id,
                'kode' => $row->kode,
                'nama' => $row->nama,
                'data_type' => $row->data_type,
                'satuan' => $row->satuan,
                'tipe_atribut_nilai_id' => $row->tipe_atribut_nilai_id,
                'nilai' => match ($row->data_type) {
                    'decimal', 'integer' => $row->nilai_number === null ? null : (float) $row->nilai_number,
                    'boolean' => $row->nilai_boolean === null ? null : (bool) $row->nilai_boolean,
                    'date' => $row->nilai_date === null ? null : substr((string) $row->nilai_date, 0, 10),
                    default => $row->nilai_text,
                },
            ])
            ->all();
    }

    /**
     * Menyesuaikan buku aset setelah nilai perolehan atau residu dikoreksi.
     *
     * Hanya dijalankan saat belum ada periode penyusutan sama sekali, sehingga akumulasi
     * masih nol dan nilai buku dapat disamakan langsung dengan nilai perolehan yang baru.
     *
     * @param  array<string, mixed>  $data
     */
    private function applyValueChange(Asset $asset, array $data, string $tenantId): void
    {
        $changes = ['updated_at' => now()];
        if (array_key_exists('acquisition_value', $data)) {
            $changes['acquisition_value'] = $data['acquisition_value'];
            $changes['net_book_value'] = $data['acquisition_value'];
        }
        if (array_key_exists('residual_value', $data)) {
            $changes['residual_value'] = $data['residual_value'] ?? 0;
        }
        DB::table('aset_tr_buku_aset')->where(['tenant_id' => $tenantId, 'asset_id' => $asset->id])->update($changes);
    }

    /**
     * Menghitung ulang tanggal mulai menyusut setelah tanggal aset mulai digunakan
     * dikoreksi. Konvensi tiap buku berbeda, jadi pergeserannya dihitung per buku.
     */
    private function recalculateStartDates(Asset $asset, string $tenantId): void
    {
        $calculator = app(DepreciationCalculator::class);
        $placedInService = (string) ($asset->placed_in_service_on ?? $asset->acquired_on);
        $books = DB::table('aset_tr_buku_aset')
            ->where(['tenant_id' => $tenantId, 'asset_id' => $asset->id])
            ->get(['id', 'convention', 'depreciation_profile_id']);

        foreach ($books as $book) {
            DB::table('aset_tr_buku_aset')->where('id', $book->id)->update([
                'depreciation_start_on' => $calculator->startDate(
                    $placedInService,
                    $book->convention,
                    $this->fiscalYear($tenantId, (string) $asset->legal_entity_id, $placedInService, $book->depreciation_profile_id, $book->convention),
                )->toDateString(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Buku aset dibentuk dari matriks group x buku: satu baris matriks menghasilkan satu
     * buku, sehingga aset dapat menyusut komersial dan fiskal sekaligus dengan aturannya
     * masing-masing. Aturan matriks disalin ke buku, bukan dirujuk hidup-hidup, supaya
     * perubahan matriks kelak tidak menulis ulang aset yang sudah berjalan.
     *
     * Bila matriks belum diisi, aset tetap boleh tercatat sebagai `received`, tetapi tidak
     * dapat ditempatkan atau mulai disusutkan sampai matriks menyediakan buku dan profil.
     *
     * @param  array<string, mixed>  $data
     */
    private function createBooks(Asset $asset, array $data, string $tenantId): void
    {
        $threshold = DB::table('aset_m_group_aset')
            ->where(['tenant_id' => $tenantId, 'id' => $data['group_aset_id']])
            ->value('capitalization_threshold');
        // Perolehan di bawah ambang kapitalisasi tetap dicatat sebagai aset, tetapi
        // bukunya tidak menyusut. Ini perilaku yang sama dengan F&O.
        $capitalized = $threshold === null || (float) $data['acquisition_value'] >= (float) $threshold;

        $rows = DB::table('aset_m_group_buku_penyusutan as matrix')
            ->join('aset_m_buku_penyusutan as buku', function ($join): void {
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
            return;
        }

        foreach ($rows as $row) {
            // Buku yang memang tidak menghitung tidak memerlukan profil. F&O pun tidak
            // menuntutnya pada buku dengan "Calculate depreciation = No". Tanpa ini,
            // group yang sengaja tidak disusutkan dan aset di bawah ambang kapitalisasi
            // sama-sama memaksa tenant mengarang profil yang tidak pernah dipakai.
            $depreciates = $capitalized && (bool) $row->depreciate;
            $profile = $depreciates ? $this->requireComputableProfile(
                $tenantId,
                $row->depreciation_profile_id,
                $row->useful_life_periods,
                $row->convention,
                checkEffectiveDate: false,
            ) : null;
            if ($depreciates && $row->alternative_profile_id) {
                $this->requireComputableProfile($tenantId, $row->alternative_profile_id, null, null, checkEffectiveDate: false);
            }
            $usefulLife = $row->useful_life_periods ?? $profile?->useful_life_periods;
            $convention = $row->convention ?? $profile?->convention;
            AssetBook::query()->create([
                'tenant_id' => $tenantId,
                'asset_id' => $asset->id,
                'buku_id' => $row->buku_id,
                'depreciation_profile_id' => $row->depreciation_profile_id,
                'alternative_profile_id' => $row->alternative_profile_id,
                'book_code' => $row->buku_code,
                'useful_life_periods' => $usefulLife,
                'convention' => $convention,
                'depreciation_start_on' => $calculator->startDate(
                    $placedInService,
                    $convention,
                    $this->fiscalYear($tenantId, (string) $data['legal_entity_id'], $placedInService, $row->depreciation_profile_id, $convention),
                )->toDateString(),
                'depreciate' => $depreciates,
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
        $yearBasis = DB::table('aset_m_profil_penyusutan')
            ->where(['tenant_id' => $tenantId, 'id' => $profileId])
            ->value('year_basis');
        if ($yearBasis !== 'fiscal') {
            return null;
        }

        try {
            $fiscal = app(KalenderFiskalAset::class)->resolve($tenantId, $legalEntityId, $placedInService);
        } catch (RuntimeException) {
            return null;
        }

        return $fiscal['year'] ?? null;
    }

    /**
     * Penempatan adalah batas ketika aset boleh menjadi `in_use`. Pada titik ini buku
     * harus sudah berasal dari matriks dan setiap buku yang dihitung harus memiliki
     * profil aktif serta rentang berlaku yang mencakup tanggal penempatan.
     */
    private function assertDepreciationReady(Asset $asset, string $tenantId, string $effectiveOn): void
    {
        $books = DB::table('aset_tr_buku_aset')
            ->where(['tenant_id' => $tenantId, 'asset_id' => $asset->id, 'status' => 'active'])
            ->get([
                'buku_id', 'depreciation_profile_id', 'alternative_profile_id',
                'useful_life_periods', 'convention', 'depreciate',
            ]);
        if ($books->isEmpty()) {
            throw ValidationException::withMessages([
                'group_aset_id' => 'Group aset belum memiliki Buku penyusutan yang lengkap. Isi matriks group x book terlebih dahulu sebelum menempatkan aset.',
            ]);
        }

        foreach ($books as $book) {
            if (! $book->buku_id) {
                throw ValidationException::withMessages([
                    'group_aset_id' => 'Buku aset lama belum terhubung ke Buku penyusutan. Perbaiki matriks group x book sebelum menempatkan aset.',
                ]);
            }
            // Buku yang tidak menghitung tidak punya angka untuk diperiksa. Menuntutnya
            // punya profil yang berlaku akan menahan aset register-saja dan aset di
            // bawah ambang kapitalisasi di status `received` selamanya.
            if (! $book->depreciate) {
                continue;
            }
            $this->requireComputableProfile(
                $tenantId,
                $book->depreciation_profile_id,
                $book->useful_life_periods,
                $book->convention,
                checkEffectiveDate: true,
                effectiveOn: $effectiveOn,
            );
            if ($book->alternative_profile_id) {
                $this->requireComputableProfile(
                    $tenantId,
                    $book->alternative_profile_id,
                    null,
                    null,
                    checkEffectiveDate: true,
                    effectiveOn: $effectiveOn,
                );
            }
        }
    }

    /**
     * Ambil profil yang benar-benar dapat dipakai oleh calculator. Validasi ini juga
     * melindungi data legacy yang dibuat sebelum matriks menjadi sumber konfigurasi.
     */
    private function requireComputableProfile(
        string $tenantId,
        ?string $profileId,
        mixed $usefulLifeOverride,
        ?string $conventionOverride,
        bool $checkEffectiveDate,
        ?string $effectiveOn = null,
    ): object {
        if (! $profileId) {
            throw ValidationException::withMessages([
                'group_aset_id' => 'Buku penyusutan belum memiliki profil utama yang efektif. Pilih profil pada matriks group x book atau pada Buku penyusutan.',
            ]);
        }

        $profile = DB::table('aset_m_profil_penyusutan')
            ->where(['tenant_id' => $tenantId, 'id' => $profileId, 'aktif' => true])
            ->whereNull('deleted_at')
            ->first([
                'id', 'method', 'frequency', 'convention', 'useful_life_periods', 'rate_percent',
                'manual_schedule', 'effective_from', 'effective_to',
            ]);
        if (! $profile) {
            throw ValidationException::withMessages([
                'group_aset_id' => 'Profil penyusutan pada Buku atau matriks sudah tidak aktif. Pilih profil yang masih berlaku.',
            ]);
        }

        $errors = [];
        if (! in_array($profile->method, ProfilPenyusutan::METHODS, true)) {
            $errors['group_aset_id'] = 'Metode profil penyusutan belum dapat dihitung oleh aplikasi.';
        }
        if (! in_array($profile->frequency, ProfilPenyusutan::FREQUENCIES, true)) {
            $errors['group_aset_id'] = 'Frekuensi profil penyusutan belum dapat dihitung oleh aplikasi.';
        }
        $convention = $conventionOverride ?? $profile->convention;
        if ($convention !== null && ! in_array($convention, BukuPenyusutan::CONVENTIONS, true)) {
            $errors['group_aset_id'] = 'Konvensi penyusutan pada Buku atau profil tidak dikenal.';
        }
        $usefulLife = $usefulLifeOverride ?? $profile->useful_life_periods;
        if (in_array($profile->method, ['straight_line', 'straight_line_life_remaining', 'reducing_balance'], true) && ! $usefulLife) {
            $errors['group_aset_id'] = 'Masa manfaat belum diisi pada matriks atau profil penyusutan.';
        }
        if ($profile->method === 'reducing_balance' && ($profile->rate_percent === null || (float) $profile->rate_percent <= 0)) {
            $errors['group_aset_id'] = 'Profil saldo menurun harus memiliki persentase per tahun yang lebih besar dari 0.';
        }
        if ($profile->method === 'manual' && ! $this->hasManualSchedule($profile->manual_schedule)) {
            $errors['group_aset_id'] = 'Profil dengan jadwal manual belum memiliki nilai penyusutan per periode.';
        }
        if ($checkEffectiveDate && $effectiveOn !== null) {
            $date = substr($effectiveOn, 0, 10);
            if ($profile->effective_from !== null && $date < substr((string) $profile->effective_from, 0, 10)) {
                $errors['effective_on'] = 'Profil penyusutan belum berlaku pada tanggal penempatan aset.';
            }
            if ($profile->effective_to !== null && $date > substr((string) $profile->effective_to, 0, 10)) {
                $errors['effective_on'] = 'Profil penyusutan sudah tidak berlaku pada tanggal penempatan aset.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $profile;
    }

    private function hasManualSchedule(mixed $schedule): bool
    {
        if (is_string($schedule)) {
            $schedule = json_decode($schedule, true);
        }

        return is_array($schedule) && $schedule !== [] && collect($schedule)->every(
            fn (mixed $row): bool => is_array($row) && array_key_exists('amount', $row) && is_numeric($row['amount']) && (float) $row['amount'] >= 0,
        );
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

        return DB::table('aset_m_lokasi_aset')
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
        return DB::table('aset_m_group_aset')
            ->where(['tenant_id' => $tenantId, 'id' => $groupAsetId])
            ->select('kelompok_harta_fiskal_id', 'asset_location_id')
            ->first();
    }

    /** @return array<string, mixed> */
    private function present(Asset $asset): array
    {
        return $asset->only(['id', 'kode', 'nama', 'legal_entity_id', 'responsible_org_unit_id', 'group_aset_id', 'kelompok_harta_fiskal_id', 'jenis_aset_id', 'kondisi_aset_id', 'pabrikan_aset_id', 'model_aset_id', 'parent_asset_id', 'asset_location_id', 'financial_dimension_org_unit_id', 'serial_number', 'model_number', 'acquired_on', 'placed_in_service_on', 'acquisition_value', 'currency_code', 'lifecycle_state', 'keterangan']);
    }
}
