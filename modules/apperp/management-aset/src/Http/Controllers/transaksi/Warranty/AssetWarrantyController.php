<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\transaksi\Warranty;

use App\Platform\Modules\Contracts\RequestContext;
use App\Platform\Modules\Contracts\RowVersion;
use App\Platform\Modules\Contracts\VendorDirectory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Apperp\ManagementAset\Http\Controllers\Controller;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;
use Modules\Apperp\ManagementAset\Models\transaksi\ServiceContract\ServiceContract;
use Modules\Apperp\ManagementAset\Models\transaksi\ServiceContract\ServiceContractLine;
use Modules\Apperp\ManagementAset\Models\transaksi\Warranty\AssetWarranty;
use Modules\Apperp\ManagementAset\Services\ActiveWarranties;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;
use stdClass;

/**
 * Garansi aset; padanan *Vendor warranty* pada aset Dynamics 365 F&O Asset Management.
 *
 * Satu garansi menempel pada satu aset dan mengikuti jangkauan organisasi asetnya. Garansi tambahan
 * dicatat sebagai baris baru, sehingga riwayat cakupan aset tetap terbaca. Berbeda dari pertanggungan
 * asuransi, baris garansi boleh disunting: ia salinan kartu garansi, bukan angka yang dijumlahkan.
 *
 * Controller ini juga melayani dua bacaan lintas garansi dan kontrak servis: daftar yang akan berakhir,
 * dan pemberitahuan garansi aktif untuk work order.
 */
class AssetWarrantyController extends Controller
{
    private const RESOURCE = 'garansi-aset';

    private const TABLE = 'aset_tr_garansi_aset';

    private const MAX_CREATION_KEY = 140;

    /** Pilihan rentang daftar akan berakhir; bawaannya 30 hari. */
    public const EXPIRY_WINDOWS = [30, 60, 90];

    public function index(Request $request): JsonResponse
    {
        $this->guard($request, 'read');
        $filter = $request->validate([
            'aset_id' => ['nullable', 'ulid'],
            'status' => ['nullable', Rule::in(['berlaku', 'berakhir'])],
        ]);
        $today = $this->today();

        $query = $this->joined(AssetWarranty::query(), $request);
        if ($filter['aset_id'] ?? null) {
            $query->where(self::TABLE.'.aset_id', $filter['aset_id']);
        }
        match ($filter['status'] ?? null) {
            'berlaku' => $query->where(self::TABLE.'.berlaku_sampai', '>=', $today),
            'berakhir' => $query->where(self::TABLE.'.berlaku_sampai', '<', $today),
            default => null,
        };

        $tenant = $this->tenant($request);
        $rows = $query->orderByDesc(self::TABLE.'.berlaku_sampai')->limit(500)->toBase()->get()
            ->map(fn (stdClass $row): stdClass => $this->present($tenant, $row, $today));

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->guard($request, 'create');
        $key = (string) validator(
            ['key' => $request->header('Idempotency-Key')],
            ['key' => ['required', 'string', 'max:'.self::MAX_CREATION_KEY, 'regex:/^[A-Za-z0-9._:-]+$/']],
        )->validate()['key'];
        if ($existing = $this->replay($request, $key)) {
            return response()->json(['data' => $existing], 200, ['Idempotent-Replayed' => 'true']);
        }

        $data = $this->validated($request, true);
        $aset = $this->aset($request, $data['aset_id']);
        $this->validateVendor($request, $aset, $data['vendor_id'] ?? null);

        try {
            AssetWarranty::query()->create([
                'tenant_id' => $this->tenant($request),
                'creation_key' => $key,
                'aset_id' => $data['aset_id'],
                ...$this->values($data),
            ]);
        } catch (QueryException $exception) {
            $existing = $this->replay($request, $key);
            if ($existing === null) {
                throw $exception;
            }

            return response()->json(['data' => $existing], 200, ['Idempotent-Replayed' => 'true']);
        }

        return response()->json(['data' => $this->replay($request, $key)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->guard($request, 'update');
        $warranty = $this->find($request, $id);
        $data = $this->validated($request, false);
        $version = RowVersion::expected($request);
        $this->validateVendor($request, $this->aset($request, $warranty->aset_id), $data['vendor_id'] ?? null);

        DB::transaction(function () use ($id, $data, $version): void {
            RowVersion::claim(AssetWarranty::query()->whereKey($id), $version);
            AssetWarranty::query()->whereKey($id)->update([...$this->values($data), 'updated_at' => now()]);
        });

        return response()->json(['data' => $this->one($request, $id)]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->guard($request, 'archive');
        $this->find($request, $id);
        $version = RowVersion::expected($request);

        DB::transaction(function () use ($id, $version): void {
            RowVersion::claim(AssetWarranty::query()->whereKey($id), $version);
            AssetWarranty::query()->whereKey($id)->update(['deleted_at' => now(), 'updated_at' => now()]);
        });

        return response()->json(status: 204);
    }

    /** Vendor aktif satu entitas legal untuk pemilih penjamin garansi dan vendor kontrak servis. */
    public function vendor(Request $request): JsonResponse
    {
        $permissions = $request->attributes->get('coreerp.permissions', []);
        abort_unless(
            in_array('management-aset.garansi-aset.read', $permissions, true) || in_array('management-aset.kontrak-servis.read', $permissions, true),
            403,
        );
        $query = $request->validate([
            'legal_entity_id' => ['required', 'ulid'],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        return response()->json(['data' => app(VendorDirectory::class)->active($this->tenant($request), $query['legal_entity_id'], (string) ($query['q'] ?? ''))]);
    }

    /**
     * Garansi dan kontrak servis yang berakhir dalam 30, 60, atau 90 hari ke depan, termasuk hari ini.
     * Masing-masing hanya tampil bila pengguna boleh membacanya; garansi tersaring unit asetnya, kontrak
     * tersaring entitas legalnya.
     */
    public function expiring(Request $request): JsonResponse
    {
        $permissions = $request->attributes->get('coreerp.permissions', []);
        $warranties = in_array('management-aset.garansi-aset.read', $permissions, true);
        $contracts = in_array('management-aset.kontrak-servis.read', $permissions, true);
        abort_unless($warranties || $contracts, 403);
        $days = (int) ($request->validate(['hari' => ['nullable', 'integer', Rule::in(self::EXPIRY_WINDOWS)]])['hari'] ?? self::EXPIRY_WINDOWS[0]);
        $today = $this->today();
        $until = Carbon::parse($today)->addDays($days)->toDateString();
        $tenant = $this->tenant($request);
        $items = [];

        if ($warranties) {
            $rows = $this->joined(AssetWarranty::query(), $request)
                ->whereBetween(self::TABLE.'.berlaku_sampai', [$today, $until])
                ->toBase()->get();
            foreach ($rows as $row) {
                $row = $this->present($tenant, $row, $today);
                $items[] = [
                    'jenis' => 'garansi',
                    'id' => $row->id,
                    'referensi' => $row->nomor_referensi,
                    'aset_id' => $row->aset_id,
                    'aset_kode' => $row->aset_kode,
                    'aset_nama' => $row->aset_nama,
                    'jumlah_aset' => 1,
                    'vendor_nama' => $row->vendor['name'] ?? null,
                    'berlaku_sampai' => $row->berlaku_sampai,
                    'sisa_hari' => $row->sisa_hari,
                ];
            }
        }

        if ($contracts) {
            $query = ServiceContract::query()->whereBetween('berlaku_sampai', [$today, $until]);
            app(OrganizationScope::class)->legalEntityQuery($query, $request, 'aset_tr_kontrak_servis.legal_entity_id');
            foreach ($query->get() as $contract) {
                $vendor = app(VendorDirectory::class)->find($tenant, $contract->vendor_id);
                $items[] = [
                    'jenis' => 'kontrak_servis',
                    'id' => $contract->id,
                    'referensi' => $contract->kode.' · '.$contract->nomor_kontrak,
                    'aset_id' => null,
                    'aset_kode' => null,
                    'aset_nama' => null,
                    'jumlah_aset' => ServiceContractLine::query()->where('kontrak_servis_id', $contract->id)->count(),
                    'vendor_nama' => $vendor['name'] ?? null,
                    'berlaku_sampai' => $contract->berlaku_sampai->toDateString(),
                    'sisa_hari' => (int) Carbon::parse($today)->diffInDays($contract->berlaku_sampai),
                ];
            }
        }

        usort($items, static fn (array $a, array $b): int => [$a['berlaku_sampai'], $a['referensi']] <=> [$b['berlaku_sampai'], $b['referensi']]);

        return response()->json(['data' => $items, 'meta' => ['hari' => $days, 'sampai' => $until]]);
    }

    /**
     * Garansi dan kontrak servis aktif untuk aset-aset sebuah work order pada tanggal mulainya. Dijaga
     * permission baca work order, bukan permission garansi: pemberitahuan ini bagian dari layar work
     * order, dan isinya hanya ringkasan yang dibutuhkan perencana.
     */
    public function forWorkOrder(Request $request, ActiveWarranties $active): JsonResponse
    {
        abort_unless(in_array('management-aset.pemeliharaan-aset.read', $request->attributes->get('coreerp.permissions', []), true), 403);
        $data = $request->validate([
            'aset_id' => ['required', 'array', 'max:100'],
            'aset_id.*' => ['required', 'ulid'],
            'tanggal' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $query = Aset::query()->whereIn('aset_tr_aset.id', array_values(array_unique($data['aset_id'])));
        app(OrganizationScope::class)->asetQuery($query, $request);
        $asetIds = array_values(array_map(strval(...), $query->pluck('aset_tr_aset.id')->all()));

        return response()->json(['data' => $active->forAssets($this->tenant($request), $asetIds, $data['tanggal'] ?? $this->today())]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'aset_id' => [$creating ? 'required' : 'prohibited', 'ulid'],
            'vendor_id' => ['nullable', 'ulid'],
            'jenis_garansi' => ['required', Rule::in(array_keys(AssetWarranty::TYPES))],
            'nomor_referensi' => ['nullable', 'string', 'max:80'],
            'berlaku_mulai' => ['required', 'date_format:Y-m-d'],
            'berlaku_sampai' => ['required', 'date_format:Y-m-d', 'after_or_equal:berlaku_mulai'],
            'catatan' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function values(array $data): array
    {
        $reference = trim((string) ($data['nomor_referensi'] ?? ''));
        $note = trim((string) ($data['catatan'] ?? ''));

        return [
            'vendor_id' => $data['vendor_id'] ?? null,
            'jenis_garansi' => $data['jenis_garansi'],
            'nomor_referensi' => $reference === '' ? null : $reference,
            'berlaku_mulai' => $data['berlaku_mulai'],
            'berlaku_sampai' => $data['berlaku_sampai'],
            'catatan' => $note === '' ? null : $note,
        ];
    }

    /** Penjamin wajib vendor entitas legal aset. */
    private function validateVendor(Request $request, Aset $aset, ?string $vendorId): void
    {
        if ($vendorId === null) {
            return;
        }
        $vendor = app(VendorDirectory::class)->find($this->tenant($request), $vendorId);
        if ($vendor === null || $vendor['legal_entity_id'] !== $aset->legal_entity_id) {
            throw ValidationException::withMessages(['vendor_id' => 'Pilih penjamin dari vendor entitas legal aset ini.']);
        }
    }

    private function aset(Request $request, string $asetId): Aset
    {
        $query = Aset::query()->whereKey($asetId);
        app(OrganizationScope::class)->asetQuery($query, $request);
        $aset = $query->first();
        if ($aset === null) {
            throw ValidationException::withMessages(['aset_id' => 'Aset tidak ditemukan atau berada di luar unit kerja yang dapat Anda akses.']);
        }

        return $aset;
    }

    private function find(Request $request, string $id): AssetWarranty
    {
        $warranty = AssetWarranty::query()
            ->whereKey($id)
            ->whereIn('aset_id', app(OrganizationScope::class)->asetQuery(Aset::query(), $request)->select('aset_tr_aset.id'))
            ->first();
        abort_if($warranty === null, 404);

        return $warranty;
    }

    private function one(Request $request, string $id): ?stdClass
    {
        $row = $this->joined(AssetWarranty::query(), $request)->where(self::TABLE.'.id', $id)->toBase()->first();

        return $row === null ? null : $this->present($this->tenant($request), $row, $this->today());
    }

    /** Replay tanpa saringan arsip: kunci idempotensi sudah membuktikan pemiliknya. */
    private function replay(Request $request, string $key): ?stdClass
    {
        $row = $this->joined(AssetWarranty::withTrashed(), $request)->where(self::TABLE.'.creation_key', $key)->toBase()->first();

        return $row === null ? null : $this->present($this->tenant($request), $row, $this->today());
    }

    /**
     * Garansi beserta aset dan jenis asetnya, tersaring jangkauan organisasi lewat aset.
     *
     * @param  Builder<AssetWarranty>  $query
     * @return Builder<AssetWarranty>
     */
    private function joined(Builder $query, Request $request): Builder
    {
        $query->join('aset_tr_aset', function (JoinClause $join): void {
            $join->on('aset_tr_aset.id', '=', self::TABLE.'.aset_id')->on('aset_tr_aset.tenant_id', '=', self::TABLE.'.tenant_id');
        })->select([self::TABLE.'.*', 'aset_tr_aset.kode as aset_kode', 'aset_tr_aset.nama as aset_nama', 'aset_tr_aset.legal_entity_id']);
        app(OrganizationScope::class)->asetQuery($query, $request);

        return $query;
    }

    private function present(string $tenant, stdClass $row, string $today): stdClass
    {
        $vendor = $row->vendor_id === null ? null : app(VendorDirectory::class)->find($tenant, (string) $row->vendor_id);
        $row->vendor = $vendor === null ? null : ['id' => $vendor['id'], 'number' => $vendor['number'], 'name' => $vendor['name'], 'status' => $vendor['status']];
        $row->jenis_garansi_label = AssetWarranty::TYPES[$row->jenis_garansi] ?? $row->jenis_garansi;
        $row->berlaku = $row->berlaku_mulai <= $today && $row->berlaku_sampai >= $today;
        $row->sisa_hari = $row->berlaku_sampai < $today ? 0 : (int) Carbon::parse($today)->diffInDays(Carbon::parse($row->berlaku_sampai));

        return $row;
    }

    private function today(): string
    {
        return Carbon::now(app(RequestContext::class)->timezone())->toDateString();
    }

    private function guard(Request $request, string $action): void
    {
        abort_unless(in_array('management-aset.'.self::RESOURCE.'.'.$action, $request->attributes->get('coreerp.permissions', []), true), 403);
    }

    private function tenant(Request $request): string
    {
        return (string) $request->attributes->get('coreerp.tenant_id');
    }
}
