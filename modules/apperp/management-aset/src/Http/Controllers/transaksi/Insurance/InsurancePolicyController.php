<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\transaksi\Insurance;

use App\Platform\Modules\Contracts\RequestContext;
use App\Platform\Modules\Contracts\RowVersion;
use App\Platform\Modules\Contracts\VendorDirectory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Apperp\ManagementAset\Http\Controllers\Controller;
use Modules\Apperp\ManagementAset\Models\transaksi\Insurance\InsuranceCoverage;
use Modules\Apperp\ManagementAset\Models\transaksi\Insurance\InsurancePolicy;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;
use Modules\Apperp\ManagementAset\Services\AssetNumberSequenceIssuer;
use Modules\Apperp\ManagementAset\Services\InsuredValues;
use Modules\Apperp\ManagementAset\Services\NumberSequenceException;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;
use Modules\Apperp\ManagementAset\Support\StatusAset;
use stdClass;

/**
 * Polis asuransi dan pertanggungan asetnya; padanan *Insurance Card*, *Insurance Journal*, dan
 * *Ins. Coverage Ledger Entries* Business Central.
 *
 * Polis milik satu entitas legal tanpa unit kerja, jadi terlihat oleh pengguna yang punya hibah pada
 * entitas legal itu ({@see OrganizationScope::legalEntityQuery()}). Pertanggungan menempel pada aset dan
 * mengikuti jangkauan asetnya: aset di luar unit pengguna tidak dapat ditambahkan.
 *
 * **Pertanggungan adalah riwayat.** Satu baris adalah nilai pertanggungan satu aset untuk satu periode.
 * Nilai yang berubah tidak menimpa baris lama: baris lama diakhiri sehari sebelum nilai baru berlaku
 * dan baris baru dibuat ({@see self::replaceCoverage()}), sama seperti BC mencatat perubahan nilai
 * sebagai entri ledger baru. Pertanggungan yang salah dicatat diarsipkan.
 *
 * Tidak ada yang diposting ke buku besar, seperti di BC: premi dibayar lewat tagihan vendor biasa.
 */
class InsurancePolicyController extends Controller
{
    private const RESOURCE = 'polis-asuransi';

    private const TABLE = 'aset_m_polis_asuransi';

    private const COVERAGE = 'aset_tr_pertanggungan_asuransi';

    /** 160 batas Core dikurangi awalan `polis-asuransi:` dan cadangan. */
    private const MAX_CREATION_KEY = 140;

    public function index(Request $request, InsuredValues $insured): JsonResponse
    {
        $this->guard($request, 'read');
        $filter = $request->validate([
            'legal_entity_id' => ['nullable', 'ulid'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $query = $this->scoped(InsurancePolicy::query(), $request);
        if ($filter['legal_entity_id'] ?? null) {
            $query->where(self::TABLE.'.legal_entity_id', $filter['legal_entity_id']);
        }
        if (($filter['q'] ?? '') !== '') {
            $search = '%'.mb_strtolower((string) $filter['q']).'%';
            $query->where(fn ($query) => $query
                ->whereRaw('lower('.self::TABLE.'.kode) like ?', [$search])
                ->orWhereRaw('lower('.self::TABLE.'.nama) like ?', [$search])
                ->orWhereRaw('lower('.self::TABLE.'.nomor_polis) like ?', [$search]));
        }

        $today = $this->today();
        $rows = $this->withLookups($query)->orderByDesc(self::TABLE.'.berlaku_mulai')->orderBy(self::TABLE.'.kode')->limit(500)->get();
        $totals = $insured->perPolicy(array_values($rows->pluck('id')->map(strval(...))->all()), $today);
        $tenant = $this->tenant($request);

        return response()->json(['data' => $rows->map(fn (stdClass $row): stdClass => $this->present($tenant, $row, $totals[(string) $row->id] ?? null, $today))]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->guard($request, 'read');

        return $this->document($request, $id);
    }

    /** Vendor aktif entitas legal polis untuk pemilih penanggung. Vendor milik Core (K-06). */
    public function vendor(Request $request): JsonResponse
    {
        $this->guard($request, 'read');
        $query = $request->validate([
            'legal_entity_id' => ['required', 'ulid'],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        return response()->json(['data' => app(VendorDirectory::class)->active($this->tenant($request), $query['legal_entity_id'], (string) ($query['q'] ?? ''))]);
    }

    public function store(Request $request, AssetNumberSequenceIssuer $numbers): JsonResponse
    {
        $this->guard($request, 'create');
        $key = $this->creationKey($request);
        $tenant = $this->tenant($request);
        if ($existing = InsurancePolicy::withTrashed()->where('creation_key', $key)->first()) {
            return response()->json(['data' => ['id' => $existing->id, 'kode' => $existing->kode]], 200, ['Idempotent-Replayed' => 'true']);
        }

        $data = $this->validated($request);
        app(OrganizationScope::class)->require($request, $data['legal_entity_id'], null);
        $this->validateVendor($tenant, $data);

        try {
            $kode = $numbers->issue('management-aset.'.self::RESOURCE, $tenant, self::RESOURCE.':'.$key, $data['legal_entity_id']);
        } catch (NumberSequenceException $exception) {
            return response()->json(['error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()]], NumberSequenceException::HTTP_STATUS);
        }

        $id = (string) Str::ulid();
        try {
            (new InsurancePolicy)->forceFill([
                'id' => $id,
                'tenant_id' => $tenant,
                'creation_key' => $key,
                'kode' => $kode,
                'legal_entity_id' => $data['legal_entity_id'],
                ...$this->values($data),
            ])->save();
        } catch (QueryException $exception) {
            $existing = InsurancePolicy::withTrashed()->where('creation_key', $key)->first();
            if ($existing === null) {
                throw $exception;
            }

            return response()->json(['data' => ['id' => $existing->id, 'kode' => $existing->kode]], 200, ['Idempotent-Replayed' => 'true']);
        }

        return $this->document($request, $id, 201, ['Location' => $request->url().'/'.$id]);
    }

    /**
     * Mengubah kartu polis. Entitas legal tidak dapat diganti: nomornya terbit untuk entitas legal itu
     * dan pertanggungannya menunjuk aset entitas legal itu. Masa berlaku boleh berubah selama seluruh
     * pertanggungan yang tercatat masih berada di dalamnya.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $this->guard($request, 'update');
        $policy = $this->find($request, $id);
        $data = $this->validated($request);
        $version = RowVersion::expected($request);
        if ($data['legal_entity_id'] !== $policy->legal_entity_id) {
            throw ValidationException::withMessages(['legal_entity_id' => 'Entitas legal polis tidak dapat diganti; buat polis baru untuk entitas legal lain.']);
        }
        $this->validateVendor($this->tenant($request), $data);

        DB::transaction(function () use ($id, $data, $version): void {
            RowVersion::claim(InsurancePolicy::query()->whereKey($id), $version);
            $outside = InsuranceCoverage::query()->where('polis_asuransi_id', $id)
                ->where(fn ($query) => $query->where('berlaku_mulai', '<', $data['berlaku_mulai'])
                    ->when(($data['berlaku_sampai'] ?? null) !== null, fn ($query) => $query
                        ->orWhereNull('berlaku_sampai')
                        ->orWhere('berlaku_sampai', '>', $data['berlaku_sampai'])))
                ->exists();
            if ($outside) {
                throw ValidationException::withMessages(['berlaku_mulai' => 'Masa berlaku ini tidak lagi mencakup seluruh pertanggungan aset pada polis. Akhiri atau arsipkan pertanggungan yang berada di luarnya lebih dulu.']);
            }
            InsurancePolicy::query()->whereKey($id)->update([...$this->values($data), 'updated_at' => now()]);
        });

        return $this->document($request, $id);
    }

    /**
     * Mengarsipkan polis. Polis yang masih menanggung aset tidak dapat diarsipkan: pertanggungannya
     * diakhiri lebih dulu, supaya aset tidak diam-diam menjadi tidak diasuransikan.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->guard($request, 'archive');
        $this->find($request, $id);
        $version = RowVersion::expected($request);
        $today = $this->today();

        DB::transaction(function () use ($id, $version, $today): void {
            RowVersion::claim(InsurancePolicy::query()->whereKey($id), $version);
            $running = InsuranceCoverage::query()->where('polis_asuransi_id', $id)
                ->where(fn ($query) => $query->whereNull('berlaku_sampai')->orWhere('berlaku_sampai', '>=', $today))
                ->exists();
            abort_if($running, 422, 'Polis ini masih menanggung aset. Akhiri pertanggungannya lebih dulu.');
            InsurancePolicy::query()->whereKey($id)->update(['deleted_at' => now(), 'updated_at' => now()]);
        });

        return response()->json(status: 204);
    }

    /** Menambahkan pertanggungan satu aset ke polis; padanan satu baris *Insurance Journal* BC. */
    public function addCoverage(Request $request, string $id): JsonResponse
    {
        $this->guard($request, 'update');
        $key = $this->creationKey($request);
        if ($this->coverageRecorded($key)) {
            return $this->document($request, $id, 200, ['Idempotent-Replayed' => 'true']);
        }

        $policy = $this->find($request, $id);
        $data = $request->validate([
            'aset_id' => ['required', 'ulid'],
            'nilai_pertanggungan' => ['required', 'numeric', 'gt:0', 'max:9999999999999999'],
            'berlaku_mulai' => ['required', 'date_format:Y-m-d'],
            'berlaku_sampai' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:berlaku_mulai'],
            'keterangan' => ['nullable', 'string', 'max:2000'],
        ]);
        $version = RowVersion::expected($request);
        abort_if($policy->diblokir, 422, 'Polis ini diblokir, sehingga tidak dapat menanggung aset baru.');
        $this->requireCoverableAsset($request, $policy, $data['aset_id']);
        $this->requireWithinPolicy($policy, $data['berlaku_mulai'], $data['berlaku_sampai'] ?? null);

        try {
            DB::transaction(function () use ($request, $id, $data, $key, $version): void {
                RowVersion::claim(InsurancePolicy::query()->whereKey($id), $version);
                $this->requireNoOverlap($id, $data['aset_id'], $data['berlaku_mulai'], $data['berlaku_sampai'] ?? null);
                InsuranceCoverage::query()->create([
                    'tenant_id' => $this->tenant($request),
                    'creation_key' => $key,
                    'polis_asuransi_id' => $id,
                    'aset_id' => $data['aset_id'],
                    'nilai_pertanggungan' => $data['nilai_pertanggungan'],
                    'berlaku_mulai' => $data['berlaku_mulai'],
                    'berlaku_sampai' => $data['berlaku_sampai'] ?? null,
                    'keterangan' => $this->text($data['keterangan'] ?? null),
                ]);
            });
        } catch (QueryException $exception) {
            // Penambahan serentak dengan kunci yang sama: yang kalah melihat baris pemenangnya.
            if (! $this->coverageRecorded($key)) {
                throw $exception;
            }

            return $this->document($request, $id, 200, ['Idempotent-Replayed' => 'true']);
        }

        return $this->document($request, $id, 201);
    }

    /** Mengakhiri pertanggungan pada tanggal tertentu, misalnya karena aset dijual atau dipindah polis. */
    public function endCoverage(Request $request, string $id, string $coverageId): JsonResponse
    {
        $this->guard($request, 'update');
        $this->find($request, $id);
        $coverage = $this->coverage($request, $id, $coverageId);
        $date = $request->validate(['berlaku_sampai' => ['required', 'date_format:Y-m-d']])['berlaku_sampai'];
        $version = RowVersion::expected($request);
        if ($date < $coverage->berlaku_mulai->toDateString()) {
            throw ValidationException::withMessages(['berlaku_sampai' => 'Tanggal akhir tidak boleh sebelum pertanggungan mulai berlaku. Arsipkan pertanggungan bila ia memang salah dicatat.']);
        }
        if ($coverage->berlaku_sampai !== null && $date > $coverage->berlaku_sampai->toDateString()) {
            throw ValidationException::withMessages(['berlaku_sampai' => 'Pertanggungan ini sudah berakhir lebih awal. Tambahkan pertanggungan baru untuk memperpanjangnya.']);
        }

        DB::transaction(function () use ($id, $coverageId, $date, $version): void {
            RowVersion::claim(InsurancePolicy::query()->whereKey($id), $version);
            InsuranceCoverage::query()->whereKey($coverageId)->update(['berlaku_sampai' => $date, 'updated_at' => now()]);
        });

        return $this->document($request, $id);
    }

    /**
     * Mengganti nilai pertanggungan mulai satu tanggal: baris lama diakhiri sehari sebelumnya dan baris
     * baru dibuat dengan sisa periodenya. Padanan *Index Insurance* BC untuk satu aset, tanpa persentase.
     */
    public function replaceCoverage(Request $request, string $id, string $coverageId): JsonResponse
    {
        $this->guard($request, 'update');
        $policy = $this->find($request, $id);
        $coverage = $this->coverage($request, $id, $coverageId);
        $data = $request->validate([
            'nilai_pertanggungan' => ['required', 'numeric', 'gt:0', 'max:9999999999999999'],
            'berlaku_mulai' => ['required', 'date_format:Y-m-d'],
            'keterangan' => ['nullable', 'string', 'max:2000'],
        ]);
        $version = RowVersion::expected($request);
        abort_if($policy->diblokir, 422, 'Polis ini diblokir, sehingga nilai pertanggungannya tidak dapat diganti.');
        $start = $data['berlaku_mulai'];
        $end = $coverage->berlaku_sampai?->toDateString();
        if ($start <= $coverage->berlaku_mulai->toDateString() || ($end !== null && $start > $end)) {
            throw ValidationException::withMessages(['berlaku_mulai' => 'Nilai baru harus mulai berlaku sesudah pertanggungan ini mulai dan sebelum ia berakhir.']);
        }

        DB::transaction(function () use ($request, $id, $coverage, $coverageId, $data, $start, $end, $version): void {
            RowVersion::claim(InsurancePolicy::query()->whereKey($id), $version);
            InsuranceCoverage::query()->whereKey($coverageId)->update([
                'berlaku_sampai' => Carbon::parse($start)->subDay()->toDateString(),
                'updated_at' => now(),
            ]);
            InsuranceCoverage::query()->create([
                'tenant_id' => $this->tenant($request),
                'creation_key' => self::RESOURCE.':ganti:'.$coverageId.':'.$start,
                'polis_asuransi_id' => $id,
                'aset_id' => $coverage->aset_id,
                'nilai_pertanggungan' => $data['nilai_pertanggungan'],
                'berlaku_mulai' => $start,
                'berlaku_sampai' => $end,
                'keterangan' => $this->text($data['keterangan'] ?? null),
            ]);
        });

        return $this->document($request, $id);
    }

    /** Pertanggungan yang salah dicatat diarsipkan; yang benar tetapi berhenti diakhiri. */
    public function archiveCoverage(Request $request, string $id, string $coverageId): JsonResponse
    {
        $this->guard($request, 'update');
        $this->find($request, $id);
        $this->coverage($request, $id, $coverageId);
        $version = RowVersion::expected($request);

        DB::transaction(function () use ($id, $coverageId, $version): void {
            RowVersion::claim(InsurancePolicy::query()->whereKey($id), $version);
            InsuranceCoverage::query()->whereKey($coverageId)->update(['deleted_at' => now(), 'updated_at' => now()]);
        });

        return $this->document($request, $id);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $tenant = $this->tenant($request);

        return $request->validate([
            'legal_entity_id' => ['required', 'ulid'],
            'nama' => ['required', 'string', 'max:150'],
            'nomor_polis' => ['required', 'string', 'max:60'],
            'jenis_asuransi_id' => ['nullable', 'ulid', Rule::exists('aset_m_jenis_asuransi', 'id')->where('tenant_id', $tenant)->whereNull('deleted_at')],
            'vendor_id' => ['nullable', 'ulid'],
            'berlaku_mulai' => ['required', 'date_format:Y-m-d'],
            'berlaku_sampai' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:berlaku_mulai'],
            'premi_tahunan' => ['nullable', 'numeric', 'min:0', 'max:9999999999999999'],
            'nilai_pertanggungan' => ['nullable', 'numeric', 'min:0', 'max:9999999999999999'],
            'diblokir' => ['sometimes', 'boolean'],
            'keterangan' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function values(array $data): array
    {
        return [
            'nama' => trim((string) $data['nama']),
            'nomor_polis' => trim((string) $data['nomor_polis']),
            'jenis_asuransi_id' => $data['jenis_asuransi_id'] ?? null,
            'vendor_id' => $data['vendor_id'] ?? null,
            'berlaku_mulai' => $data['berlaku_mulai'],
            'berlaku_sampai' => $data['berlaku_sampai'] ?? null,
            'premi_tahunan' => $data['premi_tahunan'] ?? 0,
            'nilai_pertanggungan' => $data['nilai_pertanggungan'] ?? 0,
            'diblokir' => filter_var($data['diblokir'] ?? false, FILTER_VALIDATE_BOOL),
            'keterangan' => $this->text($data['keterangan'] ?? null),
        ];
    }

    /**
     * Penanggung wajib vendor entitas legal polis. Vendor nonaktif yang sudah tercatat pada polis tetap
     * diterima; yang baru dipilih wajib aktif lewat pemilih vendor.
     *
     * @param  array<string, mixed>  $data
     */
    private function validateVendor(string $tenant, array $data): void
    {
        if (($data['vendor_id'] ?? null) === null) {
            return;
        }
        $vendor = app(VendorDirectory::class)->find($tenant, (string) $data['vendor_id']);
        if ($vendor === null || $vendor['legal_entity_id'] !== $data['legal_entity_id']) {
            throw ValidationException::withMessages(['vendor_id' => 'Pilih penanggung dari vendor entitas legal polis ini.']);
        }
    }

    /** Aset dalam jangkauan pengguna, milik entitas legal polis, dan masih beredar. */
    private function requireCoverableAsset(Request $request, InsurancePolicy $policy, string $asetId): void
    {
        $query = Aset::query()->whereKey($asetId);
        app(OrganizationScope::class)->asetQuery($query, $request);
        $aset = $query->first(['aset_tr_aset.id', 'aset_tr_aset.kode', 'aset_tr_aset.legal_entity_id', 'aset_tr_aset.lifecycle_state']);
        if ($aset === null) {
            throw ValidationException::withMessages(['aset_id' => 'Aset tidak ditemukan atau berada di luar unit kerja yang dapat Anda akses.']);
        }
        if ($aset->legal_entity_id !== $policy->legal_entity_id) {
            throw ValidationException::withMessages(['aset_id' => 'Aset '.$aset->kode.' milik entitas legal lain; polis hanya menanggung aset entitas legalnya sendiri.']);
        }
        if (! StatusAset::bolehDibuatkanWorkOrder($aset->lifecycle_state)) {
            throw ValidationException::withMessages(['aset_id' => 'Aset '.$aset->kode.' sudah dihentikan atau dilepas, sehingga tidak lagi diasuransikan.']);
        }
    }

    /** Kunci idempotensi pertanggungan sudah pernah dipakai, termasuk oleh baris yang sudah diarsipkan. */
    private function coverageRecorded(string $key): bool
    {
        return InsuranceCoverage::withTrashed()->where('creation_key', $key)->exists();
    }

    private function requireWithinPolicy(InsurancePolicy $policy, string $start, ?string $end): void
    {
        $policyEnd = $policy->berlaku_sampai?->toDateString();
        if ($start < $policy->berlaku_mulai->toDateString()) {
            throw ValidationException::withMessages(['berlaku_mulai' => 'Pertanggungan tidak boleh mulai sebelum polis berlaku ('.$policy->berlaku_mulai->format('d-m-Y').').']);
        }
        if ($policyEnd !== null && ($start > $policyEnd || ($end !== null && $end > $policyEnd))) {
            throw ValidationException::withMessages(['berlaku_sampai' => 'Pertanggungan harus berada di dalam masa berlaku polis (sampai '.Carbon::parse($policyEnd)->format('d-m-Y').').']);
        }
    }

    /**
     * Satu aset tidak boleh ditanggung dua kali oleh polis yang sama pada tanggal yang sama: jumlahnya
     * akan terhitung ganda. Polis berbeda boleh menanggung aset yang sama bersamaan, seperti di BC.
     * Dipanggil sesudah versi polis diklaim, jadi dua penambahan serentak berjalan bergantian.
     */
    private function requireNoOverlap(string $policyId, string $asetId, string $start, ?string $end): void
    {
        $overlap = InsuranceCoverage::query()
            ->where(['polis_asuransi_id' => $policyId, 'aset_id' => $asetId])
            ->where(fn ($query) => $query->whereNull('berlaku_sampai')->orWhere('berlaku_sampai', '>=', $start))
            ->when($end !== null, fn ($query) => $query->where('berlaku_mulai', '<=', $end))
            ->exists();
        if ($overlap) {
            throw ValidationException::withMessages(['aset_id' => 'Aset ini sudah ditanggung polis ini pada periode yang sama. Ganti nilai pertanggungannya atau akhiri yang lama lebih dulu.']);
        }
    }

    /**
     * Kartu polis beserta pertanggungannya (termasuk yang sudah berakhir), total yang ditanggung hari
     * ini, dan selisihnya terhadap plafon polis.
     *
     * @param  array<string, string>  $headers
     */
    private function document(Request $request, string $id, int $status = 200, array $headers = []): JsonResponse
    {
        $tenant = $this->tenant($request);
        $today = $this->today();
        $row = $this->withLookups($this->scoped(InsurancePolicy::query(), $request)->where(self::TABLE.'.id', $id))->first();
        abort_if($row === null, 404);
        $totals = app(InsuredValues::class)->perPolicy([$id], $today);
        $row = $this->present($tenant, $row, $totals[$id] ?? null, $today);
        $row->pertanggungan = $this->coverages($request, $id, $today);

        return response()->json(['data' => $row], $status, [...$headers, 'ETag' => RowVersion::etag((int) $row->version)]);
    }

    /**
     * Pertanggungan polis yang asetnya terlihat oleh pengguna. Aset di luar jangkauan tidak ditampilkan,
     * tetapi tetap terhitung pada total polis: total adalah angka polis, bukan angka pengguna.
     *
     * @return Collection<int, stdClass>
     */
    private function coverages(Request $request, string $id, string $today): Collection
    {
        $query = InsuranceCoverage::query()
            ->join('aset_tr_aset', function (JoinClause $join): void {
                $join->on('aset_tr_aset.id', '=', self::COVERAGE.'.aset_id')->on('aset_tr_aset.tenant_id', '=', self::COVERAGE.'.tenant_id');
            })
            ->where(self::COVERAGE.'.polis_asuransi_id', $id);
        app(OrganizationScope::class)->asetQuery($query, $request);

        return $query->orderBy('aset_tr_aset.kode')->orderBy(self::COVERAGE.'.berlaku_mulai')
            ->toBase()
            ->get([self::COVERAGE.'.*', 'aset_tr_aset.kode as aset_kode', 'aset_tr_aset.nama as aset_nama'])
            ->map(function (stdClass $row) use ($today): stdClass {
                $row->berjalan = $row->berlaku_mulai <= $today && ($row->berlaku_sampai === null || $row->berlaku_sampai >= $today);

                return $row;
            });
    }

    /**
     * @param  Builder<InsurancePolicy>  $query
     */
    private function withLookups(Builder $query): QueryBuilder
    {
        return $query
            ->leftJoin('aset_m_jenis_asuransi as jenis', function (JoinClause $join): void {
                $join->on('jenis.id', '=', self::TABLE.'.jenis_asuransi_id')->on('jenis.tenant_id', '=', self::TABLE.'.tenant_id');
            })
            ->toBase()
            ->select([self::TABLE.'.*', 'jenis.kode as jenis_asuransi_kode', 'jenis.nama as jenis_asuransi_nama']);
    }

    /** Total yang ditanggung, selisih terhadap plafon, status berlaku, dan nama penanggung. */
    private function present(string $tenant, stdClass $row, ?string $totalInsured, string $today): stdClass
    {
        $vendor = $row->vendor_id === null ? null : app(VendorDirectory::class)->find($tenant, (string) $row->vendor_id);
        $row->vendor = $vendor === null ? null : ['id' => $vendor['id'], 'number' => $vendor['number'], 'name' => $vendor['name'], 'status' => $vendor['status']];
        $row->diblokir = (bool) $row->diblokir;
        $row->total_nilai_tertanggung = $totalInsured ?? '0.00';
        // *Over/Under Insured* BC: plafon polis dikurangi total yang ditanggung. Negatif berarti aset
        // yang ditanggung melebihi plafon polis.
        $row->selisih_plafon = number_format((float) $row->nilai_pertanggungan - (float) $row->total_nilai_tertanggung, 2, '.', '');
        $row->berlaku = $row->berlaku_mulai <= $today && ($row->berlaku_sampai === null || $row->berlaku_sampai >= $today);

        return $row;
    }

    private function find(Request $request, string $id): InsurancePolicy
    {
        $policy = $this->scoped(InsurancePolicy::query(), $request)->where(self::TABLE.'.id', $id)->first();
        abort_if($policy === null, 404);

        return $policy;
    }

    /** Pertanggungan polis ini yang asetnya dalam jangkauan pengguna. */
    private function coverage(Request $request, string $policyId, string $coverageId): InsuranceCoverage
    {
        $query = InsuranceCoverage::query()
            ->where(self::COVERAGE.'.id', $coverageId)
            ->where(self::COVERAGE.'.polis_asuransi_id', $policyId)
            ->whereIn(self::COVERAGE.'.aset_id', app(OrganizationScope::class)->asetQuery(Aset::query(), $request)->select('aset_tr_aset.id'));
        $coverage = $query->first();
        abort_if($coverage === null, 404);

        return $coverage;
    }

    /**
     * @param  Builder<InsurancePolicy>  $query
     * @return Builder<InsurancePolicy>
     */
    private function scoped(Builder $query, Request $request): Builder
    {
        app(OrganizationScope::class)->legalEntityQuery($query, $request, self::TABLE.'.legal_entity_id');

        return $query;
    }

    /** Hari ini menurut zona pengguna, bukan UTC server. */
    private function today(): string
    {
        return Carbon::now(app(RequestContext::class)->timezone())->toDateString();
    }

    private function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
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
