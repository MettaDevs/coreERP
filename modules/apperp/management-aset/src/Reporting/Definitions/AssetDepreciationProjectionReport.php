<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Reporting\Definitions;

use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\BukuAset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\DepreciationPeriod;
use Modules\Apperp\ManagementAset\Reporting\AdditionalFilters;
use Modules\Apperp\ManagementAset\Reporting\AssetReportFilters;
use Modules\Apperp\ManagementAset\Reporting\Layouts\BuiltinLayout;
use Modules\Apperp\ManagementAset\Reporting\ReportContext;
use Modules\Apperp\ManagementAset\Reporting\ReportData;
use Modules\Apperp\ManagementAset\Reporting\ReportDataException;
use Modules\Apperp\ManagementAset\Reporting\ReportDataItem;
use Modules\Apperp\ManagementAset\Reporting\ReportDefinition;
use Modules\Apperp\ManagementAset\Services\DepreciationCalculator;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;
use stdClass;

/**
 * Proyeksi penyusutan per periode ke depan, satu baris per buku aset per periode, padanan *Fixed Asset -
 * Projected Value* Business Central.
 *
 * Angkanya dihitung dengan `DepreciationCalculator` yang sama dengan proposal penyusutan — termasuk perpindahan
 * ke profil alternatif — sehingga proyeksi dan usulan yang kelak dibuat tidak pernah berbeda. Proyeksi berjalan
 * dari keadaan buku aset sekarang: mulai dari periode sesudah periode final terakhir (usulan yang belum
 * difinalkan ikut dihitung ulang), dan setiap periode mengurangi nilai buku yang dipakai periode berikutnya.
 * Periode sebelum rentang laporan tetap dihitung supaya periode di dalam rentang benar, tetapi tidak tampil.
 *
 * Periode mengikuti frekuensi profil dan berakhir pada akhir bulan, kuartal, semester, atau tahun kalender. Buku
 * yang tidak disusutkan, sudah ditutup, atau berprofil konsumsi (nilainya baru diketahui dari pemakaian) tidak
 * diproyeksikan.
 */
final class AssetDepreciationProjectionReport implements ReportDefinition
{
    /** Rentang terpanjang, dalam bulan, supaya satu permintaan tidak menghitung ratusan periode per aset. */
    private const MAX_MONTHS = 60;

    public function code(): string
    {
        return 'laporan-proyeksi-penyusutan-aset';
    }

    public function name(): string
    {
        return 'Proyeksi penyusutan aset';
    }

    public function description(): string
    {
        return 'Penyusutan per periode ke depan, beserta akumulasi dan nilai buku sesudahnya, dari keadaan buku aset sekarang.';
    }

    public function permission(): string
    {
        return 'management-aset.penyusutan.read';
    }

    public function builtinLayouts(): array
    {
        return [
            new BuiltinLayout('standar', 'Proyeksi penyusutan aset standar (Excel)', 'Satu baris per aset per periode: penyusutan, akumulasi, dan nilai buku sesudah periode itu.', 'xlsx'),
        ];
    }

    public function parameterRules(): array
    {
        return [
            ...AssetReportFilters::rules(),
            'dari' => ['nullable', 'date_format:Y-m'],
            'sampai' => ['nullable', 'date_format:Y-m'],
        ];
    }

    public function dataItems(): array
    {
        return [
            new ReportDataItem('aset', 'Aset', Aset::class, 'aset_tr_aset', ['kode']),
            new ReportDataItem('buku', 'Buku aset', BukuAset::class, 'buku'),
        ];
    }

    public function fields(): array
    {
        return [
            ...AssetReportFilters::fields(),
            ['key' => 'filter_dari', 'label' => 'Dari bulan', 'table' => null, 'type' => 'month'],
            ['key' => 'filter_sampai', 'label' => 'Sampai bulan', 'table' => null, 'type' => 'month'],
            ['key' => 'jumlah_aset', 'label' => 'Jumlah buku aset', 'table' => null],
            ['key' => 'total_penyusutan', 'label' => 'Total proyeksi penyusutan', 'table' => null, 'type' => 'money'],
            ['key' => 'dicetak_pada', 'label' => 'Tanggal cetak', 'table' => null, 'type' => 'datetime'],
            ['key' => 'baris.nomor', 'label' => 'No.', 'table' => 'baris'],
            ['key' => 'baris.kode', 'label' => 'Kode aset', 'table' => 'baris'],
            ['key' => 'baris.nama', 'label' => 'Nama aset', 'table' => 'baris'],
            ['key' => 'baris.group', 'label' => 'Group aset', 'table' => 'baris'],
            ['key' => 'baris.buku', 'label' => 'Buku penyusutan', 'table' => 'baris'],
            ['key' => 'baris.periode', 'label' => 'Periode', 'table' => 'baris', 'type' => 'month'],
            ['key' => 'baris.akhir_periode', 'label' => 'Akhir periode', 'table' => 'baris', 'type' => 'date'],
            ['key' => 'baris.penyusutan', 'label' => 'Penyusutan', 'table' => 'baris', 'type' => 'money'],
            ['key' => 'baris.akumulasi_penyusutan', 'label' => 'Akumulasi penyusutan', 'table' => 'baris', 'type' => 'money'],
            ['key' => 'baris.nilai_buku', 'label' => 'Nilai buku', 'table' => 'baris', 'type' => 'money'],
        ];
    }

    public function data(ReportContext $context, array $parameters): ReportData
    {
        $dari = Carbon::parse(($parameters['dari'] ?? $context->now()->format('Y-m')).'-01')->startOfDay();
        $sampai = Carbon::parse(($parameters['sampai'] ?? $dari->copy()->addMonthsNoOverflow(11)->format('Y-m')).'-01')->endOfMonth()->startOfDay();
        if ($sampai->lessThan($dari)) {
            throw new ReportDataException('Bulan akhir proyeksi tidak boleh sebelum bulan awalnya.');
        }
        if ($dari->diffInMonths($sampai) >= self::MAX_MONTHS) {
            throw new ReportDataException(sprintf('Proyeksi paling panjang %d bulan. Persempit rentangnya.', self::MAX_MONTHS));
        }

        $calculator = app(DepreciationCalculator::class);
        $total = BigDecimal::zero();
        $lines = [];
        $books = [];
        foreach ($this->books($context, $parameters)->toBase()->cursor() as $book) {
            foreach ($this->project($calculator, $book, $dari, $sampai) as $periode) {
                $total = $total->plus($periode['penyusutan']);
                $books[(string) $book->id] = true;
                $lines[] = [
                    'nomor' => count($lines) + 1,
                    'kode' => $book->aset_kode,
                    'nama' => $book->aset_nama,
                    'group' => $book->group_nama ?? '—',
                    'buku' => $book->buku_nama ?? $book->book_code,
                    'periode' => $periode['akhir']->format('Y-m'),
                    'akhir_periode' => $periode['akhir']->toDateString(),
                    'penyusutan' => (string) $periode['penyusutan'],
                    'akumulasi_penyusutan' => (string) $periode['akumulasi'],
                    'nilai_buku' => (string) $periode['nilai_buku'],
                ];
            }
        }

        return new ReportData(
            fields: [
                ...AssetReportFilters::names($parameters),
                'filter_dari' => $dari->format('Y-m'),
                'filter_sampai' => $sampai->format('Y-m'),
                'jumlah_aset' => count($books),
                'total_penyusutan' => (string) $total,
                'dicetak_pada' => now('UTC')->toIso8601ZuluString(),
            ],
            tables: ['baris' => $lines],
            fileName: 'proyeksi-penyusutan-aset-'.$dari->format('Y-m').'-'.$sampai->format('Y-m'),
        );
    }

    /**
     * Periode yang diproyeksikan untuk satu buku, dalam rentang laporan.
     *
     * @return list<array{akhir: Carbon, penyusutan: BigDecimal, akumulasi: BigDecimal, nilai_buku: BigDecimal}>
     */
    private function project(DepreciationCalculator $calculator, stdClass $book, Carbon $dari, Carbon $sampai): array
    {
        $bulanPerPeriode = intdiv(12, $calculator->periodsPerYear($book->frequency));
        $mulai = $book->terakhir_final !== null
            ? Carbon::parse((string) $book->terakhir_final)->startOfDay()->addDay()
            : Carbon::parse((string) ($book->depreciation_start_on ?? $dari->toDateString()))->startOfDay();
        $akhir = $this->periodEnd($mulai, $bulanPerPeriode);
        $berjalan = (int) $book->elapsed_periods_offset + (int) $book->periode_asli;
        $periode = [];
        while ($akhir->lessThanOrEqualTo($sampai)) {
            $calculator->applyAlternativeProfile($book, $berjalan);
            // Jadwal manual yang sudah habis tidak menyusutkan lagi; proposal menolaknya dengan pesan.
            $nilai = $book->method === 'manual' && $berjalan >= count($this->schedule($book->manual_schedule) ?? [])
                ? 0.0
                : $calculator->amount($book, $berjalan);
            $penyusutan = BigDecimal::of((string) $nilai)->toScale(2);
            $book->net_book_value = (string) BigDecimal::of((string) $book->net_book_value)->minus($penyusutan);
            $book->accumulated_depreciation = (string) BigDecimal::of((string) $book->accumulated_depreciation)->plus($penyusutan);
            // Periode tanpa penyusutan — aset sudah habis disusutkan — tidak ditampilkan.
            if ($akhir->greaterThanOrEqualTo($dari) && ! $penyusutan->isZero()) {
                $periode[] = [
                    'akhir' => $akhir->copy(),
                    'penyusutan' => $penyusutan,
                    'akumulasi' => BigDecimal::of((string) $book->accumulated_depreciation),
                    'nilai_buku' => BigDecimal::of((string) $book->net_book_value),
                ];
            }
            $berjalan++;
            $akhir = $this->periodEnd($akhir->copy()->addDay(), $bulanPerPeriode);
        }

        return $periode;
    }

    /** Akhir periode yang memuat `$tanggal`: akhir bulan, kuartal, semester, atau tahun kalender. */
    private function periodEnd(Carbon $tanggal, int $bulanPerPeriode): Carbon
    {
        $bulan = (int) (ceil($tanggal->month / $bulanPerPeriode) * $bulanPerPeriode);

        return $tanggal->copy()->setDate($tanggal->year, $bulan, 1)->endOfMonth()->startOfDay();
    }

    /** @return list<mixed>|null */
    private function schedule(mixed $schedule): ?array
    {
        $rows = is_string($schedule) ? json_decode($schedule, true) : $schedule;

        return is_array($rows) ? array_values($rows) : null;
    }

    /**
     * Buku aktif yang disusutkan, dengan profil, umur yang sudah berjalan, dan periode final terakhirnya.
     *
     * @param  array<string, mixed>  $parameters
     * @return Builder<Aset>
     */
    private function books(ReportContext $context, array $parameters): Builder
    {
        $query = Aset::query()
            ->join('aset_tr_buku_aset as buku', fn (JoinClause $join) => $join->on('buku.aset_id', '=', 'aset_tr_aset.id')->on('buku.tenant_id', '=', 'aset_tr_aset.tenant_id'))
            ->join('aset_m_profil_penyusutan as profil', fn (JoinClause $join) => $join->on('profil.id', '=', 'buku.depreciation_profile_id')->on('profil.tenant_id', '=', 'buku.tenant_id'))
            ->leftJoin('aset_m_buku_penyusutan as master_buku', fn (JoinClause $join) => $join->on('master_buku.id', '=', 'buku.buku_id')->on('master_buku.tenant_id', '=', 'buku.tenant_id'))
            ->leftJoin('aset_m_group_aset as group_aset', fn (JoinClause $join) => $join->on('group_aset.id', '=', 'aset_tr_aset.group_aset_id')->on('group_aset.tenant_id', '=', 'aset_tr_aset.tenant_id'))
            ->where('buku.status', 'active')
            ->where('buku.depreciate', true)
            ->where('profil.method', '!=', 'consumption');

        app(OrganizationScope::class)->asetQuery($query, $context->request());
        AssetReportFilters::apply($query, $parameters, 'aset_tr_aset');
        foreach ($this->dataItems() as $item) {
            AdditionalFilters::apply($query, $item, $parameters, $context);
        }
        if (! empty($parameters['buku_id'])) {
            $query->where('buku.buku_id', $parameters['buku_id']);
        } else {
            $query->where(fn ($query) => $query->where('master_buku.posting_layer', 'current')->orWhereNull('buku.buku_id'));
        }

        // Sama dengan proposal penyusutan: periode asli yang sudah final dihitung sebagai umur berjalan, juga
        // yang sudah dibalik, karena tanggal akhirnya tidak dapat diusulkan lagi.
        $periodeAsli = DepreciationPeriod::query()
            ->whereColumn('aset_tr_penyusutan_aset.buku_aset_id', 'buku.id')
            ->whereNull('aset_tr_penyusutan_aset.reverses_period_id')
            ->where('aset_tr_penyusutan_aset.status', 'final');

        return $query
            ->select(
                'buku.*',
                'profil.method', 'profil.frequency', 'profil.rate_percent', 'profil.manual_schedule',
                DB::raw('coalesce(buku.useful_life_periods, profil.useful_life_periods) as useful_life_periods'),
                'aset_tr_aset.kode as aset_kode', 'aset_tr_aset.nama as aset_nama',
                'master_buku.nama as buku_nama', 'group_aset.nama as group_nama',
            )
            ->selectSub((clone $periodeAsli)->selectRaw('count(*)'), 'periode_asli')
            ->selectSub((clone $periodeAsli)->selectRaw('max(aset_tr_penyusutan_aset.period_ends_on)'), 'terakhir_final')
            ->orderBy('aset_tr_aset.kode')
            ->orderBy('buku.book_code');
    }
}
