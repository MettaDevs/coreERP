<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\transaksi\ServiceContract;

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
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Apperp\ManagementAset\Http\Controllers\Controller;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;
use Modules\Apperp\ManagementAset\Models\transaksi\ServiceContract\ServiceContract;
use Modules\Apperp\ManagementAset\Models\transaksi\ServiceContract\ServiceContractLine;
use Modules\Apperp\ManagementAset\Services\AssetNumberSequenceIssuer;
use Modules\Apperp\ManagementAset\Services\NumberSequenceException;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;

/**
 * Kontrak servis dengan vendor pemeliharaan: satu vendor, satu periode, banyak aset.
 *
 * Business Central hanya menyimpan *Maintenance Vendor No.* pada kartu aset, dan F&O menyimpan
 * warranty agreement per aset; kontrak yang menanggung banyak aset sekaligus dengan satu nomor dan satu
 * berkas kontrak lebih dekat dengan kenyataan, jadi ia dokumen sendiri dengan baris aset.
 *
 * Kontrak milik satu entitas legal tanpa unit, seperti polis asuransi. Barisnya mengikuti jangkauan
 * asetnya: pengguna hanya melihat dan menambahkan aset dalam unitnya, dan baris aset di luar jangkauannya
 * tidak tersentuh saat ia menyimpan.
 */
class ServiceContractController extends Controller
{
    private const RESOURCE = 'kontrak-servis';

    private const TABLE = 'aset_tr_kontrak_servis';

    private const LINES = 'aset_tr_kontrak_servis_aset';

    private const MAX_CREATION_KEY = 140;

    public function index(Request $request): JsonResponse
    {
        $this->guard($request, 'read');
        $filter = $request->validate(['legal_entity_id' => ['nullable', 'ulid'], 'aset_id' => ['nullable', 'ulid']]);
        $query = $this->scoped(ServiceContract::query(), $request)
            ->withCount('lines')
            ->orderByDesc('berlaku_sampai');
        if ($filter['legal_entity_id'] ?? null) {
            $query->where(self::TABLE.'.legal_entity_id', $filter['legal_entity_id']);
        }
        // Kontrak yang menanggung satu aset, untuk bagian garansi di detail aset.
        if ($filter['aset_id'] ?? null) {
            $query->whereHas('lines', fn ($lines) => $lines->where('aset_id', $filter['aset_id']));
        }
        $tenant = $this->tenant($request);
        $today = $this->today();

        return response()->json(['data' => $query->limit(500)->get()->map(fn (ServiceContract $contract): array => $this->present($tenant, $contract, $today))]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->guard($request, 'read');

        return $this->document($request, $id);
    }

    public function store(Request $request, AssetNumberSequenceIssuer $numbers): JsonResponse
    {
        $this->guard($request, 'create');
        $key = $this->creationKey($request);
        $tenant = $this->tenant($request);
        if ($existing = ServiceContract::withTrashed()->where('creation_key', $key)->first()) {
            return response()->json(['data' => ['id' => $existing->id, 'kode' => $existing->kode]], 200, ['Idempotent-Replayed' => 'true']);
        }

        $data = $this->validated($request);
        app(OrganizationScope::class)->require($request, $data['legal_entity_id'], null);
        $this->validateVendor($tenant, $data);
        $this->validateAssets($request, $data['legal_entity_id'], $data['aset_ids'], []);

        try {
            $kode = $numbers->issue('management-aset.'.self::RESOURCE, $tenant, self::RESOURCE.':'.$key, $data['legal_entity_id']);
        } catch (NumberSequenceException $exception) {
            return response()->json(['error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()]], NumberSequenceException::HTTP_STATUS);
        }

        $id = (string) Str::ulid();
        try {
            DB::transaction(function () use ($request, $id, $tenant, $key, $kode, $data): void {
                (new ServiceContract)->forceFill([
                    'id' => $id,
                    'tenant_id' => $tenant,
                    'creation_key' => $key,
                    'kode' => $kode,
                    'legal_entity_id' => $data['legal_entity_id'],
                    ...$this->values($data),
                ])->save();
                $this->syncLines($request, $id, $data['aset_ids']);
            });
        } catch (QueryException $exception) {
            $existing = ServiceContract::withTrashed()->where('creation_key', $key)->first();
            if ($existing === null) {
                throw $exception;
            }

            return response()->json(['data' => ['id' => $existing->id, 'kode' => $existing->kode]], 200, ['Idempotent-Replayed' => 'true']);
        }

        return $this->document($request, $id, 201);
    }

    /**
     * Mengubah kontrak dan menyamakan daftar asetnya dengan yang dikirim. Daftar aset wajib dikirim,
     * walau kosong: yang tidak dikirim dikeluarkan dari kontrak.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $this->guard($request, 'update');
        $contract = $this->find($request, $id);
        $data = $this->validated($request);
        $version = RowVersion::expected($request);
        if ($data['legal_entity_id'] !== $contract->legal_entity_id) {
            throw ValidationException::withMessages(['legal_entity_id' => 'Entitas legal kontrak tidak dapat diganti; nomornya terbit untuk entitas legal ini.']);
        }
        $this->validateVendor($this->tenant($request), $data);
        $current = array_values(ServiceContractLine::query()->where('kontrak_servis_id', $id)->pluck('aset_id')->map(strval(...))->all());
        $this->validateAssets($request, $data['legal_entity_id'], $data['aset_ids'], $current);

        DB::transaction(function () use ($request, $id, $data, $version): void {
            RowVersion::claim(ServiceContract::query()->whereKey($id), $version);
            ServiceContract::query()->whereKey($id)->update([...$this->values($data), 'updated_at' => now()]);
            $this->syncLines($request, $id, $data['aset_ids']);
        });

        return $this->document($request, $id);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->guard($request, 'archive');
        $this->find($request, $id);
        $version = RowVersion::expected($request);

        DB::transaction(function () use ($id, $version): void {
            RowVersion::claim(ServiceContract::query()->whereKey($id), $version);
            ServiceContract::query()->whereKey($id)->update(['deleted_at' => now(), 'updated_at' => now()]);
        });

        return response()->json(status: 204);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'legal_entity_id' => ['required', 'ulid'],
            'nomor_kontrak' => ['required', 'string', 'max:80'],
            'vendor_id' => ['required', 'ulid'],
            'berlaku_mulai' => ['required', 'date_format:Y-m-d'],
            'berlaku_sampai' => ['required', 'date_format:Y-m-d', 'after_or_equal:berlaku_mulai'],
            'cakupan' => ['nullable', 'string', 'max:4000'],
            'nilai_kontrak' => ['nullable', 'numeric', 'min:0', 'max:9999999999999999'],
            'keterangan' => ['nullable', 'string', 'max:2000'],
            'aset_ids' => ['present', 'array', 'max:1000'],
            'aset_ids.*' => ['required', 'ulid', 'distinct'],
        ]);
        $data['aset_ids'] = array_values(array_map(strval(...), $data['aset_ids']));

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function values(array $data): array
    {
        $text = static fn (mixed $value): ?string => trim((string) $value) === '' ? null : trim((string) $value);

        return [
            'nomor_kontrak' => trim((string) $data['nomor_kontrak']),
            'vendor_id' => $data['vendor_id'],
            'berlaku_mulai' => $data['berlaku_mulai'],
            'berlaku_sampai' => $data['berlaku_sampai'],
            'cakupan' => $text($data['cakupan'] ?? null),
            'nilai_kontrak' => $data['nilai_kontrak'] ?? null,
            'keterangan' => $text($data['keterangan'] ?? null),
        ];
    }

    /** @param  array<string, mixed>  $data */
    private function validateVendor(string $tenant, array $data): void
    {
        $vendor = app(VendorDirectory::class)->find($tenant, (string) $data['vendor_id']);
        if ($vendor === null || $vendor['legal_entity_id'] !== $data['legal_entity_id']) {
            throw ValidationException::withMessages(['vendor_id' => 'Pilih vendor servis dari vendor entitas legal kontrak ini.']);
        }
    }

    /**
     * Aset baru wajib dalam jangkauan pengguna dan milik entitas legal kontrak. Aset yang sudah ada di
     * kontrak tidak diperiksa ulang.
     *
     * @param  list<string>  $asetIds
     * @param  list<string>  $current
     */
    private function validateAssets(Request $request, string $legalEntityId, array $asetIds, array $current): void
    {
        $new = array_values(array_diff($asetIds, $current));
        if ($new === []) {
            return;
        }
        $query = Aset::query()->whereIn('aset_tr_aset.id', $new);
        app(OrganizationScope::class)->asetQuery($query, $request);
        $found = $query->toBase()->get(['aset_tr_aset.id', 'aset_tr_aset.kode', 'aset_tr_aset.legal_entity_id']);
        if ($found->count() !== count($new)) {
            throw ValidationException::withMessages(['aset_ids' => 'Aset tidak ditemukan atau berada di luar unit kerja yang dapat Anda akses.']);
        }
        foreach ($found as $aset) {
            if ($aset->legal_entity_id !== $legalEntityId) {
                throw ValidationException::withMessages(['aset_ids' => 'Aset '.$aset->kode.' milik entitas legal lain; kontrak hanya menanggung aset entitas legalnya sendiri.']);
            }
        }
    }

    /**
     * Menyamakan baris aset dengan daftar yang dikirim, hanya di dalam jangkauan pengguna: baris aset di
     * luar unitnya tidak terlihat olehnya, jadi tidak pernah dianggap "tidak dikirim".
     *
     * @param  list<string>  $asetIds
     */
    private function syncLines(Request $request, string $contractId, array $asetIds): void
    {
        $visible = app(OrganizationScope::class)->asetQuery(Aset::query(), $request)->select('aset_tr_aset.id');
        $existing = ServiceContractLine::query()->where('kontrak_servis_id', $contractId)->get()->keyBy('aset_id');
        $removed = ServiceContractLine::query()
            ->where('kontrak_servis_id', $contractId)
            ->whereNotIn('aset_id', $asetIds === [] ? [''] : $asetIds)
            ->whereIn('aset_id', $visible)
            ->pluck('id')->all();
        if ($removed !== []) {
            ServiceContractLine::query()->whereIn('id', $removed)->update(['deleted_at' => now(), 'updated_at' => now()]);
        }

        $next = (int) ServiceContractLine::withTrashed()->where('kontrak_servis_id', $contractId)->max('line_number');
        foreach ($asetIds as $asetId) {
            if ($existing->has($asetId)) {
                continue;
            }
            ServiceContractLine::query()->create([
                'tenant_id' => $this->tenant($request),
                'kontrak_servis_id' => $contractId,
                'line_number' => ++$next,
                'aset_id' => $asetId,
            ]);
        }
    }

    /** @param  array<string, string>  $headers */
    private function document(Request $request, string $id, int $status = 200, array $headers = []): JsonResponse
    {
        $contract = $this->find($request, $id);
        $data = $this->present($this->tenant($request), $contract, $this->today());
        $query = ServiceContractLine::query()
            ->join('aset_tr_aset', function (JoinClause $join): void {
                $join->on('aset_tr_aset.id', '=', self::LINES.'.aset_id')->on('aset_tr_aset.tenant_id', '=', self::LINES.'.tenant_id');
            })
            ->where(self::LINES.'.kontrak_servis_id', $id);
        app(OrganizationScope::class)->asetQuery($query, $request);
        $data['aset'] = $query->orderBy(self::LINES.'.line_number')->toBase()
            ->get([self::LINES.'.line_number', self::LINES.'.aset_id', 'aset_tr_aset.kode as aset_kode', 'aset_tr_aset.nama as aset_nama']);
        $data['lines_count'] = ServiceContractLine::query()->where('kontrak_servis_id', $id)->count();

        return response()->json(['data' => $data], $status, [...$headers, 'ETag' => RowVersion::etag($contract->version)]);
    }

    /** @return array<string, mixed> */
    private function present(string $tenant, ServiceContract $contract, string $today): array
    {
        $vendor = app(VendorDirectory::class)->find($tenant, $contract->vendor_id);
        $end = $contract->berlaku_sampai->toDateString();

        return [
            ...$contract->only(['id', 'kode', 'legal_entity_id', 'nomor_kontrak', 'vendor_id', 'cakupan', 'nilai_kontrak', 'keterangan', 'version']),
            'berlaku_mulai' => $contract->berlaku_mulai->toDateString(),
            'berlaku_sampai' => $end,
            'lines_count' => $contract->getAttribute('lines_count'),
            'vendor' => $vendor === null ? null : ['id' => $vendor['id'], 'number' => $vendor['number'], 'name' => $vendor['name'], 'status' => $vendor['status']],
            'berlaku' => $contract->berlaku_mulai->toDateString() <= $today && $end >= $today,
            'sisa_hari' => $end < $today ? 0 : (int) Carbon::parse($today)->diffInDays(Carbon::parse($end)),
        ];
    }

    private function find(Request $request, string $id): ServiceContract
    {
        $contract = $this->scoped(ServiceContract::query(), $request)->whereKey($id)->first();
        abort_if($contract === null, 404);

        return $contract;
    }

    /**
     * @param  Builder<ServiceContract>  $query
     * @return Builder<ServiceContract>
     */
    private function scoped(Builder $query, Request $request): Builder
    {
        app(OrganizationScope::class)->legalEntityQuery($query, $request, self::TABLE.'.legal_entity_id');

        return $query;
    }

    private function today(): string
    {
        return Carbon::now(app(RequestContext::class)->timezone())->toDateString();
    }

    private function creationKey(Request $request): string
    {
        return (string) validator(
            ['key' => $request->header('Idempotency-Key')],
            ['key' => ['required', 'string', 'max:'.self::MAX_CREATION_KEY, 'regex:/^[A-Za-z0-9._:-]+$/']],
        )->validate()['key'];
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
