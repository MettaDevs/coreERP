<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\transaksi\MaintenanceSchedule;

use App\Platform\Modules\Contracts\RequestContext;
use App\Platform\Modules\Contracts\RowVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Apperp\ManagementAset\Http\Controllers\Controller;
use Modules\Apperp\ManagementAset\Models\master\MaintenanceJobType;
use Modules\Apperp\ManagementAset\Models\master\MaintenancePlan;
use Modules\Apperp\ManagementAset\Models\master\MaintenancePlanLine;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;
use Modules\Apperp\ManagementAset\Models\transaksi\MaintenanceSchedule\MaintenanceScheduleLine;
use Modules\Apperp\ManagementAset\Services\MaintenanceScheduleCalculator;
use Modules\Apperp\ManagementAset\Services\NumberSequenceException;
use Modules\Apperp\ManagementAset\Services\WorkOrderCreator;
use Modules\Apperp\ManagementAset\Support\MaintenanceScheduleStatus;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;
use stdClass;

/**
 * Jadwal pemeliharaan: menghitung usulan dari rencana, menampilkannya, lalu mengubah usulan terpilih
 * menjadi work order atau mengabaikannya; padanan *Maintenance schedule* F&O.
 *
 * Menghitung dijalankan dari layar (*Schedule maintenance plans*). Work order dibuat lewat
 * {@see WorkOrderCreator}, jalur yang sama dengan layar work order, jadi nomor, checklist bawaan
 * jenis pekerjaan, dan seluruh pemeriksaannya ikut. Membuat work order dari usulan menuntut hak membuat
 * work order; hak membaca jadwal saja tidak cukup.
 */
final class MaintenanceScheduleController extends Controller
{
    private const RESOURCE = 'jadwal-pemeliharaan';

    private const TABLE = 'aset_tr_jadwal_pemeliharaan';

    /** Horizon perhitungan terpanjang; padanan *Period* × *Period frequency* pada proses F&O. */
    private const MAX_HORIZON_DAYS = 366;

    public function index(Request $request): JsonResponse
    {
        $this->guard($request, 'read');
        $filter = $request->validate([
            'status' => ['nullable', Rule::in([...MaintenanceScheduleStatus::ALL, 'semua'])],
            'rencana_pemeliharaan_id' => ['nullable', 'ulid'],
            'aset_id' => ['nullable', 'ulid'],
            'sampai' => ['nullable', 'date'],
        ]);
        $status = $filter['status'] ?? MaintenanceScheduleStatus::PROPOSED;

        $query = $this->withLookups(MaintenanceScheduleLine::query());
        app(OrganizationScope::class)->query($query, $request, self::TABLE.'.legal_entity_id', self::TABLE.'.responsible_org_unit_id');
        if ($status !== 'semua') {
            $query->where(self::TABLE.'.status', $status);
        }
        foreach (['rencana_pemeliharaan_id', 'aset_id'] as $column) {
            if ($filter[$column] ?? null) {
                $query->where(self::TABLE.'.'.$column, $filter[$column]);
            }
        }
        if ($filter['sampai'] ?? null) {
            $query->where(self::TABLE.'.jatuh_tempo', '<=', $filter['sampai']);
        }

        $today = $this->today()->toDateString();
        $rows = $query->orderBy(self::TABLE.'.jatuh_tempo')->orderBy('aset.kode')->limit(1000)->toBase()->get()
            ->map(static function ($row) use ($today) {
                // Terlambat dihitung menurut hari ini pengguna, bukan disimpan: besok jawabannya berubah.
                $row->terlambat = $row->status === MaintenanceScheduleStatus::PROPOSED && $row->jatuh_tempo < $today;

                return $row;
            });

        return response()->json(['data' => $rows, 'meta' => ['hari_ini' => $today]]);
    }

    public function run(Request $request, MaintenanceScheduleCalculator $calculator): JsonResponse
    {
        $this->guard($request, 'run');
        $today = $this->today();
        $data = $request->validate([
            'sampai' => ['required', 'date', 'after_or_equal:'.$today->toDateString(), 'before_or_equal:'.$today->copy()->addDays(self::MAX_HORIZON_DAYS)->toDateString()],
            'rencana_pemeliharaan_id' => ['nullable', 'ulid'],
        ], [
            'sampai.after_or_equal' => 'Tanggal batas tidak boleh sebelum hari ini.',
            'sampai.before_or_equal' => 'Jadwal paling jauh dihitung satu tahun ke depan.',
        ]);
        if (($data['rencana_pemeliharaan_id'] ?? null) !== null) {
            MaintenancePlan::query()->findOrFail($data['rencana_pemeliharaan_id']);
        }

        $result = DB::transaction(fn (): array => $calculator->run(
            $request,
            $today,
            MaintenanceScheduleCalculator::day($data['sampai']),
            $data['rencana_pemeliharaan_id'] ?? null,
        ));

        return response()->json(['data' => $result]);
    }

    public function discard(Request $request, string $id): JsonResponse
    {
        $this->guard($request, 'discard');
        $line = $this->line($request, $id);
        abort_unless($line->status === MaintenanceScheduleStatus::PROPOSED, 422, 'Hanya usulan yang belum dibuatkan work order yang dapat diabaikan.');
        $version = RowVersion::expected($request);

        DB::transaction(function () use ($id, $version): void {
            RowVersion::claim(MaintenanceScheduleLine::query()->whereKey($id), $version);
            MaintenanceScheduleLine::query()->whereKey($id)->update(['status' => MaintenanceScheduleStatus::DISCARDED, 'updated_at' => now()]);
        });

        return response()->json(['data' => $this->presented($request, $id)]);
    }

    /**
     * Mengubah usulan terpilih menjadi work order draf; padanan dialog *Create work orders* F&O.
     *
     * `kelompok=baris` membuat satu work order per usulan. `kelompok=aset` menggabungkan usulan satu
     * aset yang memakai tipe work order yang sama menjadi satu work order dengan beberapa baris
     * pekerjaan. Kunci idempotensi work order diturunkan dari id usulan, jadi permintaan ulang tidak
     * membuat work order kedua.
     */
    public function createWorkOrders(Request $request, WorkOrderCreator $creator): JsonResponse
    {
        $this->guard($request, 'read');
        abort_unless(
            in_array('management-aset.pemeliharaan-aset.create', $request->attributes->get('coreerp.permissions', []), true),
            403,
            'Membuat work order dari jadwal membutuhkan hak membuat work order pemeliharaan.',
        );
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.id' => ['required', 'ulid', 'distinct'],
            'lines.*.version' => ['required', 'integer', 'min:1'],
            'kelompok' => ['sometimes', Rule::in(['baris', 'aset'])],
        ]);
        $byAsset = ($data['kelompok'] ?? 'baris') === 'aset';
        $versions = array_column($data['lines'], 'version', 'id');

        $query = MaintenanceScheduleLine::query()->whereKey(array_keys($versions));
        app(OrganizationScope::class)->query($query, $request, 'legal_entity_id', 'responsible_org_unit_id');
        $proposals = $query->orderBy('jatuh_tempo')->orderBy('id')->get();
        if ($proposals->count() !== count($versions)) {
            throw ValidationException::withMessages(['lines' => 'Sebagian usulan tidak ditemukan atau berada di luar unit kerja yang dapat Anda akses.']);
        }
        if ($proposals->contains(fn (MaintenanceScheduleLine $line): bool => $line->status !== MaintenanceScheduleStatus::PROPOSED)) {
            throw ValidationException::withMessages(['lines' => 'Sebagian usulan sudah dibuatkan work order atau diabaikan. Muat ulang daftar lalu ulangi.']);
        }

        $planLines = MaintenancePlanLine::withTrashed()->whereKey($proposals->pluck('rencana_baris_id')->unique()->all())->get()->keyBy('id');
        $assets = Aset::query()->whereKey($proposals->pluck('aset_id')->unique()->all())->get()->keyBy('id');
        $groups = $proposals->groupBy(function (MaintenanceScheduleLine $line) use ($byAsset, $planLines): string {
            return $byAsset ? $line->aset_id.'|'.$planLines[$line->rencana_baris_id]->tipe_work_order_id : $line->id;
        });

        try {
            $created = DB::transaction(function () use ($request, $creator, $groups, $versions, $planLines, $assets): array {
                $created = [];
                foreach ($groups as $group) {
                    foreach ($group as $line) {
                        RowVersion::claim(MaintenanceScheduleLine::query()->whereKey($line->id), (int) $versions[$line->id]);
                    }
                    $result = $creator->create($request, $this->workOrderPayload(array_values($group->all()), $planLines->all(), $assets->all()), 'jadwal:'.$group->first()->id);
                    $workOrder = $result['record'];
                    MaintenanceScheduleLine::query()->whereKey($group->pluck('id')->all())->update([
                        'status' => MaintenanceScheduleStatus::WORK_ORDER_CREATED,
                        'pemeliharaan_aset_id' => $workOrder->id,
                        'updated_at' => now(),
                    ]);
                    $created[] = ['id' => $workOrder->id, 'kode' => $workOrder->kode];
                }

                return $created;
            });
        } catch (NumberSequenceException $exception) {
            return response()->json(['error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()]], NumberSequenceException::HTTP_STATUS);
        }

        return response()->json(['data' => ['work_orders' => $created]], 201);
    }

    /**
     * Isi work order dari satu kelompok usulan. Entitas legal dan unit penanggung jawab dibaca dari
     * aset saat ini, bukan dari usulan: aset mungkin sudah dimutasi sejak jadwal dihitung.
     *
     * @param  list<MaintenanceScheduleLine>  $group
     * @param  array<string, MaintenancePlanLine>  $planLines
     * @param  array<string, Aset>  $assets
     * @return array<string, mixed>
     */
    private function workOrderPayload(array $group, array $planLines, array $assets): array
    {
        $first = $group[0];
        $aset = $assets[$first->aset_id] ?? throw ValidationException::withMessages(['lines' => 'Aset usulan sudah tidak tersedia.']);
        if ($aset->responsible_org_unit_id === null) {
            throw ValidationException::withMessages(['lines' => 'Aset '.$aset->kode.' belum punya unit penanggung jawab, sehingga work order-nya belum dapat dibuat. Lengkapi asetnya lebih dahulu.']);
        }
        $planLine = $planLines[$first->rencana_baris_id];
        $start = collect($group)->min(fn (MaintenanceScheduleLine $line): string => $line->jatuh_tempo->toDateString());
        $ends = collect($group)->map(function (MaintenanceScheduleLine $line) use ($planLines): ?string {
            $days = $planLines[$line->rencana_baris_id]->selesai_dalam_hari;

            return $days === null ? null : $line->jatuh_tempo->copy()->addDays($days)->toDateString();
        })->filter();

        return [
            'legal_entity_id' => $aset->legal_entity_id,
            'responsible_org_unit_id' => $aset->responsible_org_unit_id,
            'tipe_work_order_id' => $planLine->tipe_work_order_id,
            'tingkat_layanan_id' => $planLine->tingkat_layanan_id,
            'keterangan' => $this->description($group, $planLines),
            'diharapkan_mulai' => $start.' 00:00:00',
            'diharapkan_selesai' => $ends->isEmpty() ? null : $ends->max().' 23:59:59',
            'details' => array_map(fn (MaintenanceScheduleLine $line): array => [
                'aset_id' => $line->aset_id,
                'maintenance_job_type_id' => $planLines[$line->rencana_baris_id]->maintenance_job_type_id,
                'variant_id' => $planLines[$line->rencana_baris_id]->variant_id,
                'trade_id' => $planLines[$line->rencana_baris_id]->trade_id,
            ], $group),
        ];
    }

    /**
     * Keterangan work order: deskripsi baris rencana bila ada (*Work order description* F&O), selain
     * itu nama rencana dan jenis pekerjaannya.
     *
     * @param  list<MaintenanceScheduleLine>  $group
     * @param  array<string, MaintenancePlanLine>  $planLines
     */
    private function description(array $group, array $planLines): string
    {
        $plans = MaintenancePlan::withTrashed()->whereKey(collect($group)->pluck('rencana_pemeliharaan_id')->unique()->all())->pluck('nama', 'id');
        $jobs = MaintenanceJobType::withTrashed()->whereKey(collect($planLines)->pluck('maintenance_job_type_id')->unique()->all())->pluck('nama', 'id');

        return collect($group)->map(function (MaintenanceScheduleLine $line) use ($planLines, $plans, $jobs): string {
            $planLine = $planLines[$line->rencana_baris_id];

            return $planLine->deskripsi
                ?? ($plans[$line->rencana_pemeliharaan_id] ?? 'Rencana pemeliharaan').' — '.($jobs[$planLine->maintenance_job_type_id] ?? 'pekerjaan')
                    .' (jatuh tempo '.$line->jatuh_tempo->format('d-m-Y').')';
        })->unique()->implode('; ');
    }

    private function line(Request $request, string $id): stdClass
    {
        $query = MaintenanceScheduleLine::query()->where(self::TABLE.'.id', $id);
        app(OrganizationScope::class)->query($query, $request, self::TABLE.'.legal_entity_id', self::TABLE.'.responsible_org_unit_id');

        return $query->toBase()->first() ?? abort(404);
    }

    private function presented(Request $request, string $id): ?stdClass
    {
        $query = $this->withLookups(MaintenanceScheduleLine::query())->where(self::TABLE.'.id', $id);
        app(OrganizationScope::class)->query($query, $request, self::TABLE.'.legal_entity_id', self::TABLE.'.responsible_org_unit_id');

        return $query->toBase()->first();
    }

    /**
     * Usulan beserta nama rencana, jenis pekerjaan, counter, aset, dan nomor work order-nya.
     *
     * @param  Builder<MaintenanceScheduleLine>  $query
     * @return Builder<MaintenanceScheduleLine>
     */
    private function withLookups(Builder $query): Builder
    {
        $join = static fn (string $alias, string $column) => function ($join) use ($alias, $column): void {
            $join->on($alias.'.id', '=', self::TABLE.'.'.$column)->on($alias.'.tenant_id', '=', self::TABLE.'.tenant_id');
        };

        return $query
            ->leftJoin('aset_m_rencana_pemeliharaan as rencana', $join('rencana', 'rencana_pemeliharaan_id'))
            ->leftJoin('aset_m_rencana_pemeliharaan_baris as baris', $join('baris', 'rencana_baris_id'))
            ->leftJoin('aset_m_maintenance_job_type as pekerjaan', function ($join): void {
                $join->on('pekerjaan.id', '=', 'baris.maintenance_job_type_id')->on('pekerjaan.tenant_id', '=', 'baris.tenant_id');
            })
            ->leftJoin('aset_m_jenis_counter as counter', function ($join): void {
                $join->on('counter.id', '=', 'baris.jenis_counter_id')->on('counter.tenant_id', '=', 'baris.tenant_id');
            })
            ->leftJoin('aset_tr_aset as aset', $join('aset', 'aset_id'))
            ->leftJoin('aset_tr_pemeliharaan_aset as wo', $join('wo', 'pemeliharaan_aset_id'))
            ->select([
                self::TABLE.'.*',
                'rencana.kode as rencana_kode', 'rencana.nama as rencana_nama',
                'baris.line_number as rencana_baris_nomor', 'baris.dasar', 'baris.interval', 'baris.satuan_interval',
                'baris.interval_counter',
                'pekerjaan.kode as job_type_kode', 'pekerjaan.nama as job_type_nama',
                'counter.nama as jenis_counter_nama', 'counter.satuan as jenis_counter_satuan',
                'aset.kode as aset_kode', 'aset.nama as aset_nama',
                'wo.kode as work_order_kode', 'wo.status as work_order_status',
            ]);
    }

    /** Hari ini menurut zona waktu pengguna, sebagai tanggal kalender. */
    private function today(): Carbon
    {
        return MaintenanceScheduleCalculator::day(Carbon::now(app(RequestContext::class)->timezone())->toDateString());
    }

    private function guard(Request $request, string $action): void
    {
        abort_unless(
            in_array('management-aset.'.self::RESOURCE.'.'.$action, $request->attributes->get('coreerp.permissions', []), true),
            403,
        );
    }
}
