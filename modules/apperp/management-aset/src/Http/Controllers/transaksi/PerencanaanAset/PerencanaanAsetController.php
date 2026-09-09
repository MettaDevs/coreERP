<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\transaksi\PerencanaanAset;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Apperp\ManagementAset\Http\Controllers\Controller;
use Modules\Apperp\ManagementAset\Models\master\JenisAset;
use Modules\Apperp\ManagementAset\Models\transaksi\PerencanaanAset\PerencanaanAset;
use Modules\Apperp\ManagementAset\Models\transaksi\PerencanaanAset\PerencanaanAsetDetail;
use Modules\Apperp\ManagementAset\Services\DaftarSatuanAset;
use Modules\Apperp\ManagementAset\Services\NumberSequenceException;
use Modules\Apperp\ManagementAset\Services\PenerbitNomorAset;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;
use RuntimeException;

/**
 * Rencana pengadaan aset satu tahun anggaran.
 *
 * Penyaringan tenant datang dari scope model; yang masih menyebut `tenant_id` hanyalah
 * tabel yang di-`join`, yang memang tidak ikut tersaring.
 */
class PerencanaanAsetController extends Controller
{
    private const RESOURCE = 'perencanaan-aset';

    private const TABEL_BARIS = 'aset_tr_perencanaan_aset_details';

    public function index(Request $request): JsonResponse
    {
        $this->guard($request, 'read');

        $query = PerencanaanAset::query();
        app(OrganizationScope::class)->query($query, $request, 'legal_entity_id', 'planning_org_unit_id');

        return response()->json(['data' => $query
            ->toBase()
            ->latest('planned_on')->latest('created_at')
            ->get()]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->guard($request, 'read');
        $plan = $this->plan($request, $id);
        $plan->details = PerencanaanAsetDetail::query()
            ->leftJoin('aset_m_jenis_aset as jenis', function ($join): void {
                $join->on('jenis.id', '=', self::TABEL_BARIS.'.jenis_aset_id')->on('jenis.tenant_id', '=', self::TABEL_BARIS.'.tenant_id');
            })
            ->where(self::TABEL_BARIS.'.planning_id', $id)
            ->orderBy(self::TABEL_BARIS.'.line_number')
            ->toBase()
            ->get([
                self::TABEL_BARIS.'.*', 'jenis.kode as jenis_aset_kode', 'jenis.nama as jenis_aset_nama',
            ]);

        return response()->json(['data' => $plan]);
    }

    public function store(Request $request, PenerbitNomorAset $numbers, DaftarSatuanAset $units): JsonResponse
    {
        $this->guard($request, 'create');
        $key = $this->creationKey($request);
        $tenant = $this->tenant($request);
        if ($existing = $this->replay($key)) {
            return response()->json(['data' => $existing], 200, ['Idempotent-Replayed' => 'true']);
        }

        $data = $this->validated($request);
        app(OrganizationScope::class)->require($request, $data['legal_entity_id'], $data['planning_org_unit_id']);
        $unitMap = $this->validateLookupMasters($tenant, $data['details'], $units);
        try {
            $kode = $numbers->issue('management-aset.perencanaan-aset', $tenant, 'perencanaan-aset:'.$key, $data['legal_entity_id']);
        } catch (NumberSequenceException $exception) {
            return response()->json(['error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()]], NumberSequenceException::HTTP_STATUS);
        }

        try {
            $plan = DB::transaction(function () use ($request, $data, $key, $kode, $unitMap): array {
                $record = $this->header($request, $data, $key, $kode);
                (new PerencanaanAset)->forceFill($record)->save();
                $this->replaceDetails($record['id'], $data['details'], $unitMap);

                return $record;
            });
        } catch (QueryException $exception) {
            $existing = $this->replay($key);
            if (! $existing) {
                throw $exception;
            }

            return response()->json(['data' => $existing], 200, ['Idempotent-Replayed' => 'true']);
        }

        return response()->json(['data' => $plan], 201);
    }

    public function update(Request $request, string $id, DaftarSatuanAset $units): JsonResponse
    {
        $this->guard($request, 'update');
        $plan = $this->plan($request, $id);
        abort_unless($plan->status === 'draft', 422, 'Hanya rencana draf yang dapat diubah.');
        $data = $this->validated($request);
        app(OrganizationScope::class)->require($request, $plan->legal_entity_id, $plan->planning_org_unit_id);
        app(OrganizationScope::class)->require($request, $data['legal_entity_id'], $data['planning_org_unit_id']);
        $unitMap = $this->validateLookupMasters($this->tenant($request), $data['details'], $units);
        $version = (int) $request->validate(['version' => ['required', 'integer', 'min:1']])['version'];

        $changed = DB::transaction(function () use ($request, $id, $version, $data, $unitMap): int {
            $changes = $this->header($request, $data, '', '', false);
            unset($changes['responsible_user_id']);
            $updated = PerencanaanAset::query()->where([
                'id' => $id, 'version' => $version,
            ])->update([
                ...$changes,
                'version' => $version + 1,
                'updated_at' => now(),
            ]);
            if ($updated) {
                PerencanaanAsetDetail::query()->where('planning_id', $id)->delete();
                $this->replaceDetails($id, $data['details'], $unitMap);
            }

            return $updated;
        });
        if (! $changed) {
            return response()->json(['error' => ['code' => 'stale_version', 'message' => 'Rencana telah berubah. Muat ulang lalu coba lagi.']], 409);
        }

        return $this->show($request, $id);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->guard($request, 'archive');
        $plan = $this->plan($request, $id);
        abort_unless($plan->status === 'draft', 422, 'Hanya rencana draf yang dapat diarsipkan.');
        $version = (int) $request->validate(['version' => ['required', 'integer', 'min:1']])['version'];
        app(OrganizationScope::class)->require($request, $plan->legal_entity_id, $plan->planning_org_unit_id);
        $updated = PerencanaanAset::query()->where([
            'id' => $id, 'version' => $version,
        ])->update(['deleted_at' => now(), 'version' => $version + 1, 'updated_at' => now()]);
        if (! $updated) {
            return response()->json(['error' => ['code' => 'stale_version', 'message' => 'Rencana telah berubah. Muat ulang lalu coba lagi.']], 409);
        }

        return response()->json(status: 204);
    }

    /**
     * Rencana yang pernah dibuat dengan kunci yang sama.
     *
     * `withTrashed` karena replay idempoten harus tetap menemukan rencana yang sudah
     * diarsipkan; tanpa itu permintaan ulang mencoba menyisipkan baris kembar.
     */
    private function replay(string $key): ?object
    {
        return PerencanaanAset::withTrashed()->where('creation_key', $key)->toBase()->first();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'legal_entity_id' => ['required', 'ulid'],
            'planning_org_unit_id' => ['required', 'ulid'],
            'planned_on' => ['required', 'date'],
            'planning_year' => ['required', 'integer', 'between:2000,2100'],
            'planning_type' => ['required', Rule::in(['regular', 'additional'])],
            'funding_source' => ['nullable', 'string', 'max:250'],
            'description' => ['nullable', 'string', 'max:2000'],
            'details' => ['required', 'array', 'min:1'],
            'details.*.jenis_aset_id' => ['required', 'ulid'],
            'details.*.satuan_id' => ['required', 'ulid'],
            'details.*.quantity' => ['required', 'numeric', 'gt:0'],
            'details.*.requested_specification' => ['required', 'string', 'max:2000'],
            'details.*.estimated_unit_price' => ['nullable', 'numeric', 'min:0'],
        ]);
        if ((int) Carbon::parse($data['planned_on'])->year !== (int) $data['planning_year']) {
            throw ValidationException::withMessages(['planning_year' => 'Tahun perencanaan harus sama dengan tahun tanggal rencana.']);
        }

        return $data;
    }

    /** @param list<array<string, mixed>> $details */
    private function validateLookupMasters(string $tenant, array $details, DaftarSatuanAset $units): array
    {
        $ids = array_values(array_unique(array_column($details, 'jenis_aset_id')));
        $count = JenisAset::query()->whereIn('id', $ids)->where('aktif', true)->count();
        if ($count !== count($ids)) {
            throw ValidationException::withMessages(['details' => 'Jenis aset tidak ditemukan atau sudah tidak aktif.']);
        }
        try {
            return $units->resolve($tenant, array_values(array_unique(array_column($details, 'satuan_id'))));
        } catch (RuntimeException) {
            throw ValidationException::withMessages(['details' => 'Satuan tidak ditemukan, tidak aktif, atau belum dapat diperiksa.']);
        }
    }

    /** @return array<string, mixed> */
    private function header(Request $request, array $data, string $key, string $kode, bool $new = true): array
    {
        $total = collect($data['details'])->sum(fn (array $detail): float => (float) $detail['quantity'] * (float) ($detail['estimated_unit_price'] ?? 0));

        return array_filter([
            'id' => $new ? (string) Str::ulid() : null,
            'tenant_id' => $new ? $this->tenant($request) : null,
            'creation_key' => $new ? $key : null,
            'kode' => $new ? $kode : null,
            'legal_entity_id' => $data['legal_entity_id'], 'planning_org_unit_id' => $data['planning_org_unit_id'],
            'planned_on' => $data['planned_on'], 'planning_year' => $data['planning_year'], 'planning_type' => $data['planning_type'],
            'funding_source' => $data['funding_source'] ?? null, 'responsible_user_id' => (string) $request->attributes->get('coreerp.user_id'),
            'total_estimated_value' => $total, 'description' => $data['description'] ?? null,
            'status' => $new ? 'draft' : null, 'version' => $new ? 1 : null,
            'created_at' => $new ? now() : null, 'updated_at' => now(),
        ], static fn ($value) => $value !== null);
    }

    /** @param list<array<string, mixed>> $details */
    private function replaceDetails(string $planId, array $details, array $units): void
    {
        $types = JenisAset::query()->whereIn('id', array_column($details, 'jenis_aset_id'))->pluck('nama', 'id');
        collect($details)->values()->each(fn (array $detail, int $index) => PerencanaanAsetDetail::create([
            'planning_id' => $planId, 'line_number' => $index + 1,
            'jenis_aset_id' => $detail['jenis_aset_id'], 'satuan_id' => $detail['satuan_id'],
            'asset_name' => $types[$detail['jenis_aset_id']],
            // `unit` snapshot kode satuan untuk tampilan; `satuan_id` di atas yang menunjuk
            // satuan milik Core. Keduanya tidak saling menggantikan.
            'unit' => $units[$detail['satuan_id']]['name'],
            'quantity' => $detail['quantity'], 'requested_specification' => $detail['requested_specification'],
            'estimated_unit_price' => $detail['estimated_unit_price'] ?? 0,
            'estimated_total_price' => (float) $detail['quantity'] * (float) ($detail['estimated_unit_price'] ?? 0),
        ]));
    }

    private function creationKey(Request $request): string
    {
        return (string) validator(['key' => $request->header('Idempotency-Key')], ['key' => ['required', 'string', 'max:154', 'regex:/^[A-Za-z0-9._:-]+$/']])->validate()['key'];
    }

    private function plan(Request $request, string $id): object
    {
        $query = PerencanaanAset::query()->where('id', $id);
        app(OrganizationScope::class)->query($query, $request, 'legal_entity_id', 'planning_org_unit_id');

        return $query->toBase()->firstOrFail();
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
