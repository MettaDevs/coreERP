<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Reporting\Definitions;

use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\DepreciationPeriod;
use Modules\Apperp\ManagementAset\Reporting\AssetReportFilters;
use Modules\Apperp\ManagementAset\Reporting\AssetSpecification;
use Modules\Apperp\ManagementAset\Reporting\Layouts\BuiltinLayout;
use Modules\Apperp\ManagementAset\Reporting\ReportContext;
use Modules\Apperp\ManagementAset\Reporting\ReportData;
use Modules\Apperp\ManagementAset\Reporting\ReportDefinition;
use Modules\Apperp\ManagementAset\Services\DepreciationCalculator;
use Modules\Apperp\ManagementAset\Services\KalenderFiskalAset;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;
use stdClass;

/**
 * Laporan penyusutan aset satu bulan: satu baris per buku aset, seperti Fixed Asset Book
 * Value 01 di Business Central.
 *
 * Semua angka dibaca dari catatan buku aset, tidak dihitung ulang:
 *
 * - akumulasi = akumulasi saldo awal aset lama + periode penyusutan **final** sampai akhir
 *   bulan laporan. Baris pembalik sudah tersimpan negatif, jadi ikut dijumlah apa adanya.
 *   Ini persamaan yang sama dengan `accumulated_depreciation` pada buku asetnya.
 * - penyusutan bulan ini dan tahun berjalan = periode final dalam rentang itu. Tahun
 *   berjalan mengikuti kalender fiskal entitas legal asetnya; tanpa kalender, tahun kalender.
 * - umur berjalan = periode yang sudah disusutkan sistem lama + periode final bersih, seperti
 *   "Life remaining" buku aset D365 yang berkurang setiap penyusutan di-post.
 *
 * Usulan yang belum difinalkan tidak ikut, dan buku yang belum pernah disusutkan tampil
 * dengan akumulasi saldo awalnya saja, bukan perkiraan. Angka rencana adalah laporan lain —
 * padanannya Fixed Asset Projected Value di BC — yang memakai `DepreciationCalculator`.
 *
 * Aset yang diperoleh sesudah akhir bulan laporan, dan buku yang ditutup (asetnya dijual
 * atau dimusnahkan) sebelum bulan laporan dimulai, tidak ikut — aturan yang sama dengan BC.
 *
 * Nilai uang, angka, persen, dan bulan dikirim mentah dengan `type` di `fields()`; Core yang
 * memformatnya, jadi kolom uang tetap angka di Excel.
 */
final class AssetDepreciationReport implements ReportDefinition
{
    private const STRAIGHT_LINE = ['straight_line', 'straight_line_life_remaining'];

    public function code(): string
    {
        return 'laporan-penyusutan-aset';
    }

    public function name(): string
    {
        return 'Laporan penyusutan aset';
    }

    public function description(): string
    {
        return 'Penyusutan bulan ini, tahun berjalan, akumulasi, dan nilai buku per aset, dari catatan buku aset.';
    }

    public function permission(): string
    {
        return 'management-aset.penyusutan.read';
    }

    public function builtinLayouts(): array
    {
        return [
            new BuiltinLayout('standar', 'Laporan penyusutan aset standar (Excel)', 'Satu baris per buku aset: umur ekonomis, penyusutan, akumulasi, dan nilai buku.', 'xlsx'),
        ];
    }

    public function parameterRules(): array
    {
        return [
            ...AssetReportFilters::rules(),
            'periode' => ['nullable', 'date_format:Y-m'],
        ];
    }

    public function fields(): array
    {
        return [
            ...AssetReportFilters::fields(),
            $this->field('filter_periode', 'Periode laporan', type: 'month'),
            $this->field('total_nilai_perolehan', 'Total nilai perolehan', type: 'money'),
            $this->field('total_penyusutan_bulan_ini', 'Total penyusutan bulan ini', type: 'money'),
            $this->field('total_penyusutan_tahun_berjalan', 'Total penyusutan tahun berjalan', type: 'money'),
            $this->field('total_akumulasi_penyusutan', 'Total akumulasi penyusutan', type: 'money'),
            $this->field('total_nilai_buku_akhir', 'Total nilai buku akhir', type: 'money'),
            $this->field('jumlah_aset', 'Jumlah buku aset'),
            $this->field('dicetak_pada', 'Tanggal cetak', type: 'datetime'),
            $this->field('baris.nomor', 'No.', 'baris'),
            $this->field('baris.kode', 'Kode aset', 'baris'),
            $this->field('baris.nama', 'Nama aset', 'baris'),
            $this->field('baris.spesifikasi', 'Spesifikasi', 'baris'),
            $this->field('baris.group', 'Group aset', 'baris'),
            $this->field('baris.golongan', 'Kelompok harta fiskal', 'baris'),
            $this->field('baris.jenis', 'Jenis aset', 'baris'),
            $this->field('baris.buku', 'Buku penyusutan', 'baris'),
            $this->field('baris.bulan_perolehan', 'Bulan perolehan', 'baris', 'month'),
            $this->field('baris.umur_ekonomis_tahun', 'Umur ekonomis (tahun)', 'baris', 'number'),
            $this->field('baris.umur_ekonomis_bulan', 'Umur ekonomis (bulan)', 'baris', 'number'),
            $this->field('baris.umur_ekonomis_saat_ini', 'Umur berjalan (bulan)', 'baris', 'number'),
            $this->field('baris.sisa_umur_ekonomis_bulan', 'Sisa umur (bulan)', 'baris', 'number'),
            $this->field('baris.persentase_penyusutan', 'Tarif penyusutan per tahun', 'baris', 'percent'),
            $this->field('baris.nilai_perolehan', 'Nilai perolehan', 'baris', 'money'),
            $this->field('baris.penyusutan_bulan_ini', 'Penyusutan bulan ini', 'baris', 'money'),
            $this->field('baris.penyusutan_tahun_berjalan', 'Penyusutan tahun berjalan', 'baris', 'money'),
            $this->field('baris.akumulasi_penyusutan', 'Akumulasi penyusutan', 'baris', 'money'),
            $this->field('baris.nilai_buku_akhir', 'Nilai buku akhir', 'baris', 'money'),
        ];
    }

    public function data(ReportContext $context, array $parameters): ReportData
    {
        // `periode` sudah lolos `date_format:Y-m`, jadi tanggal satunya selalu sah.
        // Periode bawaan bulan ini menurut zona pengguna, bukan menurut jam server yang berjalan dalam UTC.
        $month = Carbon::parse(($parameters['periode'] ?? $context->now()->format('Y-m')).'-01')->startOfDay();
        $monthStart = $month->toDateString();
        $monthEnd = $month->copy()->endOfMonth()->toDateString();

        $books = $this->books($context, $parameters, $monthStart, $monthEnd);
        $yearStarts = $this->fiscalYearStarts($context->tenantId, (clone $books)->distinct()->pluck('aset_tr_aset.legal_entity_id')->all(), $monthEnd);

        $rows = $books
            ->select([
                'aset_tr_aset.kode', 'aset_tr_aset.nama', 'aset_tr_aset.model_number', 'aset_tr_aset.serial_number',
                'aset_tr_aset.acquired_on', 'buku.book_code', 'buku.acquisition_value',
                'buku.opening_accumulated_depreciation', 'buku.elapsed_periods_offset',
                // Masa manfaat dari buku aset bila ada, profil hanya cadangannya — urutan yang sama
                // dengan yang dipakai proposal penyusutan.
                DB::raw('coalesce(buku.useful_life_periods, profil.useful_life_periods) as useful_life_periods'),
                'profil.method', 'profil.frequency', 'profil.rate_percent',
                'master_buku.nama as buku_nama', 'group_aset.nama as group_nama', 'fiskal.label as golongan',
                'jenis.nama as jenis_nama', 'model.nama as model_nama',
            ])
            ->selectSub($this->finalAmount($monthEnd), 'penyusutan_tercatat')
            ->selectSub($this->finalAmount($monthEnd)->where('aset_tr_penyusutan_aset.period_ends_on', '>=', $monthStart), 'penyusutan_bulan_ini')
            ->selectSub($this->yearToDate($monthEnd, $yearStarts), 'penyusutan_tahun_berjalan')
            ->selectSub($this->netFinalPeriods($monthEnd), 'periode_final')
            ->orderBy('aset_tr_aset.kode')
            ->orderBy('buku.book_code')
            ->toBase()
            ->get();

        $calculator = app(DepreciationCalculator::class);
        $totals = array_fill_keys(['nilai_perolehan', 'penyusutan_bulan_ini', 'penyusutan_tahun_berjalan', 'akumulasi_penyusutan', 'nilai_buku_akhir'], BigDecimal::zero());
        $lines = [];

        foreach ($rows as $index => $row) {
            // Masa manfaat dicatat dalam periode profilnya: bulan, triwulan, semester, atau tahun.
            $monthsPerPeriod = intdiv(12, $calculator->periodsPerYear($row->frequency));
            $usefulLife = $row->useful_life_periods === null ? null : (int) $row->useful_life_periods * $monthsPerPeriod;
            $elapsed = ((int) $row->elapsed_periods_offset + (int) $row->periode_final) * $monthsPerPeriod;
            $accumulated = BigDecimal::of((string) $row->opening_accumulated_depreciation)->plus((string) $row->penyusutan_tercatat);
            $amounts = [
                'nilai_perolehan' => BigDecimal::of((string) $row->acquisition_value),
                'penyusutan_bulan_ini' => BigDecimal::of((string) $row->penyusutan_bulan_ini),
                'penyusutan_tahun_berjalan' => BigDecimal::of((string) $row->penyusutan_tahun_berjalan),
                'akumulasi_penyusutan' => $accumulated,
                'nilai_buku_akhir' => BigDecimal::of((string) $row->acquisition_value)->minus($accumulated),
            ];
            foreach ($amounts as $key => $amount) {
                $totals[$key] = $totals[$key]->plus($amount);
            }

            $lines[] = [
                'nomor' => $index + 1,
                'kode' => $row->kode,
                'nama' => $row->nama,
                'spesifikasi' => AssetSpecification::describe($row->model_nama, $row->model_number, $row->serial_number),
                'group' => $row->group_nama ?? '—',
                'golongan' => $row->golongan ?? '—',
                'jenis' => $row->jenis_nama ?? '—',
                'buku' => $row->buku_nama ?? $row->book_code,
                'bulan_perolehan' => substr((string) $row->acquired_on, 0, 7),
                'umur_ekonomis_tahun' => $usefulLife === null ? null : $usefulLife / 12,
                'umur_ekonomis_bulan' => $usefulLife,
                'umur_ekonomis_saat_ini' => $elapsed,
                'sisa_umur_ekonomis_bulan' => $usefulLife === null ? null : max(0, $usefulLife - $elapsed),
                'persentase_penyusutan' => $this->annualRate($row, $usefulLife),
                ...array_map(static fn (BigDecimal $amount): string => (string) $amount, $amounts),
            ];
        }

        return new ReportData(
            fields: [
                ...AssetReportFilters::names($parameters),
                'filter_periode' => $month->format('Y-m'),
                ...array_combine(
                    array_map(static fn (string $key): string => 'total_'.$key, array_keys($totals)),
                    array_map(static fn (BigDecimal $total): string => (string) $total, $totals),
                ),
                'jumlah_aset' => count($lines),
                'dicetak_pada' => now('UTC')->toIso8601ZuluString(),
            ],
            tables: ['baris' => $lines],
            fileName: 'laporan-penyusutan-aset-'.$month->format('Y-m'),
        );
    }

    /**
     * Buku aset yang ikut laporan, sebelum kolomnya dipilih.
     *
     * @param  array<string, mixed>  $parameters
     * @return Builder<Aset>
     */
    private function books(ReportContext $context, array $parameters, string $monthStart, string $monthEnd): Builder
    {
        $query = Aset::query()
            ->join('aset_tr_buku_aset as buku', fn ($join) => $join->on('buku.aset_id', '=', 'aset_tr_aset.id')->on('buku.tenant_id', '=', 'aset_tr_aset.tenant_id'))
            ->leftJoin('aset_m_buku_penyusutan as master_buku', fn ($join) => $join->on('master_buku.id', '=', 'buku.buku_id')->on('master_buku.tenant_id', '=', 'buku.tenant_id'))
            ->leftJoin('aset_m_profil_penyusutan as profil', fn ($join) => $join->on('profil.id', '=', 'buku.depreciation_profile_id')->on('profil.tenant_id', '=', 'buku.tenant_id'))
            ->leftJoin('aset_m_group_aset as group_aset', fn ($join) => $join->on('group_aset.id', '=', 'aset_tr_aset.group_aset_id')->on('group_aset.tenant_id', '=', 'aset_tr_aset.tenant_id'))
            ->leftJoin('aset_m_kelompok_harta_fiskal as fiskal', fn ($join) => $join->on('fiskal.id', '=', 'aset_tr_aset.kelompok_harta_fiskal_id')->on('fiskal.tenant_id', '=', 'aset_tr_aset.tenant_id'))
            ->leftJoin('aset_m_jenis_aset as jenis', fn ($join) => $join->on('jenis.id', '=', 'aset_tr_aset.jenis_aset_id')->on('jenis.tenant_id', '=', 'aset_tr_aset.tenant_id'))
            ->leftJoin('aset_m_model_aset as model', fn ($join) => $join->on('model.id', '=', 'aset_tr_aset.model_aset_id')->on('model.tenant_id', '=', 'aset_tr_aset.tenant_id'))
            ->where('aset_tr_aset.acquired_on', '<=', $monthEnd)
            ->where(fn ($query) => $query->whereNull('buku.closed_on')->orWhere('buku.closed_on', '>=', $monthStart));

        app(OrganizationScope::class)->asetQuery($query, $context->request());

        AssetReportFilters::apply($query, $parameters, 'aset_tr_aset');

        if (! empty($parameters['buku_id'])) {
            $query->where('buku.buku_id', $parameters['buku_id']);
        } else {
            // Tanpa pilihan buku: buku komersial saja. Buku fiskal atau memorandum menyusutkan
            // aset yang sama, dan menjumlahkannya bersama membuat totalnya dobel.
            $query->where(fn ($query) => $query->where('master_buku.posting_layer', 'current')->orWhereNull('buku.buku_id'));
        }

        return $query;
    }

    /** @return Builder<DepreciationPeriod> Jumlah periode final sebuah buku sampai `$until`. */
    private function finalAmount(string $until): Builder
    {
        return DepreciationPeriod::query()
            ->selectRaw('coalesce(sum(aset_tr_penyusutan_aset.amount), 0)')
            ->whereColumn('aset_tr_penyusutan_aset.buku_aset_id', 'buku.id')
            ->where('aset_tr_penyusutan_aset.status', 'final')
            ->where('aset_tr_penyusutan_aset.period_ends_on', '<=', $until);
    }

    /**
     * Penyusutan final sejak awal tahun buku entitas legal asetnya.
     *
     * @param  array<string, string>  $yearStarts  Entitas legal => tanggal awal tahun bukunya.
     * @return Builder<DepreciationPeriod>
     */
    private function yearToDate(string $monthEnd, array $yearStarts): Builder
    {
        $cases = '';
        $bindings = [];
        foreach ($yearStarts as $legalEntityId => $start) {
            $cases .= ' when ? then cast(? as date)';
            array_push($bindings, $legalEntityId, $start);
        }
        $bindings[] = substr($monthEnd, 0, 4).'-01-01';
        $start = $cases === '' ? 'cast(? as date)' : 'case aset_tr_aset.legal_entity_id'.$cases.' else cast(? as date) end';

        return $this->finalAmount($monthEnd)->whereRaw('aset_tr_penyusutan_aset.period_ends_on >= '.$start, $bindings);
    }

    /**
     * Periode final bersih sampai `$until`: periode asli dikurangi yang sudah dibalik.
     *
     * @return Builder<DepreciationPeriod>
     */
    private function netFinalPeriods(string $until): Builder
    {
        return DepreciationPeriod::query()
            ->selectRaw('count(*) filter (where aset_tr_penyusutan_aset.reverses_period_id is null) - count(*) filter (where aset_tr_penyusutan_aset.reverses_period_id is not null)')
            ->whereColumn('aset_tr_penyusutan_aset.buku_aset_id', 'buku.id')
            ->where('aset_tr_penyusutan_aset.status', 'final')
            ->where('aset_tr_penyusutan_aset.period_ends_on', '<=', $until);
    }

    /**
     * Awal tahun buku tiap entitas legal pada akhir bulan laporan. Entitas legal yang belum
     * punya kalender fiskal memakai tahun kalender, seperti penghitung penyusutan.
     *
     * @param  array<int, mixed>  $legalEntityIds
     * @return array<string, string>
     */
    private function fiscalYearStarts(string $tenantId, array $legalEntityIds, string $monthEnd): array
    {
        $calendar = app(KalenderFiskalAset::class);
        $starts = [];
        foreach ($legalEntityIds as $legalEntityId) {
            if (is_string($legalEntityId) && $legalEntityId !== '') {
                $starts[$legalEntityId] = $calendar->resolve($tenantId, $legalEntityId, $monthEnd)['year']['starts_on']
                    ?? substr($monthEnd, 0, 4).'-01-01';
            }
        }

        return $starts;
    }

    /** Tarif setahun: dari profil untuk saldo menurun, 100 ÷ umur (tahun) untuk garis lurus. */
    private function annualRate(stdClass $row, ?int $usefulLifeMonths): ?float
    {
        if ($row->method === 'reducing_balance') {
            return $row->rate_percent === null ? null : (float) $row->rate_percent;
        }

        return in_array($row->method, self::STRAIGHT_LINE, true) && $usefulLifeMonths > 0
            ? 1200 / $usefulLifeMonths
            : null;
    }

    /** @return array{key: string, label: string, table: ?string, type?: string} */
    private function field(string $key, string $label, ?string $table = null, ?string $type = null): array
    {
        $field = ['key' => $key, 'label' => $label, 'table' => $table];
        if ($type !== null) {
            $field['type'] = $type;
        }

        return $field;
    }
}
