<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\transaksi\Downtime;

use App\Platform\Modules\Contracts\RequestContext;
use App\Platform\Modules\Contracts\RowVersion;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Apperp\ManagementAset\Http\Controllers\Controller;
use Modules\Apperp\ManagementAset\Models\master\DowntimeReason;
use Modules\Apperp\ManagementAset\Models\transaksi\Downtime\AssetDowntime;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;
use Modules\Apperp\ManagementAset\Models\transaksi\MaintenanceRequest\MaintenanceRequest;
use Modules\Apperp\ManagementAset\Models\transaksi\PemeliharaanAset\PemeliharaanAsetDetail;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;
use stdClass;

/**
 * Pencatatan downtime aset; padanan halaman *Maintenance downtime* Dynamics 365 F&O.
 *
 * Satu catatan adalah satu periode aset tidak dapat dipakai. Aturannya:
 *
 * - Waktu diketik menurut zona pengguna dan disimpan dalam UTC. Isian dengan offset dibaca apa adanya.
 * - Selesai boleh kosong selama aset masih berhenti; satu aset paling banyak satu catatan terbuka.
 * - Catatan satu aset tidak boleh tumpang tindih, supaya availability tidak menghitung jam yang sama dua
 *   kali. F&O hanya menolak dua registrasi berwaktu persis sama; aturan di sini lebih ketat.
 * - Aset, work order, dan permintaan pemeliharaan tidak dapat diganti sesudah dicatat, seperti di F&O.
 *   Yang boleh dikoreksi waktu, alasan, dan keterangan.
 *
 * Jangkauan organisasi mengikuti aset.
 */
class AssetDowntimeController extends Controller
{
    private const RESOURCE = 'downtime-aset';

    private const TABLE = 'aset_tr_downtime_aset';

    private const MAX_CREATION_KEY = 140;

    public function index(Request $request): JsonResponse
    {
        $this->guard($request, 'read');
        $filter = $request->validate([
            'aset_id' => ['nullable', 'ulid'],
            'pemeliharaan_aset_id' => ['nullable', 'ulid'],
            'dari' => ['nullable', 'date_format:Y-m-d'],
            'sampai' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:dari'],
            'terbuka' => ['nullable', 'boolean'],
        ]);
        $timezone = app(RequestContext::class)->timezone();

        $query = $this->joined(AssetDowntime::query(), $request);
        foreach (['aset_id', 'pemeliharaan_aset_id'] as $column) {
            if ($filter[$column] ?? null) {
                $query->where(self::TABLE.'.'.$column, $filter[$column]);
            }
        }
        // Periode dibaca menurut hari pengguna; catatan yang sebagian berada di dalamnya ikut.
        if ($filter['dari'] ?? null) {
            $from = Carbon::parse($filter['dari'], $timezone)->startOfDay()->utc();
            $query->where(fn ($query) => $query->whereNull(self::TABLE.'.selesai')->orWhere(self::TABLE.'.selesai', '>', $from));
        }
        if ($filter['sampai'] ?? null) {
            $query->where(self::TABLE.'.mulai', '<', Carbon::parse($filter['sampai'], $timezone)->addDay()->startOfDay()->utc());
        }
        if (filter_var($filter['terbuka'] ?? false, FILTER_VALIDATE_BOOL)) {
            $query->whereNull(self::TABLE.'.selesai');
        }

        return response()->json(['data' => $query->orderByDesc(self::TABLE.'.mulai')->limit(500)->toBase()->get()->map($this->present(...))]);
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

        $data = $request->validate([
            'aset_id' => ['required', 'ulid'],
            'mulai' => ['required', 'date'],
            'selesai' => ['nullable', 'date'],
            'alasan_downtime_id' => ['nullable', 'ulid'],
            'pemeliharaan_aset_id' => ['nullable', 'ulid'],
            'permintaan_pemeliharaan_id' => ['nullable', 'ulid'],
            'keterangan' => ['nullable', 'string', 'max:2000'],
        ]);
        $this->aset($request, $data['aset_id']);
        [$start, $end] = $this->period($data['mulai'], $data['selesai'] ?? null);
        $this->validateReason($data['alasan_downtime_id'] ?? null);
        $workOrderId = $data['pemeliharaan_aset_id'] ?? null;
        if ($workOrderId !== null && ! PemeliharaanAsetDetail::query()->where(['pemeliharaan_aset_id' => $workOrderId, 'aset_id' => $data['aset_id']])->exists()) {
            throw ValidationException::withMessages(['pemeliharaan_aset_id' => 'Work order ini tidak memuat aset tersebut.']);
        }
        $requestId = $data['permintaan_pemeliharaan_id'] ?? null;
        if ($requestId !== null && ! MaintenanceRequest::query()->whereKey($requestId)->where('aset_id', $data['aset_id'])->exists()) {
            throw ValidationException::withMessages(['permintaan_pemeliharaan_id' => 'Permintaan pemeliharaan ini bukan untuk aset tersebut.']);
        }
        // Permintaan yang melahirkan work order ikut tercatat, supaya downtime dapat ditelusuri ke laporannya.
        $requestId ??= $workOrderId === null ? null : MaintenanceRequest::query()->where('pemeliharaan_aset_id', $workOrderId)->value('id');

        try {
            DB::transaction(function () use ($data, $key, $start, $end, $workOrderId, $requestId): void {
                Aset::query()->whereKey($data['aset_id'])->lockForUpdate()->first(['id']);
                $this->requireNoOverlap($data['aset_id'], $start, $end, null);
                AssetDowntime::query()->create([
                    'creation_key' => $key,
                    'aset_id' => $data['aset_id'],
                    'mulai' => $start,
                    'selesai' => $end,
                    'alasan_downtime_id' => $data['alasan_downtime_id'] ?? null,
                    'pemeliharaan_aset_id' => $workOrderId,
                    'permintaan_pemeliharaan_id' => $requestId,
                    'sumber' => AssetDowntime::MANUAL,
                    'keterangan' => $this->text($data['keterangan'] ?? null),
                ]);
            });
        } catch (QueryException $exception) {
            $existing = $this->replay($request, $key);
            if ($existing === null) {
                throw $exception;
            }

            return response()->json(['data' => $existing], 200, ['Idempotent-Replayed' => 'true']);
        }

        return response()->json(['data' => $this->replay($request, $key)], 201);
    }

    /** Koreksi waktu, alasan, dan keterangan; termasuk menutup downtime yang masih terbuka. */
    public function update(Request $request, string $id): JsonResponse
    {
        $this->guard($request, 'update');
        $downtime = $this->find($request, $id);
        $data = $request->validate([
            'mulai' => ['required', 'date'],
            'selesai' => ['nullable', 'date'],
            'alasan_downtime_id' => ['nullable', 'ulid'],
            'keterangan' => ['nullable', 'string', 'max:2000'],
        ]);
        $version = RowVersion::expected($request);
        [$start, $end] = $this->period($data['mulai'], $data['selesai'] ?? null);
        $this->validateReason($data['alasan_downtime_id'] ?? null);

        DB::transaction(function () use ($downtime, $id, $data, $start, $end, $version): void {
            Aset::query()->whereKey($downtime->aset_id)->lockForUpdate()->first(['id']);
            RowVersion::claim(AssetDowntime::query()->whereKey($id), $version);
            $this->requireNoOverlap($downtime->aset_id, $start, $end, $id);
            AssetDowntime::query()->whereKey($id)->update([
                'mulai' => $start,
                'selesai' => $end,
                'alasan_downtime_id' => $data['alasan_downtime_id'] ?? null,
                'keterangan' => $this->text($data['keterangan'] ?? null),
                'updated_at' => now(),
            ]);
        });

        return response()->json(['data' => $this->one($request, $id)]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->guard($request, 'archive');
        $this->find($request, $id);
        $version = RowVersion::expected($request);

        DB::transaction(function () use ($id, $version): void {
            RowVersion::claim(AssetDowntime::query()->whereKey($id), $version);
            AssetDowntime::query()->whereKey($id)->update(['deleted_at' => now(), 'updated_at' => now()]);
        });

        return response()->json(status: 204);
    }

    /**
     * Mulai dan selesai sebagai waktu UTC. Waktu tanpa offset dibaca menurut zona pengguna; selesai tidak
     * boleh di masa depan, karena catatan ini mencatat yang sudah terjadi.
     *
     * @return array{0: Carbon, 1: ?Carbon}
     */
    private function period(string $start, ?string $end): array
    {
        $timezone = app(RequestContext::class)->timezone();
        $from = Carbon::parse($start, $timezone)->utc();
        $until = $end === null || $end === '' ? null : Carbon::parse($end, $timezone)->utc();
        $now = now()->utc();
        if ($from->greaterThan($now)) {
            throw ValidationException::withMessages(['mulai' => 'Waktu mulai tidak boleh di masa depan; downtime mencatat henti yang sudah terjadi.']);
        }
        if ($until !== null && $until->lessThanOrEqualTo($from)) {
            throw ValidationException::withMessages(['selesai' => 'Waktu selesai harus sesudah waktu mulai.']);
        }
        if ($until !== null && $until->greaterThan($now)) {
            throw ValidationException::withMessages(['selesai' => 'Waktu selesai tidak boleh di masa depan. Kosongkan bila aset masih berhenti.']);
        }

        return [$from, $until];
    }

    /** Catatan lain pada aset yang sama tidak boleh berpotongan dengan periode ini. */
    private function requireNoOverlap(string $asetId, Carbon $start, ?Carbon $end, ?string $exceptId): void
    {
        $overlap = AssetDowntime::query()
            ->where('aset_id', $asetId)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->where(fn ($query) => $query->whereNull('selesai')->orWhere('selesai', '>', $start))
            ->when($end !== null, fn ($query) => $query->where('mulai', '<', $end))
            ->orderBy('mulai')
            ->first();
        if ($overlap !== null) {
            throw ValidationException::withMessages(['mulai' => $overlap->selesai === null
                ? 'Aset ini masih tercatat berhenti sejak '.$this->local($overlap->mulai).'. Tutup catatan itu lebih dulu.'
                : 'Periode ini bertumpang tindih dengan downtime '.$this->local($overlap->mulai).' sampai '.$this->local($overlap->selesai).'.']);
        }
    }

    private function validateReason(?string $reasonId): void
    {
        if ($reasonId !== null && ! DowntimeReason::query()->whereKey($reasonId)->where('aktif', true)->exists()) {
            throw ValidationException::withMessages(['alasan_downtime_id' => 'Alasan downtime tidak ditemukan atau sudah tidak aktif.']);
        }
    }

    private function aset(Request $request, string $asetId): void
    {
        $query = Aset::query()->whereKey($asetId);
        app(OrganizationScope::class)->asetQuery($query, $request);
        if (! $query->exists()) {
            throw ValidationException::withMessages(['aset_id' => 'Aset tidak ditemukan atau berada di luar unit kerja yang dapat Anda akses.']);
        }
    }

    private function find(Request $request, string $id): AssetDowntime
    {
        $downtime = AssetDowntime::query()
            ->whereKey($id)
            ->whereIn('aset_id', app(OrganizationScope::class)->asetQuery(Aset::query(), $request)->select('aset_tr_aset.id'))
            ->first();
        abort_if($downtime === null, 404);

        return $downtime;
    }

    private function one(Request $request, string $id): ?stdClass
    {
        $row = $this->joined(AssetDowntime::query(), $request)->where(self::TABLE.'.id', $id)->toBase()->first();

        return $row === null ? null : $this->present($row);
    }

    private function replay(Request $request, string $key): ?stdClass
    {
        $row = $this->joined(AssetDowntime::withTrashed(), $request)->where(self::TABLE.'.creation_key', $key)->toBase()->first();

        return $row === null ? null : $this->present($row);
    }

    /**
     * Downtime beserta aset, alasan, work order, dan permintaannya; tersaring jangkauan lewat aset.
     *
     * @param  Builder<AssetDowntime>  $query
     * @return Builder<AssetDowntime>
     */
    private function joined(Builder $query, Request $request): Builder
    {
        $query
            ->join('aset_tr_aset', function (JoinClause $join): void {
                $join->on('aset_tr_aset.id', '=', self::TABLE.'.aset_id')->on('aset_tr_aset.tenant_id', '=', self::TABLE.'.tenant_id');
            })
            ->leftJoin('aset_m_alasan_downtime as alasan', function (JoinClause $join): void {
                $join->on('alasan.id', '=', self::TABLE.'.alasan_downtime_id')->on('alasan.tenant_id', '=', self::TABLE.'.tenant_id');
            })
            ->leftJoin('aset_tr_pemeliharaan_aset as wo', function (JoinClause $join): void {
                $join->on('wo.id', '=', self::TABLE.'.pemeliharaan_aset_id')->on('wo.tenant_id', '=', self::TABLE.'.tenant_id');
            })
            ->leftJoin('aset_tr_permintaan_pemeliharaan as permintaan', function (JoinClause $join): void {
                $join->on('permintaan.id', '=', self::TABLE.'.permintaan_pemeliharaan_id')->on('permintaan.tenant_id', '=', self::TABLE.'.tenant_id');
            })
            ->select([
                self::TABLE.'.*',
                'aset_tr_aset.kode as aset_kode', 'aset_tr_aset.nama as aset_nama',
                'alasan.kode as alasan_downtime_kode', 'alasan.nama as alasan_downtime_nama', 'alasan.masuk_kpi',
                'wo.kode as pemeliharaan_aset_kode', 'permintaan.kode as permintaan_pemeliharaan_kode',
            ]);
        app(OrganizationScope::class)->asetQuery($query, $request);

        return $query;
    }

    /** Durasi dalam jam; catatan terbuka dihitung sampai sekarang. Tanpa alasan tetap masuk KPI. */
    private function present(stdClass $row): stdClass
    {
        $end = $row->selesai === null ? now() : Carbon::parse($row->selesai);
        $row->durasi_jam = round(max(0, Carbon::parse($row->mulai)->diffInSeconds($end, false)) / 3600, 2);
        $row->terbuka = $row->selesai === null;
        $row->masuk_kpi = $row->masuk_kpi === null ? true : (bool) $row->masuk_kpi;

        return $row;
    }

    private function local(CarbonInterface $time): string
    {
        return $time->copy()->setTimezone(app(RequestContext::class)->timezone())->format('d-m-Y H:i');
    }

    private function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function guard(Request $request, string $action): void
    {
        abort_unless(in_array('management-aset.'.self::RESOURCE.'.'.$action, $request->attributes->get('coreerp.permissions', []), true), 403);
    }
}
