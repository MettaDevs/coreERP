<?php

namespace Modules\Apperp\ManagementAset\Services;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Modules\Apperp\ManagementAset\Models\master\DowntimeReason;
use Modules\Apperp\ManagementAset\Models\transaksi\Downtime\AssetDowntime;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;
use Modules\Apperp\ManagementAset\Models\transaksi\PemeliharaanAset\PemeliharaanAsetDetail;
use Modules\Apperp\ManagementAset\Support\WorkOrderStatus;
use stdClass;

/**
 * KPI pemeliharaan per aset, jenis aset, atau lokasi untuk satu periode; padanan *Asset KPIs* Dynamics
 * 365 F&O Asset Management dan laporan *Maintenance Analysis* Business Central.
 *
 * Dihitung saat dibaca, bukan disimpan sebagai tabel agregat: angkanya bergantung pada periode yang
 * dipilih, dan downtime serta work order boleh dikoreksi sesudahnya. Pemanggil (layar KPI, dashboard)
 * menyiapkan query aset yang sudah tersaring jangkauan organisasi dan penyaringnya sendiri.
 *
 * Rumus mengikuti tabel field halaman *Asset KPIs* F&O; satuannya jam, dengan kalender 24 jam karena
 * modul ini belum punya kalender kerja (lihat dokumen fitur):
 *
 * - Total waktu = jam dari awal periode (atau tanggal aset mulai dipakai, mana yang lebih akhir) sampai
 *   akhir periode (atau saat ini, mana yang lebih awal).
 * - Downtime = jumlah jam catatan downtime di dalam total waktu, kecuali yang alasannya tidak masuk KPI.
 * - Uptime = total waktu − downtime. Availability % = uptime ÷ total waktu × 100.
 * - Jumlah kerusakan = baris pekerjaan ber-sebab kerusakan pada work order yang selesai dalam periode.
 * - MTBF = total waktu ÷ jumlah kerusakan; tanpa kerusakan, MTBF = total waktu.
 * - Jam perbaikan = jam aktual baris pekerjaan ber-sebab kerusakan itu.
 * - MTTR (*MRT* di F&O) = jam perbaikan ÷ jumlah kerusakan; tanpa kerusakan, MTTR = jam perbaikan.
 * - Jumlah henti = catatan downtime yang masuk KPI dan berpotongan dengan total waktu.
 * - Work order selesai = work order berstatus selesai atau ditutup yang selesai dalam periode.
 *
 * Kelompok (jenis, lokasi, seluruhnya) menjumlahkan total waktu, downtime, kerusakan, jam perbaikan,
 * henti, dan work order anggotanya, lalu menghitung ulang rasio dari jumlah itu — bukan merata-ratakan
 * rasio per aset.
 */
final class MaintenanceKpi
{
    public const BY_ASSET = 'aset';

    public const BY_TYPE = 'jenis';

    public const BY_LOCATION = 'lokasi';

    public const GROUPINGS = [self::BY_ASSET, self::BY_TYPE, self::BY_LOCATION];

    /** Status work order yang dihitung sebagai selesai. */
    private const FINISHED = [WorkOrderStatus::SELESAI, WorkOrderStatus::DITUTUP];

    /**
     * @param  Builder<Aset>  $assets  aset yang dihitung, sudah tersaring jangkauan dan penyaring pemanggil
     * @param  CarbonInterface  $from  awal periode, inklusif, dalam zona pengguna (tanggal mulai pakai aset dibaca di zona ini)
     * @param  CarbonInterface  $until  akhir periode, eksklusif
     * @return array{baris: list<array<string, mixed>>, total: array<string, mixed>}
     */
    public function calculate(Builder $assets, CarbonInterface $from, CarbonInterface $until, string $groupBy = self::BY_ASSET): array
    {
        if (! in_array($groupBy, self::GROUPINGS, true)) {
            throw new InvalidArgumentException('Pengelompokan KPI tidak dikenal: '.$groupBy);
        }
        $timezone = $from->getTimezone()->getName();
        $from = Carbon::createFromTimestamp($from->getTimestamp())->utc();
        $until = Carbon::createFromTimestamp(min($until->getTimestamp(), now()->getTimestamp()))->utc();

        $rows = $assets->clone()
            ->leftJoin('aset_m_jenis_aset as kpi_jenis', function (JoinClause $join): void {
                $join->on('kpi_jenis.id', '=', 'aset_tr_aset.jenis_aset_id')->on('kpi_jenis.tenant_id', '=', 'aset_tr_aset.tenant_id');
            })
            ->leftJoin('aset_m_lokasi_aset as kpi_lokasi', function (JoinClause $join): void {
                $join->on('kpi_lokasi.id', '=', 'aset_tr_aset.lokasi_aset_id')->on('kpi_lokasi.tenant_id', '=', 'aset_tr_aset.tenant_id');
            })
            ->orderBy('aset_tr_aset.kode')
            ->toBase()
            ->get([
                'aset_tr_aset.id', 'aset_tr_aset.kode', 'aset_tr_aset.nama', 'aset_tr_aset.acquired_on', 'aset_tr_aset.placed_in_service_on',
                'aset_tr_aset.jenis_aset_id', 'kpi_jenis.kode as jenis_kode', 'kpi_jenis.nama as jenis_nama',
                'aset_tr_aset.lokasi_aset_id', 'kpi_lokasi.kode as lokasi_kode', 'kpi_lokasi.nama as lokasi_nama',
            ]);

        $windows = [];
        foreach ($rows as $row) {
            $windows[(string) $row->id] = $this->window($row, $from, $until, $timezone);
        }
        $ids = array_keys(array_filter($windows));
        $downtime = $this->downtime($ids, $windows, $from, $until);
        $work = $this->workOrders($ids, $from, $until);

        $perAsset = [];
        foreach ($rows as $row) {
            $id = (string) $row->id;
            $window = $windows[$id];
            $perAsset[] = [
                'row' => $row,
                'figures' => [
                    'jumlah_aset' => 1,
                    'total_jam' => $window === null ? 0.0 : ($window[1]->getTimestamp() - $window[0]->getTimestamp()) / 3600,
                    'downtime_jam' => $downtime[$id]['hours'] ?? 0.0,
                    'jumlah_henti' => $downtime[$id]['stops'] ?? 0,
                    'jumlah_kerusakan' => $work[$id]['faults'] ?? 0,
                    'jam_perbaikan' => $work[$id]['repair_hours'] ?? 0.0,
                    'wo_selesai' => $work[$id]['work_orders'] ?? 0,
                ],
            ];
        }

        $groups = [];
        foreach ($perAsset as $item) {
            [$key, $code, $name] = $this->groupKey($item['row'], $groupBy);
            $groups[$key] ??= ['kunci' => $key === '' ? null : $key, 'kode' => $code, 'nama' => $name, 'sums' => []];
            $groups[$key]['sums'] = $this->add($groups[$key]['sums'], $item['figures']);
        }

        $total = [];
        foreach ($perAsset as $item) {
            $total = $this->add($total, $item['figures']);
        }

        return [
            'baris' => array_values(array_map(
                fn (array $group): array => ['kunci' => $group['kunci'], 'kode' => $group['kode'], 'nama' => $group['nama'], ...$this->ratios($group['sums'])],
                $groups,
            )),
            'total' => $this->ratios($total),
        ];
    }

    /**
     * Rentang waktu aset di dalam periode: mulai dari tanggal aset mulai dipakai (atau diperoleh) bila
     * lebih akhir dari awal periode. Null bila aset belum ada sepanjang periode.
     *
     * @return array{0: Carbon, 1: Carbon}|null
     */
    private function window(stdClass $aset, Carbon $from, Carbon $until, string $timezone): ?array
    {
        $since = $aset->placed_in_service_on ?? $aset->acquired_on;
        $start = $from;
        if ($since !== null) {
            $inService = Carbon::parse(substr((string) $since, 0, 10), $timezone)->startOfDay()->utc();
            $start = $inService->greaterThan($from) ? $inService : $from;
        }

        return $start->lessThan($until) ? [$start, $until] : null;
    }

    /**
     * Jam downtime dan jumlah henti per aset, dipotong ke rentang aset. Catatan terbuka dihitung sampai
     * akhir rentang.
     *
     * @param  list<string>  $ids
     * @param  array<string, array{0: Carbon, 1: Carbon}|null>  $windows
     * @return array<string, array{hours: float, stops: int}>
     */
    private function downtime(array $ids, array $windows, Carbon $from, Carbon $until): array
    {
        if ($ids === []) {
            return [];
        }

        $records = AssetDowntime::query()
            ->whereIn('aset_id', $ids)
            ->where('mulai', '<', $until)
            ->where(fn ($query) => $query->whereNull('selesai')->orWhere('selesai', '>', $from))
            ->where(fn ($query) => $query->whereNull('alasan_downtime_id')
                ->orWhereIn('alasan_downtime_id', DowntimeReason::withTrashed()->where('masuk_kpi', true)->select('id')))
            ->toBase()
            ->get(['aset_id', 'mulai', 'selesai']);

        $result = [];
        foreach ($records as $record) {
            $id = (string) $record->aset_id;
            $window = $windows[$id] ?? null;
            if ($window === null) {
                continue;
            }
            $start = max(Carbon::parse((string) $record->mulai)->getTimestamp(), $window[0]->getTimestamp());
            $end = min($record->selesai === null ? $window[1]->getTimestamp() : Carbon::parse((string) $record->selesai)->getTimestamp(), $window[1]->getTimestamp());
            if ($end <= $start) {
                continue;
            }
            $result[$id] ??= ['hours' => 0.0, 'stops' => 0];
            $result[$id]['hours'] += ($end - $start) / 3600;
            $result[$id]['stops']++;
        }

        return $result;
    }

    /**
     * Work order selesai, kerusakan, dan jam perbaikan per aset dari work order yang selesai dalam periode.
     *
     * @param  list<string>  $ids
     * @return array<string, array{work_orders: int, faults: int, repair_hours: float}>
     */
    private function workOrders(array $ids, Carbon $from, Carbon $until): array
    {
        if ($ids === []) {
            return [];
        }

        $fault = "(aset_tr_pemeliharaan_aset_details.sebab_kerusakan_id is not null or coalesce(trim(aset_tr_pemeliharaan_aset_details.sebab_kerusakan_keterangan), '') <> '')";
        $rows = PemeliharaanAsetDetail::query()
            ->join('aset_tr_pemeliharaan_aset as wo', function (JoinClause $join): void {
                $join->on('wo.id', '=', 'aset_tr_pemeliharaan_aset_details.pemeliharaan_aset_id')
                    ->on('wo.tenant_id', '=', 'aset_tr_pemeliharaan_aset_details.tenant_id');
            })
            ->whereNull('wo.deleted_at')
            ->whereIn('wo.status', self::FINISHED)
            ->where('wo.aktual_selesai', '>=', $from)
            ->where('wo.aktual_selesai', '<', $until)
            ->whereIn('aset_tr_pemeliharaan_aset_details.aset_id', $ids)
            ->groupBy('aset_tr_pemeliharaan_aset_details.aset_id')
            ->toBase()
            ->select('aset_tr_pemeliharaan_aset_details.aset_id')
            ->selectRaw('count(distinct wo.id) as work_orders')
            ->selectRaw("count(*) filter (where {$fault}) as faults")
            ->selectRaw("coalesce(sum(aset_tr_pemeliharaan_aset_details.aktual_jam) filter (where {$fault}), 0) as repair_hours")
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row->aset_id] = [
                'work_orders' => (int) $row->work_orders,
                'faults' => (int) $row->faults,
                'repair_hours' => (float) $row->repair_hours,
            ];
        }

        return $result;
    }

    /** @return array{0: string, 1: ?string, 2: ?string} kunci kelompok, kode, nama */
    private function groupKey(stdClass $row, string $groupBy): array
    {
        return match ($groupBy) {
            self::BY_TYPE => [(string) ($row->jenis_aset_id ?? ''), $row->jenis_kode, $row->jenis_nama],
            self::BY_LOCATION => [(string) ($row->lokasi_aset_id ?? ''), $row->lokasi_kode, $row->lokasi_nama],
            default => [(string) $row->id, $row->kode, $row->nama],
        };
    }

    /**
     * @param  array<string, int|float>  $sums
     * @param  array<string, int|float>  $figures
     * @return array<string, int|float>
     */
    private function add(array $sums, array $figures): array
    {
        foreach ($figures as $name => $value) {
            $sums[$name] = ($sums[$name] ?? 0) + $value;
        }

        return $sums;
    }

    /**
     * Rasio dari jumlah, dibulatkan dua desimal. Availability kosong bila total waktu nol.
     *
     * @param  array<string, int|float>  $sums
     * @return array<string, mixed>
     */
    private function ratios(array $sums): array
    {
        $total = (float) ($sums['total_jam'] ?? 0);
        $down = min((float) ($sums['downtime_jam'] ?? 0), $total);
        $faults = (int) ($sums['jumlah_kerusakan'] ?? 0);
        $repair = (float) ($sums['jam_perbaikan'] ?? 0);

        return [
            'jumlah_aset' => (int) ($sums['jumlah_aset'] ?? 0),
            'total_jam' => round($total, 2),
            'downtime_jam' => round($down, 2),
            'uptime_jam' => round($total - $down, 2),
            'availability_persen' => $total > 0 ? round(($total - $down) / $total * 100, 2) : null,
            'jumlah_henti' => (int) ($sums['jumlah_henti'] ?? 0),
            'jumlah_kerusakan' => $faults,
            'mtbf_jam' => round($faults > 0 ? $total / $faults : $total, 2),
            'jam_perbaikan' => round($repair, 2),
            'mttr_jam' => round($faults > 0 ? $repair / $faults : $repair, 2),
            'wo_selesai' => (int) ($sums['wo_selesai'] ?? 0),
        ];
    }
}
