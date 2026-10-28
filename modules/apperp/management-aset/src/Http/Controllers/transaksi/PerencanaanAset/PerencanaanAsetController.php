<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\transaksi\PerencanaanAset;

use App\Platform\Modules\Contracts\FundingSourceDirectory;
use App\Platform\Modules\Contracts\IncrementalList;
use App\Platform\Modules\Contracts\NumberSequenceIssuer;
use App\Platform\Modules\Contracts\OrganizationDirectory;
use App\Platform\Modules\Contracts\PlanningYearDirectory;
use App\Platform\Modules\Contracts\RowVersion;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Apperp\ManagementAset\Http\Controllers\Controller;
use Modules\Apperp\ManagementAset\Models\master\JenisAset;
use Modules\Apperp\ManagementAset\Models\transaksi\PerencanaanAset\PerencanaanAset;
use Modules\Apperp\ManagementAset\Models\transaksi\PerencanaanAset\PerencanaanAsetDetail;
use Modules\Apperp\ManagementAset\Models\transaksi\PermintaanPengadaanAset\PermintaanPengadaanAsetDetail;
use Modules\Apperp\ManagementAset\Services\AssetNumberSequenceIssuer;
use Modules\Apperp\ManagementAset\Services\AssetUnitOfMeasureDirectory;
use Modules\Apperp\ManagementAset\Services\NumberSequenceException;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;
use RuntimeException;
use stdClass;

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
        $query->select('aset_tr_perencanaan_aset.*')->selectSub(
            DB::table(self::TABEL_BARIS.' as estimates')->selectRaw('COUNT(*)')
                ->whereColumn('estimates.tenant_id', 'aset_tr_perencanaan_aset.tenant_id')
                ->whereColumn('estimates.planning_id', 'aset_tr_perencanaan_aset.id')
                ->whereNull('estimates.deleted_at')->where('estimates.estimate_pending', true)->where('estimates.estimated_unit_price', 0),
            'unestimated_line_count',
        );

        return response()->json(IncrementalList::query($request, $query->toBase(), [
            'id' => 'id', 'kode' => 'kode', 'date' => 'planned_on', 'status' => 'status', 'planning_year' => 'planning_year',
        ], ['kode', 'description'], 'date', [], 'desc'));
    }

    public function numberPolicy(Request $request, NumberSequenceIssuer $numbers): JsonResponse
    {
        $this->guard($request, 'create');
        $tenantId = $this->tenant($request);
        $reference = 'management-aset.perencanaan-aset';

        return response()->json(['data' => [
            'allow_manual' => $numbers->allowsManual($tenantId, 'management-aset', $reference),
            'allow_automatic' => $numbers->allowsAutomatic($tenantId, 'management-aset', $reference),
        ]]);
    }

    public function planningYears(Request $request): JsonResponse
    {
        $this->guardPlanningChoices($request);
        $data = $request->validate(['legal_entity_id' => ['required', 'ulid']]);
        app(PlanningOrganizationScope::class)->require($request, $data['legal_entity_id']);

        return response()->json(app(PlanningYearDirectory::class)->lookup($this->tenant($request), $data['legal_entity_id'], $request->query()));
    }

    public function organizations(Request $request): JsonResponse
    {
        $this->guardPlanningChoices($request);
        $data = $request->validate([
            'kind' => ['required', 'in:legal_entities,operating_units'],
            'legal_entity_id' => ['required_if:kind,operating_units', 'nullable', 'ulid'],
            'record_ids' => ['sometimes', 'array', 'max:100'],
            'record_ids.*' => ['required', 'string'],
            'record_id' => ['nullable', 'string'],
        ]);

        return response()->json(app(PlanningOrganizationScope::class)->choices($request, $data['kind'], $data['legal_entity_id'] ?? null));
    }

    private function guardPlanningChoices(Request $request): void
    {
        $held = $request->attributes->get('coreerp.permissions', []);
        abort_unless(array_intersect($held, array_map(fn ($action) => 'management-aset.'.self::RESOURCE.'.'.$action, ['read', 'create', 'update'])) !== [], 403);
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
        $plan->details->each(function (stdClass $line): void {
            if ($line->estimate_pending && BigDecimal::of((string) $line->estimated_unit_price)->isZero()) {
                $line->estimated_unit_price = null;
                $line->estimated_total_price = null;
            }
        });

        $plan->unestimated_line_count = $plan->details->filter(fn (stdClass $line) => $line->estimated_unit_price === null)->count();

        return response()->json(['data' => $plan], 200, ['ETag' => RowVersion::etag((int) $plan->version)]);
    }

    public function store(Request $request, AssetNumberSequenceIssuer $numbers, AssetUnitOfMeasureDirectory $units): JsonResponse
    {
        $this->guard($request, 'create');
        $key = $this->creationKey($request);
        $tenant = $this->tenant($request);
        if ($existing = $this->replay($request, $key)) {
            return response()->json(['data' => $existing], 200, ['Idempotent-Replayed' => 'true']);
        }

        $data = $this->validated($request);
        app(PlanningOrganizationScope::class)->require($request, $data['legal_entity_id'], $data['planning_org_unit_id']);
        $this->validateFundingSources($tenant, $data['details']);
        $unitMap = $this->validateLookupMasters($tenant, $data['details'], $units);
        try {
            DB::transaction(function () use ($request, $data, $key, $tenant, $numbers, $unitMap): void {
                $code = $numbers->issue('management-aset.perencanaan-aset', $tenant, 'perencanaan-aset:'.$key, $data['legal_entity_id'], manualValue: filled($data['kode'] ?? null) ? trim($data['kode']) : null);
                $record = $this->header($request, $data, $key, $code);
                (new PerencanaanAset)->forceFill($record)->save();
                $this->replaceDetails($record['id'], $data['details'], $unitMap);
            });
        } catch (NumberSequenceException $exception) {
            return response()->json(['error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()]], NumberSequenceException::HTTP_STATUS);
        } catch (QueryException $exception) {
            $existing = $this->replay($request, $key);
            if (! $existing) {
                if ($exception->getCode() === '23505') {
                    throw ValidationException::withMessages(['kode' => 'Kode perencanaan sudah digunakan.']);
                }
                throw $exception;
            }

            return response()->json(['data' => $existing], 200, ['Idempotent-Replayed' => 'true']);
        }

        // Dibaca ulang supaya `version` yang dipulangkan adalah nilai di database.
        return response()->json(['data' => $this->replay($request, $key)], 201);
    }

    public function update(Request $request, string $id, AssetUnitOfMeasureDirectory $units): JsonResponse
    {
        $this->guard($request, 'update');
        $plan = $this->plan($request, $id);
        abort_unless($plan->status === 'draft', 422, 'Hanya rencana draf yang dapat diubah.');
        $data = $this->validated($request, $id);
        if ($data['legal_entity_id'] !== $plan->legal_entity_id) {
            throw ValidationException::withMessages(['legal_entity_id' => 'Perusahaan dokumen tersimpan tidak dapat dipindahkan.']);
        }
        app(OrganizationScope::class)->require($request, $plan->legal_entity_id, $plan->planning_org_unit_id);
        app(OrganizationScope::class)->require($request, $data['legal_entity_id'], $data['planning_org_unit_id']);
        $this->validateFundingSources($this->tenant($request), $data['details'], $id);
        $unitMap = $this->validateLookupMasters($this->tenant($request), $data['details'], $units);
        $version = RowVersion::expected($request);

        DB::transaction(function () use ($request, $id, $version, $data, $unitMap): void {
            RowVersion::claim(PerencanaanAset::query()->whereKey($id), $version);
            $this->assertNotRequested($id);
            $changes = $this->header($request, $data, '', '', false);
            PerencanaanAset::query()->whereKey($id)->update([
                ...$changes,
                'updated_at' => now(),
            ]);
            $this->replaceDetails($id, $data['details'], $unitMap);
        });

        return $this->show($request, $id);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->guard($request, 'archive');
        $plan = $this->plan($request, $id);
        abort_unless($plan->status === 'draft', 422, 'Hanya rencana draf yang dapat diarsipkan.');
        $version = RowVersion::expected($request);
        app(OrganizationScope::class)->require($request, $plan->legal_entity_id, $plan->planning_org_unit_id);
        DB::transaction(function () use ($id, $version): void {
            RowVersion::claim(PerencanaanAset::query()->whereKey($id), $version);
            $this->assertNotRequested($id);
            PerencanaanAset::query()->whereKey($id)->update(['deleted_at' => now(), 'updated_at' => now()]);
        });

        return response()->json(status: 204);
    }

    private function assertNotRequested(string $id): void
    {
        abort_if(PermintaanPengadaanAsetDetail::query()
            ->whereIn('planning_detail_id', PerencanaanAsetDetail::query()->where('planning_id', $id)->select('id'))
            ->exists(), 422, 'Perencanaan sudah menjadi dasar permintaan dan tidak dapat diubah atau diarsipkan.');
    }

    /**
     * Rencana yang pernah dibuat dengan kunci yang sama.
     *
     * `withTrashed` karena replay idempoten harus tetap menemukan rencana yang sudah
     * diarsipkan; tanpa itu permintaan ulang mencoba menyisipkan baris kembar.
     */
    private function replay(Request $request, string $key): ?stdClass
    {
        $record = PerencanaanAset::withTrashed()->where('creation_key', $key)->toBase()->first();
        if ($record !== null) {
            app(PlanningOrganizationScope::class)->require($request, $record->legal_entity_id, $record->planning_org_unit_id);
        }

        return $record;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?string $planId = null): array
    {
        $data = $request->validate([
            'kode' => ['nullable', 'string', 'max:255'],
            'legal_entity_id' => ['required', 'ulid'],
            'planning_org_unit_id' => ['required', 'ulid'],
            'planned_on' => ['required', 'date'],
            'planning_year_id' => ['required', 'ulid'],
            'responsible_user_id' => ['required', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:2000'],
            'details' => ['required', 'array', 'min:1'],
            'details.*.id' => ['nullable', 'ulid', 'distinct'],
            'details.*.needed_on' => ['required', 'date_format:Y-m-d'],
            'details.*.funding_source_id' => ['required', 'ulid'],
            'details.*.jenis_aset_id' => ['required', 'ulid'],
            'details.*.satuan_id' => ['required', 'ulid'],
            'details.*.quantity' => ['required', 'numeric', 'gt:0'],
            'details.*.requested_specification' => ['required', 'string', 'max:2000'],
            'details.*.estimated_unit_price' => ['nullable', 'numeric', 'min:0'],
        ]);
        $year = app(PlanningYearDirectory::class)->resolve($this->tenant($request), $data['legal_entity_id'], [$data['planning_year_id']])[$data['planning_year_id']] ?? null;
        $currentYearId = $planId === null ? null : PerencanaanAset::query()->whereKey($planId)->value('planning_year_id');
        if ($year === null || (! $year['active'] && $currentYearId !== $year['id'])) {
            throw ValidationException::withMessages(['planning_year_id' => 'Pilih tahun rencana yang aktif untuk perusahaan ini.']);
        }
        $data['planning_year'] = (int) $year['year'];
        foreach ($data['details'] as $index => $detail) {
            if ((int) Carbon::parse($detail['needed_on'])->year !== (int) $data['planning_year']) {
                throw ValidationException::withMessages(['details.'.$index.'.needed_on' => 'Tanggal kebutuhan harus berada dalam tahun rencana yang dipilih.']);
            }
        }
        $member = app(OrganizationDirectory::class)->lookup($this->tenant($request), 'members', [
            'record_ids' => [$data['responsible_user_id']], 'per_page' => 1,
        ]);
        if (! collect($member['data'])->contains(fn ($row) => (string) $row->id === $data['responsible_user_id'])) {
            throw ValidationException::withMessages(['responsible_user_id' => 'Pilih penanggung jawab dari anggota yang masih aktif.']);
        }

        return $data;
    }

    /**
     * @param  list<array<string, mixed>>  $details
     * @return array<string, array{id: string, code: string, name: string, symbol: ?string, decimal_places: int}>
     */
    private function validateLookupMasters(string $tenant, array $details, AssetUnitOfMeasureDirectory $units): array
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

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function header(Request $request, array $data, string $key, string $kode, bool $new = true): array
    {
        return array_filter([
            'id' => $new ? (string) Str::ulid() : null,
            'tenant_id' => $new ? $this->tenant($request) : null,
            'creation_key' => $new ? $key : null,
            'kode' => $new ? $kode : null,
            'legal_entity_id' => $data['legal_entity_id'], 'planning_org_unit_id' => $data['planning_org_unit_id'],
            'planned_on' => $data['planned_on'], 'planning_year' => $data['planning_year'],
            'planning_year_id' => $data['planning_year_id'],
            'responsible_user_id' => $data['responsible_user_id'],
            'unestimated_line_count' => count(array_filter($data['details'], static fn ($detail) => ($detail['estimated_unit_price'] ?? null) === null)),
            'total_estimated_value' => $this->totalEstimasi($data['details']), 'description' => $data['description'] ?? null,
            'status' => $new ? 'draft' : null,
            'created_at' => $new ? now() : null, 'updated_at' => now(),
        ], static fn ($value) => $value !== null);
    }

    /**
     * Nilai rencana seluruh baris.
     *
     * Berdiri sendiri karena `header()` menerima hasil `validate()` yang nilainya tidak
     * bertipe; barisnya baru dapat dinyatakan bentuknya di sini.
     *
     * @param  list<array<string, mixed>>  $details
     */
    private function totalEstimasi(array $details): string
    {
        $total = BigDecimal::zero();
        foreach ($details as $detail) {
            $total = $total->plus($this->detailTotal($detail) ?? '0');
        }

        return (string) $total->toScale(2);
    }

    /**
     * @param  list<array<string, mixed>>  $details
     * @param  array<string, array{id: string, code: string, name: string, symbol: ?string, decimal_places: int}>  $units
     */
    private function replaceDetails(string $planId, array $details, array $units): void
    {
        $types = JenisAset::query()->whereIn('id', array_column($details, 'jenis_aset_id'))->pluck('nama', 'id');
        $existing = PerencanaanAsetDetail::query()->where('planning_id', $planId)->get()->keyBy('id');
        $nextNumber = (int) PerencanaanAsetDetail::withTrashed()->where('planning_id', $planId)->max('line_number');
        $tenantId = (string) PerencanaanAset::query()->whereKey($planId)->value('tenant_id');
        $sources = app(FundingSourceDirectory::class)->resolve($tenantId, array_values(array_unique(array_column($details, 'funding_source_id'))));
        $retained = [];
        foreach ($details as $index => $detail) {
            $line = isset($detail['id']) ? $existing->get($detail['id']) : null;
            if (isset($detail['id']) && $line === null) {
                throw ValidationException::withMessages(['details.'.$index.'.id' => 'Baris rencana tidak ditemukan pada dokumen ini.']);
            }
            $source = $sources[$detail['funding_source_id']] ?? null;
            if ($source === null || (! $source['active'] && $line?->funding_source_id !== $detail['funding_source_id'])) {
                throw ValidationException::withMessages(['details.'.$index.'.funding_source_id' => 'Pilih sumber dana yang masih aktif.']);
            }
            $line ??= new PerencanaanAsetDetail;
            $fundingName = $line->funding_source_id === $detail['funding_source_id'] && filled($line->funding_source_name)
                ? $line->funding_source_name : $source['name'];
            $line->fill([
                'planning_id' => $planId, 'line_number' => $line->exists ? $line->line_number : ++$nextNumber,
                'jenis_aset_id' => $detail['jenis_aset_id'], 'satuan_id' => $detail['satuan_id'],
                'nama_aset' => $types[$detail['jenis_aset_id']],
                // `unit` snapshot kode satuan untuk tampilan; `satuan_id` di atas yang menunjuk
                // satuan milik Core. Keduanya tidak saling menggantikan.
                'unit' => $units[$detail['satuan_id']]['name'],
                'needed_on' => $detail['needed_on'], 'funding_source_id' => $detail['funding_source_id'],
                'funding_source_name' => $fundingName,
                'quantity' => $detail['quantity'], 'requested_specification' => $detail['requested_specification'],
                'estimated_unit_price' => $detail['estimated_unit_price'] ?? 0,
                'estimated_total_price' => $this->detailTotal($detail) ?? '0.00',
                'estimate_pending' => ($detail['estimated_unit_price'] ?? null) === null,
            ]);
            $line->save();
            $retained[] = $line->id;
        }
        PerencanaanAsetDetail::query()->where('planning_id', $planId)->whereNotIn('id', $retained)->delete();
    }

    /** @param array<string, mixed> $detail */
    private function detailTotal(array $detail): ?string
    {
        return ($detail['estimated_unit_price'] ?? null) === null ? null
            : (string) BigDecimal::of((string) $detail['quantity'])->multipliedBy((string) $detail['estimated_unit_price'])->toScale(2, RoundingMode::HalfUp);
    }

    /** @param list<array<string, mixed>> $details */
    private function validateFundingSources(string $tenantId, array $details, ?string $planId = null): void
    {
        $sources = app(FundingSourceDirectory::class)->resolve($tenantId, array_values(array_unique(array_column($details, 'funding_source_id'))));
        $existing = $planId === null ? collect() : PerencanaanAsetDetail::query()->where('planning_id', $planId)->get()->keyBy('id');
        foreach ($details as $index => $detail) {
            $line = isset($detail['id']) ? $existing->get($detail['id']) : null;
            $source = $sources[$detail['funding_source_id']] ?? null;
            if (isset($detail['id']) && $line === null) {
                throw ValidationException::withMessages(['details.'.$index.'.id' => 'Baris rencana tidak ditemukan pada dokumen ini.']);
            }
            if ($source === null || (! $source['active'] && $line?->funding_source_id !== $detail['funding_source_id'])) {
                throw ValidationException::withMessages(['details.'.$index.'.funding_source_id' => 'Pilih sumber dana yang masih aktif.']);
            }
        }
    }

    private function creationKey(Request $request): string
    {
        return (string) validator(['key' => $request->header('Idempotency-Key')], ['key' => ['required', 'string', 'max:154', 'regex:/^[A-Za-z0-9._:-]+$/']])->validate()['key'];
    }

    /** Baris mentah hasil `toBase()`: sebuah `stdClass`, bukan model. */
    private function plan(Request $request, string $id): stdClass
    {
        $query = PerencanaanAset::query()->where('id', $id);
        app(OrganizationScope::class)->query($query, $request, 'legal_entity_id', 'planning_org_unit_id');
        $query->select('aset_tr_perencanaan_aset.*');
        app(OrganizationDirectory::class)->addNames($query->getQuery(), $this->tenant($request), [
            'legal_entity_name' => 'aset_tr_perencanaan_aset.legal_entity_id',
            'planning_org_unit_name' => 'aset_tr_perencanaan_aset.planning_org_unit_id',
        ], ['responsible_user_name' => 'aset_tr_perencanaan_aset.responsible_user_id']);

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
