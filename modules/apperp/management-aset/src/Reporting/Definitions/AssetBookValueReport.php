<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Reporting\Definitions;

use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\BukuAset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\DepreciationPeriod;
use Modules\Apperp\ManagementAset\Models\transaksi\Reclassification\AssetReclassificationBook;
use Modules\Apperp\ManagementAset\Models\transaksi\ValueAdjustment\AssetValueAdjustment;
use Modules\Apperp\ManagementAset\Models\transaksi\ValueAdjustment\AssetValueAdjustmentLine;
use Modules\Apperp\ManagementAset\Reporting\AdditionalFilters;
use Modules\Apperp\ManagementAset\Reporting\AssetReportFilters;
use Modules\Apperp\ManagementAset\Reporting\Layouts\BuiltinLayout;
use Modules\Apperp\ManagementAset\Reporting\ReportContext;
use Modules\Apperp\ManagementAset\Reporting\ReportData;
use Modules\Apperp\ManagementAset\Reporting\ReportDataItem;
use Modules\Apperp\ManagementAset\Reporting\ReportDefinition;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;
use stdClass;

/**
 * Mutasi nilai buku aset dalam rentang tanggal, satu baris per buku aset: saldo awal, perolehan, penyusutan,
 * penurunan dan kenaikan nilai, reklasifikasi masuk dan keluar, pelepasan, dan saldo akhir — untuk harga
 * perolehan, akumulasi penyusutan, dan nilai buku. Padanan *Fixed Asset - Book Value 01/02* Business Central.
 *
 * Register aset tidak menyimpan buku besar per transaksi seperti FA Ledger Entry BC, jadi mutasinya disusun
 * dari catatan yang membentuk saldo buku aset, masing-masing pada tanggalnya:
 *
 * - **perolehan** — harga perolehan dasar pada tanggal perolehan aset: harga perolehan buku sekarang dikurangi
 *   yang masuk lewat reklasifikasi dan ditambah yang keluar. Koreksi nilai perolehan hanya boleh sebelum ada
 *   penyusutan, jadi ia bagian dari perolehan itu. Aset pecahan tidak punya perolehan sendiri; nilainya masuk
 *   lewat reklasifikasi. Akumulasi saldo awal aset lama dicatat bersama perolehannya;
 * - **penyusutan** — periode penyusutan **final** menurut tanggal akhir periodenya, termasuk baris pembalik
 *   yang tersimpan negatif. Usulan yang belum difinalkan tidak ikut;
 * - **penurunan dan kenaikan nilai** — baris dokumen penyesuaian nilai yang sudah diposting;
 * - **reklasifikasi** — pemindahan per buku yang dicatat saat reklasifikasi diposting. Pindah group tidak
 *   mengubah saldo buku asetnya, jadi tidak tampil di sini;
 * - **pelepasan** — seluruh saldo buku yang ditutup pada tanggal tutupnya (penjualan atau pemusnahan).
 *
 * Persamaannya: nilai buku akhir = awal + perolehan − penyusutan − penurunan + kenaikan + reklasifikasi masuk
 * − keluar − pelepasan, dan saldo akhir pada hari ini sama dengan saldo buku aset di register.
 *
 * Tanpa pilihan buku, hanya buku komersial yang dibaca, seperti laporan penyusutan; menjumlahkan buku fiskal
 * bersama membuat totalnya dobel. Buku yang tidak punya saldo maupun mutasi dalam rentang tidak ditampilkan.
 */
final class AssetBookValueReport implements ReportDefinition
{
    /** Komponen saldo buku aset dan kolomnya di pemindahan reklasifikasi. */
    private const RECLASS = ['acq' => 'nilai_perolehan', 'acm' => 'akumulasi_penyusutan', 'wd' => 'penurunan_nilai', 'ap' => 'kenaikan_nilai'];

    /** Kolom uang baris, dalam urutan tampil. */
    private const MONEY = [
        'harga_perolehan_awal', 'perolehan', 'reklasifikasi_harga_perolehan', 'pelepasan_harga_perolehan', 'harga_perolehan_akhir',
        'akumulasi_awal', 'penyusutan', 'reklasifikasi_akumulasi', 'pelepasan_akumulasi', 'akumulasi_akhir',
        'nilai_buku_awal', 'penurunan_nilai', 'kenaikan_nilai', 'reklasifikasi_masuk', 'reklasifikasi_keluar', 'pelepasan', 'nilai_buku_akhir',
    ];

    public function code(): string
    {
        return 'laporan-nilai-buku-aset';
    }

    public function name(): string
    {
        return 'Laporan mutasi nilai buku aset';
    }

    public function description(): string
    {
        return 'Saldo awal, perolehan, penyusutan, penyesuaian nilai, reklasifikasi, pelepasan, dan saldo akhir per aset dalam rentang tanggal.';
    }

    public function permission(): string
    {
        return 'management-aset.penyusutan.read';
    }

    public function builtinLayouts(): array
    {
        return [
            new BuiltinLayout('standar', 'Mutasi nilai buku aset standar (Excel)', 'Satu baris per buku aset: harga perolehan, akumulasi penyusutan, dan nilai buku dari saldo awal sampai saldo akhir.', 'xlsx'),
        ];
    }

    public function parameterRules(): array
    {
        return [
            ...AssetReportFilters::rules(),
            'dari' => ['nullable', 'date_format:Y-m-d'],
            'sampai' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:dari'],
        ];
    }

    /** Aset lalu buku asetnya, seperti Fixed Asset lalu FA Depreciation Book di laporan Book Value BC. */
    public function dataItems(): array
    {
        return [
            new ReportDataItem('aset', 'Aset', Aset::class, 'aset_tr_aset', ['kode']),
            new ReportDataItem('buku', 'Buku aset', BukuAset::class, 'buku'),
        ];
    }

    public function fields(): array
    {
        $fields = [
            ...AssetReportFilters::fields(),
            ['key' => 'filter_dari', 'label' => 'Dari tanggal', 'table' => null, 'type' => 'date'],
            ['key' => 'filter_sampai', 'label' => 'Sampai tanggal', 'table' => null, 'type' => 'date'],
            ['key' => 'jumlah_aset', 'label' => 'Jumlah buku aset', 'table' => null],
            ['key' => 'dicetak_pada', 'label' => 'Tanggal cetak', 'table' => null, 'type' => 'datetime'],
        ];
        foreach (self::MONEY as $key) {
            $fields[] = ['key' => 'total_'.$key, 'label' => 'Total '.$this->label($key), 'table' => null, 'type' => 'money'];
        }
        array_push(
            $fields,
            ['key' => 'baris.nomor', 'label' => 'No.', 'table' => 'baris'],
            ['key' => 'baris.kode', 'label' => 'Kode aset', 'table' => 'baris'],
            ['key' => 'baris.nama', 'label' => 'Nama aset', 'table' => 'baris'],
            ['key' => 'baris.group', 'label' => 'Group aset', 'table' => 'baris'],
            ['key' => 'baris.buku', 'label' => 'Buku penyusutan', 'table' => 'baris'],
            ['key' => 'baris.tanggal_perolehan', 'label' => 'Tanggal perolehan', 'table' => 'baris', 'type' => 'date'],
        );
        foreach (self::MONEY as $key) {
            $fields[] = ['key' => 'baris.'.$key, 'label' => $this->label($key), 'table' => 'baris', 'type' => 'money'];
        }

        return $fields;
    }

    public function data(ReportContext $context, array $parameters): ReportData
    {
        $today = $context->now()->toDateString();
        $dari = is_string($parameters['dari'] ?? null) && $parameters['dari'] !== '' ? $parameters['dari'] : Carbon::parse($today)->startOfYear()->toDateString();
        $sampai = is_string($parameters['sampai'] ?? null) && $parameters['sampai'] !== '' ? $parameters['sampai'] : $today;

        $rows = $this->books($context, $parameters, $dari)
            ->leftJoinLateral($this->depreciation($dari, $sampai), 'dep')
            ->leftJoinLateral($this->valueAdjustments($dari, $sampai), 'pn')
            ->leftJoinLateral($this->reclassifications('buku_aset_tujuan_id', $dari, $sampai), 'rin')
            ->leftJoinLateral($this->reclassifications('buku_aset_asal_id', $dari, $sampai), 'rout')
            ->select([
                'aset_tr_aset.kode', 'aset_tr_aset.nama', 'aset_tr_aset.acquired_on', 'buku.book_code',
                'buku.acquisition_value', 'buku.accumulated_depreciation', 'buku.write_down_amount', 'buku.appreciation_amount',
                'buku.opening_accumulated_depreciation', 'buku.closed_on',
                'master_buku.nama as buku_nama', 'group_aset.nama as group_nama',
                'dep.*', 'pn.*',
                ...$this->reclassColumns('rin', 'in'),
                ...$this->reclassColumns('rout', 'out'),
            ])
            ->orderBy('aset_tr_aset.kode')
            ->orderBy('buku.book_code')
            ->toBase()
            ->get();

        $totals = array_fill_keys(self::MONEY, BigDecimal::zero());
        $lines = [];
        foreach ($rows as $row) {
            $amounts = $this->amounts($row, $dari, $sampai);
            if ($amounts === null) {
                continue;
            }
            foreach ($amounts as $key => $amount) {
                $totals[$key] = $totals[$key]->plus($amount);
            }
            $lines[] = [
                'nomor' => count($lines) + 1,
                'kode' => $row->kode,
                'nama' => $row->nama,
                'group' => $row->group_nama ?? '—',
                'buku' => $row->buku_nama ?? $row->book_code,
                'tanggal_perolehan' => substr((string) $row->acquired_on, 0, 10),
                ...array_map(static fn (BigDecimal $amount): string => (string) $amount, $amounts),
            ];
        }

        return new ReportData(
            fields: [
                ...AssetReportFilters::names($parameters),
                'filter_dari' => $dari,
                'filter_sampai' => $sampai,
                'jumlah_aset' => count($lines),
                'dicetak_pada' => now('UTC')->toIso8601ZuluString(),
                ...array_combine(
                    array_map(static fn (string $key): string => 'total_'.$key, array_keys($totals)),
                    array_map(static fn (BigDecimal $total): string => (string) $total, $totals),
                ),
            ],
            tables: ['baris' => $lines],
            fileName: 'laporan-mutasi-nilai-buku-aset-'.$dari.'-'.$sampai,
        );
    }

    /**
     * Mutasi satu buku aset, atau `null` bila buku itu tidak punya saldo maupun mutasi dalam rentang.
     *
     * @return array<string, BigDecimal>|null
     */
    private function amounts(stdClass $row, string $dari, string $sampai): ?array
    {
        $d = static fn (mixed $value): BigDecimal => BigDecimal::of($value === null ? '0' : (string) $value);
        $tutup = $row->closed_on === null ? null : substr((string) $row->closed_on, 0, 10);
        if ($tutup !== null && $tutup < $dari) {
            return null;
        }
        $diperoleh = substr((string) $row->acquired_on, 0, 10);
        // Harga perolehan dasar: tanpa yang masuk dan keluar lewat reklasifikasi, kapan pun.
        $dasar = $d($row->acquisition_value)->minus($d($row->in_all_acq))->plus($d($row->out_all_acq));
        $sebelum = $diperoleh < $dari;
        $dalam = ! $sebelum && $diperoleh <= $sampai;
        $saldoAwal = $d($row->opening_accumulated_depreciation);

        $awal = [
            'acq' => ($sebelum ? $dasar : BigDecimal::zero())->plus($d($row->in_before_acq))->minus($d($row->out_before_acq)),
            'acm' => ($sebelum ? $saldoAwal : BigDecimal::zero())->plus($d($row->dep_before))->plus($d($row->in_before_acm))->minus($d($row->out_before_acm)),
            'wd' => $d($row->wd_before)->plus($d($row->in_before_wd))->minus($d($row->out_before_wd)),
            'ap' => $d($row->ap_before)->plus($d($row->in_before_ap))->minus($d($row->out_before_ap)),
        ];
        $masuk = [];
        $keluar = [];
        foreach (array_keys(self::RECLASS) as $k) {
            $masuk[$k] = $d($row->{'in_range_'.$k});
            $keluar[$k] = $d($row->{'out_range_'.$k});
        }
        $perolehan = $dalam ? $dasar : BigDecimal::zero();
        $penyusutan = $d($row->dep_range)->plus($dalam ? $saldoAwal : BigDecimal::zero());
        $turun = $d($row->wd_range);
        $naik = $d($row->ap_range);
        // Sebelum pelepasan, saldo setiap komponen pada tanggal tutup = saldo buku sekarang: sesudah ditutup,
        // tidak ada yang mengubahnya lagi.
        $lepas = $tutup !== null && $tutup <= $sampai
            ? ['acq' => $d($row->acquisition_value), 'acm' => $d($row->accumulated_depreciation), 'wd' => $d($row->write_down_amount), 'ap' => $d($row->appreciation_amount)]
            : array_fill_keys(array_keys(self::RECLASS), BigDecimal::zero());

        $nilaiBuku = static fn (array $saldo): BigDecimal => $saldo['acq']->minus($saldo['acm'])->minus($saldo['wd'])->plus($saldo['ap']);
        $akhir = [
            'acq' => $awal['acq']->plus($perolehan)->plus($masuk['acq'])->minus($keluar['acq'])->minus($lepas['acq']),
            'acm' => $awal['acm']->plus($penyusutan)->plus($masuk['acm'])->minus($keluar['acm'])->minus($lepas['acm']),
            'wd' => $awal['wd']->plus($turun)->plus($masuk['wd'])->minus($keluar['wd'])->minus($lepas['wd']),
            'ap' => $awal['ap']->plus($naik)->plus($masuk['ap'])->minus($keluar['ap'])->minus($lepas['ap']),
        ];

        $amounts = [
            'harga_perolehan_awal' => $awal['acq'],
            'perolehan' => $perolehan,
            'reklasifikasi_harga_perolehan' => $masuk['acq']->minus($keluar['acq']),
            'pelepasan_harga_perolehan' => $lepas['acq'],
            'harga_perolehan_akhir' => $akhir['acq'],
            'akumulasi_awal' => $awal['acm'],
            'penyusutan' => $penyusutan,
            'reklasifikasi_akumulasi' => $masuk['acm']->minus($keluar['acm']),
            'pelepasan_akumulasi' => $lepas['acm'],
            'akumulasi_akhir' => $akhir['acm'],
            'nilai_buku_awal' => $nilaiBuku($awal),
            'penurunan_nilai' => $turun,
            'kenaikan_nilai' => $naik,
            'reklasifikasi_masuk' => $nilaiBuku($masuk),
            'reklasifikasi_keluar' => $nilaiBuku($keluar),
            'pelepasan' => $nilaiBuku($lepas),
            'nilai_buku_akhir' => $nilaiBuku($akhir),
        ];
        $kosong = array_filter($amounts, static fn (BigDecimal $amount): bool => ! $amount->isZero()) === [] && $awal['acq']->isZero();

        return $kosong ? null : $amounts;
    }

    /**
     * Buku aset yang ikut laporan, sebelum kolomnya dipilih.
     *
     * @param  array<string, mixed>  $parameters
     * @return Builder<Aset>
     */
    private function books(ReportContext $context, array $parameters, string $dari): Builder
    {
        $query = Aset::query()
            ->join('aset_tr_buku_aset as buku', fn (JoinClause $join) => $join->on('buku.aset_id', '=', 'aset_tr_aset.id')->on('buku.tenant_id', '=', 'aset_tr_aset.tenant_id'))
            ->leftJoin('aset_m_buku_penyusutan as master_buku', fn (JoinClause $join) => $join->on('master_buku.id', '=', 'buku.buku_id')->on('master_buku.tenant_id', '=', 'buku.tenant_id'))
            ->leftJoin('aset_m_group_aset as group_aset', fn (JoinClause $join) => $join->on('group_aset.id', '=', 'aset_tr_aset.group_aset_id')->on('group_aset.tenant_id', '=', 'aset_tr_aset.tenant_id'))
            // Buku yang sudah ditutup sebelum rentang tidak punya saldo maupun mutasi.
            ->where(fn ($query) => $query->whereNull('buku.closed_on')->orWhere('buku.closed_on', '>=', $dari));

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

        return $query;
    }

    /** @return Builder<DepreciationPeriod> Penyusutan final sebelum dan di dalam rentang, satu baris per buku. */
    private function depreciation(string $dari, string $sampai): Builder
    {
        return DepreciationPeriod::query()
            ->selectRaw('coalesce(sum(amount) filter (where period_ends_on < ?), 0) as dep_before', [$dari])
            ->selectRaw('coalesce(sum(amount) filter (where period_ends_on between ? and ?), 0) as dep_range', [$dari, $sampai])
            ->whereColumn('aset_tr_penyusutan_aset.buku_aset_id', 'buku.id')
            ->where('aset_tr_penyusutan_aset.status', 'final');
    }

    /** @return Builder<AssetValueAdjustmentLine> Penurunan dan kenaikan nilai yang sudah diposting, satu baris per buku. */
    private function valueAdjustments(string $dari, string $sampai): Builder
    {
        $kolom = 'aset_tr_penyesuaian_nilai_aset_details.nilai';
        $query = AssetValueAdjustmentLine::query()
            ->join('aset_tr_penyesuaian_nilai_aset as dokumen', fn (JoinClause $join) => $join->on('dokumen.id', '=', 'aset_tr_penyesuaian_nilai_aset_details.penyesuaian_nilai_aset_id')->on('dokumen.tenant_id', '=', 'aset_tr_penyesuaian_nilai_aset_details.tenant_id'))
            ->whereColumn('aset_tr_penyesuaian_nilai_aset_details.aset_id', 'buku.aset_id')
            ->whereColumn('dokumen.buku_id', 'buku.buku_id')
            ->where('dokumen.status', AssetValueAdjustment::POSTED)
            ->whereNull('dokumen.deleted_at');
        foreach (['wd' => AssetValueAdjustment::WRITE_DOWN, 'ap' => AssetValueAdjustment::APPRECIATION] as $alias => $jenis) {
            $query->selectRaw("coalesce(sum({$kolom}) filter (where dokumen.jenis = ? and dokumen.tanggal < ?), 0) as {$alias}_before", [$jenis, $dari]);
            $query->selectRaw("coalesce(sum({$kolom}) filter (where dokumen.jenis = ? and dokumen.tanggal between ? and ?), 0) as {$alias}_range", [$jenis, $dari, $sampai]);
        }

        return $query;
    }

    /**
     * Pemindahan reklasifikasi yang masuk ke (`buku_aset_tujuan_id`) atau keluar dari (`buku_aset_asal_id`) buku
     * aset ini: sebelum rentang, di dalam rentang, dan sepanjang waktu (untuk harga perolehan dasar). Pindah
     * group — buku asal dan tujuannya sama — tidak ikut.
     *
     * @return Builder<AssetReclassificationBook>
     */
    private function reclassifications(string $column, string $dari, string $sampai): Builder
    {
        $query = AssetReclassificationBook::query()
            ->whereColumn('aset_tr_reklasifikasi_aset_buku.'.$column, 'buku.id')
            ->whereColumn('aset_tr_reklasifikasi_aset_buku.buku_aset_asal_id', '!=', 'aset_tr_reklasifikasi_aset_buku.buku_aset_tujuan_id');
        foreach (self::RECLASS as $alias => $kolom) {
            $query->selectRaw("coalesce(sum({$kolom}) filter (where tanggal < ?), 0) as before_{$alias}", [$dari]);
            $query->selectRaw("coalesce(sum({$kolom}) filter (where tanggal between ? and ?), 0) as range_{$alias}", [$dari, $sampai]);
        }
        $query->selectRaw('coalesce(sum(nilai_perolehan), 0) as all_acq');

        return $query;
    }

    /** @return list<string> Kolom pemindahan reklasifikasi diberi awalan arahnya. */
    private function reclassColumns(string $alias, string $prefix): array
    {
        $columns = ["{$alias}.all_acq as {$prefix}_all_acq"];
        foreach (array_keys(self::RECLASS) as $key) {
            $columns[] = "{$alias}.before_{$key} as {$prefix}_before_{$key}";
            $columns[] = "{$alias}.range_{$key} as {$prefix}_range_{$key}";
        }

        return $columns;
    }

    private function label(string $key): string
    {
        return [
            'harga_perolehan_awal' => 'Harga perolehan awal',
            'perolehan' => 'Perolehan',
            'reklasifikasi_harga_perolehan' => 'Reklasifikasi harga perolehan',
            'pelepasan_harga_perolehan' => 'Pelepasan harga perolehan',
            'harga_perolehan_akhir' => 'Harga perolehan akhir',
            'akumulasi_awal' => 'Akumulasi penyusutan awal',
            'penyusutan' => 'Penyusutan',
            'reklasifikasi_akumulasi' => 'Reklasifikasi akumulasi',
            'pelepasan_akumulasi' => 'Pelepasan akumulasi',
            'akumulasi_akhir' => 'Akumulasi penyusutan akhir',
            'nilai_buku_awal' => 'Nilai buku awal',
            'penurunan_nilai' => 'Penurunan nilai',
            'kenaikan_nilai' => 'Kenaikan nilai',
            'reklasifikasi_masuk' => 'Reklasifikasi masuk',
            'reklasifikasi_keluar' => 'Reklasifikasi keluar',
            'pelepasan' => 'Pelepasan',
            'nilai_buku_akhir' => 'Nilai buku akhir',
        ][$key];
    }
}
