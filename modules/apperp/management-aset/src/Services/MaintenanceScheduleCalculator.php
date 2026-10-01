<?php

namespace Modules\Apperp\ManagementAset\Services;

use App\Platform\Modules\Contracts\RequestContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\Apperp\ManagementAset\Models\master\MaintenanceJobTypeJenisAset;
use Modules\Apperp\ManagementAset\Models\master\MaintenancePlan;
use Modules\Apperp\ManagementAset\Models\master\MaintenancePlanLine;
use Modules\Apperp\ManagementAset\Models\master\MaintenancePlanTarget;
use Modules\Apperp\ManagementAset\Models\transaksi\CounterReading\CounterReading;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;
use Modules\Apperp\ManagementAset\Models\transaksi\MaintenanceSchedule\MaintenanceScheduleLine;
use Modules\Apperp\ManagementAset\Models\transaksi\PemeliharaanAset\PemeliharaanAsetDetail;
use Modules\Apperp\ManagementAset\Support\MaintenancePlanBasis;
use Modules\Apperp\ManagementAset\Support\MaintenanceScheduleStatus;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;
use Modules\Apperp\ManagementAset\Support\StatusAset;
use Modules\Apperp\ManagementAset\Support\WorkOrderStatus;
use stdClass;

/**
 * Menghitung usulan jadwal dari rencana pemeliharaan; padanan proses *Schedule maintenance plans* F&O.
 *
 * Untuk setiap baris rencana aktif dan setiap aset yang dikenainya, ia menghitung jatuh tempo sampai
 * tanggal batas, lalu menyimpannya sebagai usulan. Tiga hal yang membuatnya aman dijalankan berulang:
 *
 * 1. **Idempoten.** Satu jatuh tempo (baris rencana, aset, tanggal — atau batas total counter) paling
 *    banyak satu usulan, dijaga indeks unik dan disisipkan dengan `ON CONFLICT DO NOTHING`. Usulan
 *    yang sudah menjadi work order atau diabaikan menahan kuncinya, jadi tidak lahir lagi.
 * 2. **Membersihkan sendiri.** Usulan yang masih `usulan` tetapi tidak lagi dihasilkan perhitungan —
 *    intervalnya diubah, asetnya dikeluarkan, rencananya dimatikan, jatuh temponya tergantikan yang
 *    lebih baru — diarsipkan.
 * 3. **Menghormati pekerjaan yang sudah ada.** Jatuh tempo tidak diusulkan bila aset sudah punya work
 *    order jenis pekerjaan yang sama (yang tidak dibatalkan) dalam rentang toleransi rencana di
 *    sekitar tanggal itu; ini padanan *Tolerance days* dan *Suppress overlapping maintenance jobs* F&O.
 *
 * Yang dihitung hanya aset dalam jangkauan organisasi peminta, dan hanya aset yang masih boleh dibuatkan
 * work order. Seluruh tanggal di sini tanggal kalender tanpa jam, dibentuk sebagai tengah malam UTC
 * supaya perbandingannya tidak tergelincir zona waktu.
 */
final class MaintenanceScheduleCalculator
{
    /** Pengaman putaran untuk baris berinterval kecil dengan horizon panjang. */
    private const MAX_OCCURRENCES = 400;

    /** @return array{dibuat: int, dibersihkan: int} */
    public function run(Request $request, Carbon $today, Carbon $until, ?string $planId = null): array
    {
        $tenant = (string) $request->attributes->get('coreerp.tenant_id');
        $created = 0;
        $cleaned = $this->cleanInactive($request, $planId);

        $plans = MaintenancePlan::query()->where('aktif', true)
            ->when($planId !== null, fn ($query) => $query->whereKey($planId))
            ->orderBy('kode')->get();

        foreach ($plans as $plan) {
            $assets = $this->assets($request, $plan);
            $lines = MaintenancePlanLine::query()->where(['rencana_pemeliharaan_id' => $plan->id, 'aktif' => true])
                ->orderBy('line_number')->get();

            foreach ($lines as $line) {
                $eligible = $this->eligibleFor($line, $assets);
                $dues = $this->withoutExistingWork($plan, $line, $this->dues($line, $eligible, $today, $until));

                $rows = [];
                foreach ($dues as $asetId => $list) {
                    $aset = $eligible[$asetId]['aset'];
                    foreach ($list as $due) {
                        $rows[] = [
                            'id' => (string) Str::ulid(),
                            'tenant_id' => $tenant,
                            'rencana_pemeliharaan_id' => $plan->id,
                            'rencana_baris_id' => $line->id,
                            'aset_id' => $asetId,
                            'legal_entity_id' => $aset->legal_entity_id,
                            'responsible_org_unit_id' => $aset->responsible_org_unit_id,
                            'jatuh_tempo' => $due['jatuh_tempo'],
                            'nilai_jatuh_tempo' => $due['nilai_jatuh_tempo'],
                            'nilai_counter' => $due['nilai_counter'],
                            'status' => MaintenanceScheduleStatus::PROPOSED,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }
                }
                if ($rows !== []) {
                    $created += MaintenanceScheduleLine::query()->insertOrIgnore($rows);
                }
                $cleaned += $this->cleanStale($request, $line, $dues);
            }
        }

        return ['dibuat' => $created, 'dibersihkan' => $cleaned];
    }

    /** Tanggal kalender sebagai tengah malam UTC. */
    public static function day(string $date): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', substr($date, 0, 10), 'UTC')->startOfDay();
    }

    /**
     * Aset yang dikenai rencana, berkunci id, beserta tanggal mulainya. Objek aset menang atas objek
     * jenis aset bila keduanya menyebut aset yang sama, karena tanggal mulainya lebih spesifik.
     *
     * @return array<string, array{aset: stdClass, start: Carbon}>
     */
    private function assets(Request $request, MaintenancePlan $plan): array
    {
        $targets = MaintenancePlanTarget::query()->where(['rencana_pemeliharaan_id' => $plan->id, 'aktif' => true])->get();
        $planStart = self::day($plan->tanggal_mulai->toDateString());
        $result = [];

        foreach (['jenis_aset_id', 'aset_id'] as $column) {
            $byId = $targets->whereNotNull($column)->keyBy($column);
            if ($byId->isEmpty()) {
                continue;
            }
            $query = Aset::query()->whereIn($column === 'aset_id' ? 'id' : 'jenis_aset_id', $byId->keys()->all());
            app(OrganizationScope::class)->asetQuery($query, $request);
            $rows = $query->toBase()->get(['id', 'kode', 'jenis_aset_id', 'legal_entity_id', 'responsible_org_unit_id', 'lifecycle_state']);
            foreach ($rows as $aset) {
                if (! StatusAset::bolehDibuatkanWorkOrder($aset->lifecycle_state)) {
                    continue;
                }
                $target = $byId[$column === 'aset_id' ? $aset->id : $aset->jenis_aset_id];
                $result[(string) $aset->id] = [
                    'aset' => $aset,
                    'start' => $target->tanggal_mulai === null ? $planStart : self::day($target->tanggal_mulai->toDateString()),
                ];
            }
        }

        return $result;
    }

    /**
     * Aset yang jenisnya cocok dengan jenis pekerjaan baris. Jenis pekerjaan yang sudah dikaitkan ke
     * jenis aset hanya berlaku untuk jenis itu — aturan yang sama dengan pembuatan work order, jadi
     * usulan yang lahir di sini memang dapat dijadikan work order.
     *
     * @param  array<string, array{aset: stdClass, start: Carbon}>  $assets
     * @return array<string, array{aset: stdClass, start: Carbon}>
     */
    private function eligibleFor(MaintenancePlanLine $line, array $assets): array
    {
        $allowed = MaintenanceJobTypeJenisAset::query()->where('job_type_id', $line->maintenance_job_type_id)
            ->pluck('jenis_aset_id')->map(strval(...))->all();
        if ($allowed === []) {
            return $assets;
        }

        return array_filter($assets, static fn (array $entry): bool => in_array((string) $entry['aset']->jenis_aset_id, $allowed, true));
    }

    /**
     * Jatuh tempo per aset.
     *
     * @param  array<string, array{aset: stdClass, start: Carbon}>  $assets
     * @return array<string, list<array{jatuh_tempo: string, nilai_jatuh_tempo: ?string, nilai_counter: ?string}>>
     */
    private function dues(MaintenancePlanLine $line, array $assets, Carbon $today, Carbon $until): array
    {
        if ($assets === []) {
            return [];
        }
        if (! MaintenancePlanBasis::isTimeBased($line->dasar)) {
            return $this->counterDues($line, $assets);
        }

        $lastDone = $line->dasar === MaintenancePlanBasis::LAST_WORK_ORDER
            ? $this->lastCompleted($line, array_keys($assets))
            : [];
        $result = [];
        foreach ($assets as $asetId => $entry) {
            $dates = $line->dasar === MaintenancePlanBasis::LAST_WORK_ORDER
                ? $this->afterLastWorkOrder($line, $entry['start'], $lastDone[$asetId] ?? null, $until)
                : $this->fromStartDate($line, $entry['start'], $today, $until);
            foreach ($dates as $date) {
                $result[$asetId][] = ['jatuh_tempo' => $date->toDateString(), 'nilai_jatuh_tempo' => null, 'nilai_counter' => null];
            }
        }

        return $result;
    }

    /**
     * *Repeated from start date*: tanggal mulai, lalu setiap interval. Jatuh tempo yang sudah lewat
     * hanya diusulkan yang paling akhir — pekerjaan terlambat tetap terlihat, tetapi rencana yang
     * dimulai lima tahun lalu tidak menimbun enam puluh usulan bulanan yang sudah basi. Yang akan
     * datang diusulkan seluruhnya sampai tanggal batas.
     *
     * @return list<Carbon>
     */
    private function fromStartDate(MaintenancePlanLine $line, Carbon $start, Carbon $today, Carbon $until): array
    {
        if ($start->gt($until)) {
            return [];
        }
        $interval = (int) $line->interval;
        $unit = (string) $line->satuan_interval;
        // Lompat mendekati hari ini lebih dahulu, supaya interval harian dari tanggal mulai yang lama
        // tidak berputar ribuan kali.
        $elapsed = match ($unit) {
            'hari' => $start->diffInDays($today),
            'minggu' => $start->diffInWeeks($today),
            'bulan' => $start->diffInMonths($today),
            default => $start->diffInYears($today),
        };
        $step = max(0, (int) floor($elapsed / $interval) - 1);

        $overdue = null;
        $upcoming = [];
        for ($i = 0; $i < self::MAX_OCCURRENCES; $i++, $step++) {
            $occurrence = MaintenancePlanBasis::add($start, $interval, $unit, $step);
            if ($occurrence->lt($today)) {
                $overdue = $occurrence;

                continue;
            }
            if ($occurrence->gt($until)) {
                break;
            }
            $upcoming[] = $occurrence;
        }

        return $overdue === null ? $upcoming : [$overdue, ...$upcoming];
    }

    /**
     * *Repeated from last work order*: satu jatuh tempo, interval sesudah work order terakhir selesai.
     * Tanpa work order, jatuh tempo pertama adalah tanggal mulai — aset yang belum pernah dikalibrasi
     * langsung terlihat terlambat. Jatuh tempo berikutnya baru dapat dihitung setelah pekerjaan ini
     * selesai, jadi hanya satu yang diusulkan.
     *
     * @return list<Carbon>
     */
    private function afterLastWorkOrder(MaintenancePlanLine $line, Carbon $start, ?Carbon $lastDone, Carbon $until): array
    {
        $due = $lastDone === null ? $start : MaintenancePlanBasis::add($lastDone, (int) $line->interval, (string) $line->satuan_interval);

        return $due->gt($until) ? [] : [$due];
    }

    /**
     * *Repeated on aggregated value*: jatuh tempo pada setiap kelipatan interval dari total counter.
     * Yang diusulkan hanya kelipatan tertinggi yang sudah dicapai (dikurangi toleransi); tanggalnya
     * tanggal pembacaan yang pertama kali mencapainya, seperti F&O memakai waktu pembacaan yang
     * melewati batas. Aset tanpa pembacaan dilewati, sama seperti F&O.
     *
     * @param  array<string, array{aset: stdClass, start: Carbon}>  $assets
     * @return array<string, list<array{jatuh_tempo: string, nilai_jatuh_tempo: ?string, nilai_counter: ?string}>>
     */
    private function counterDues(MaintenancePlanLine $line, array $assets): array
    {
        $interval = (float) $line->interval_counter;
        $tolerance = (float) $line->toleransi_counter;
        if ($interval <= 0) {
            return [];
        }

        $totals = CounterReading::query()
            ->whereIn('aset_id', array_keys($assets))
            ->where('jenis_counter_id', $line->jenis_counter_id)
            ->selectRaw('DISTINCT ON (aset_id) aset_id, nilai_total')
            ->orderBy('aset_id')->orderByDesc('dibaca_pada')->orderByDesc('id')
            ->toBase()->get();

        $result = [];
        foreach ($totals as $row) {
            $total = (float) $row->nilai_total;
            $multiple = (int) floor(($total + $tolerance) / $interval);
            if ($multiple < 1) {
                continue;
            }
            $threshold = $multiple * $interval;
            $reachedAt = CounterReading::query()
                ->where(['aset_id' => $row->aset_id, 'jenis_counter_id' => $line->jenis_counter_id])
                ->where('nilai_total', '>=', $threshold - $tolerance)
                ->orderBy('dibaca_pada')->orderBy('id')
                ->value('dibaca_pada');
            $result[(string) $row->aset_id][] = [
                'jatuh_tempo' => substr((string) $reachedAt, 0, 10),
                'nilai_jatuh_tempo' => number_format($threshold, 2, '.', ''),
                'nilai_counter' => number_format($total, 2, '.', ''),
            ];
        }

        return $result;
    }

    /**
     * Tanggal selesai aktual work order terakhir per aset untuk jenis pekerjaan (dan varian, bila
     * baris rencana menyebutnya) yang sama, dalam zona waktu peminta.
     *
     * @param  list<string>  $asetIds
     * @return array<string, Carbon>
     */
    private function lastCompleted(MaintenancePlanLine $line, array $asetIds): array
    {
        $timezone = $this->timezone();

        return $this->jobLines($line, $asetIds)
            ->whereIn('wo.status', [WorkOrderStatus::SELESAI, WorkOrderStatus::DITUTUP])
            ->whereNotNull('wo.aktual_selesai')
            ->groupBy('aset_tr_pemeliharaan_aset_details.aset_id')
            ->selectRaw('aset_tr_pemeliharaan_aset_details.aset_id, max(wo.aktual_selesai) as selesai')
            ->toBase()->get()
            ->mapWithKeys(static fn ($row): array => [
                (string) $row->aset_id => self::day(Carbon::parse((string) $row->selesai, 'UTC')->setTimezone($timezone)->toDateString()),
            ])->all();
    }

    /**
     * Membuang jatuh tempo yang sudah tertutup work order jenis pekerjaan yang sama dalam rentang
     * toleransi rencana. Tanggal work order: selesai aktual, lalu jadwal, lalu harapan, lalu tanggal
     * dibuat — yang pertama terisi.
     *
     * @param  array<string, list<array{jatuh_tempo: string, nilai_jatuh_tempo: ?string, nilai_counter: ?string}>>  $dues
     * @return array<string, list<array{jatuh_tempo: string, nilai_jatuh_tempo: ?string, nilai_counter: ?string}>>
     */
    private function withoutExistingWork(MaintenancePlan $plan, MaintenancePlanLine $line, array $dues): array
    {
        if ($dues === []) {
            return [];
        }
        $before = $plan->toleransi_hari_sebelum;
        $after = $plan->toleransi_hari_sesudah;
        $dates = collect($dues)->flatten(1)->pluck('jatuh_tempo');
        $from = self::day((string) $dates->min())->subDays($before)->toDateString();
        $to = self::day((string) $dates->max())->addDays($after + 1)->toDateString();
        $reference = 'COALESCE(wo.aktual_selesai, wo.dijadwalkan_mulai, wo.diharapkan_mulai, wo.created_at)';

        $existing = $this->jobLines($line, array_keys($dues))
            ->where('wo.status', '!=', WorkOrderStatus::DIBATALKAN)
            ->whereRaw($reference.' >= ? AND '.$reference.' < ?', [$from, $to])
            ->selectRaw('aset_tr_pemeliharaan_aset_details.aset_id, '.$reference.' as tanggal')
            ->toBase()->get()
            ->groupBy('aset_id');

        $result = [];
        foreach ($dues as $asetId => $list) {
            $workDates = ($existing[$asetId] ?? collect())->map(static fn ($row): Carbon => self::day((string) $row->tanggal));
            foreach ($list as $due) {
                $date = self::day($due['jatuh_tempo']);
                $windowStart = $date->copy()->subDays($before);
                $windowEnd = $date->copy()->addDays($after);
                $covered = $workDates->contains(static fn (Carbon $work): bool => $work->betweenIncluded($windowStart, $windowEnd));
                if (! $covered) {
                    $result[$asetId][] = $due;
                }
            }
        }

        return $result;
    }

    /**
     * Baris work order (tidak terarsip, header tidak terarsip) untuk jenis pekerjaan baris rencana.
     *
     * @param  list<string>  $asetIds
     * @return Builder<PemeliharaanAsetDetail>
     */
    private function jobLines(MaintenancePlanLine $line, array $asetIds): Builder
    {
        $details = 'aset_tr_pemeliharaan_aset_details';

        return PemeliharaanAsetDetail::query()
            ->join('aset_tr_pemeliharaan_aset as wo', function ($join) use ($details): void {
                $join->on('wo.id', '=', $details.'.pemeliharaan_aset_id')->on('wo.tenant_id', '=', $details.'.tenant_id');
            })
            ->whereNull('wo.deleted_at')
            ->whereIn($details.'.aset_id', $asetIds)
            ->where($details.'.maintenance_job_type_id', $line->maintenance_job_type_id)
            ->when($line->variant_id !== null, fn ($query) => $query->where($details.'.variant_id', $line->variant_id));
    }

    /**
     * Mengarsipkan usulan baris ini yang tidak lagi dihasilkan perhitungan, di dalam jangkauan
     * organisasi peminta. Usulan yang sudah menjadi work order atau diabaikan tidak disentuh.
     *
     * @param  array<string, list<array{jatuh_tempo: string, nilai_jatuh_tempo: ?string, nilai_counter: ?string}>>  $dues
     */
    private function cleanStale(Request $request, MaintenancePlanLine $line, array $dues): int
    {
        $keep = [];
        foreach ($dues as $asetId => $list) {
            foreach ($list as $due) {
                $keep[$asetId.'|'.($due['nilai_jatuh_tempo'] ?? $due['jatuh_tempo'])] = true;
            }
        }

        $query = MaintenanceScheduleLine::query()->where(['rencana_baris_id' => $line->id, 'status' => MaintenanceScheduleStatus::PROPOSED]);
        app(OrganizationScope::class)->query($query, $request, 'legal_entity_id', 'responsible_org_unit_id');
        $stale = $query->get(['id', 'aset_id', 'jatuh_tempo', 'nilai_jatuh_tempo'])
            ->reject(static fn (MaintenanceScheduleLine $row): bool => isset($keep[$row->aset_id.'|'.($row->nilai_jatuh_tempo ?? $row->jatuh_tempo->toDateString())]))
            ->pluck('id');

        return $this->archive($stale);
    }

    /**
     * Usulan dari rencana atau baris yang dimatikan atau diarsipkan tidak lagi dihasilkan siapa pun,
     * jadi dibersihkan di sini.
     */
    private function cleanInactive(Request $request, ?string $planId): int
    {
        $activeLines = MaintenancePlanLine::query()->where('aktif', true)
            ->whereIn('rencana_pemeliharaan_id', MaintenancePlan::query()->where('aktif', true)->select('id'))
            ->select('id');
        $query = MaintenanceScheduleLine::query()->where('status', MaintenanceScheduleStatus::PROPOSED)
            ->whereNotIn('rencana_baris_id', $activeLines)
            ->when($planId !== null, fn ($builder) => $builder->where('rencana_pemeliharaan_id', $planId));
        app(OrganizationScope::class)->query($query, $request, 'legal_entity_id', 'responsible_org_unit_id');

        return $this->archive($query->pluck('id'));
    }

    /** @param  Collection<int, mixed>  $ids */
    private function archive(Collection $ids): int
    {
        if ($ids->isEmpty()) {
            return 0;
        }

        return MaintenanceScheduleLine::query()->whereKey($ids->all())->update(['deleted_at' => now(), 'updated_at' => now()]);
    }

    private function timezone(): string
    {
        return app(RequestContext::class)->timezone();
    }
}
