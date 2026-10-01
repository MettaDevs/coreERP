<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Reporting\Definitions;

use Brick\Math\BigDecimal;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;
use Modules\Apperp\ManagementAset\Models\transaksi\Reclassification\AssetReclassificationLine;
use Modules\Apperp\ManagementAset\Reporting\AdditionalFilters;
use Modules\Apperp\ManagementAset\Reporting\AssetReportFilters;
use Modules\Apperp\ManagementAset\Reporting\Layouts\BuiltinLayout;
use Modules\Apperp\ManagementAset\Reporting\ReportContext;
use Modules\Apperp\ManagementAset\Reporting\ReportData;
use Modules\Apperp\ManagementAset\Reporting\ReportDataItem;
use Modules\Apperp\ManagementAset\Reporting\ReportDefinition;
use Modules\Apperp\ManagementAset\Support\AcquisitionMethod;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;
use Modules\Apperp\ManagementAset\Support\StatusAset;

/**
 * Daftar perolehan aset: aset yang diperoleh dalam rentang tanggal, satu baris per aset, dengan nilai
 * perolehan, cara perolehan, dan dokumen asalnya. Padanan *Fixed Asset Acquisition List* Business Central.
 *
 * - **Nilai perolehan** adalah harga perolehan register pada saat diperoleh: harga perolehan sekarang ditambah
 *   bagian yang sudah dipecah ke aset lain. Koreksi nilai perolehan sebelum penyusutan ikut, karena ia
 *   mengoreksi perolehan itu sendiri.
 * - **Cara perolehan dan dokumen asal** dibaca dari penerimaan aset yang melahirkannya. Aset yang dicatat
 *   langsung di register tidak punya dokumen asal.
 * - **Aset pecahan tidak ikut**: ia lahir dari reklasifikasi, bukan diperoleh, dan nilainya sudah terhitung
 *   pada aset asalnya — seperti BC yang hanya mencetak aset dengan tanggal perolehan di rentang itu.
 */
final class AssetAcquisitionListReport implements ReportDefinition
{
    private const WITHOUT_DOCUMENT = 'Dicatat langsung di register';

    public function code(): string
    {
        return 'laporan-perolehan-aset';
    }

    public function name(): string
    {
        return 'Daftar perolehan aset';
    }

    public function description(): string
    {
        return 'Aset yang diperoleh dalam rentang tanggal, dengan nilai perolehan, cara perolehan, dan dokumen asalnya.';
    }

    public function permission(): string
    {
        return 'management-aset.aset.read';
    }

    public function builtinLayouts(): array
    {
        return [
            new BuiltinLayout('standar', 'Daftar perolehan aset standar (Excel)', 'Satu baris per aset yang diperoleh: tanggal, cara perolehan, dokumen asal, dan nilai perolehan.', 'xlsx'),
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

    public function dataItems(): array
    {
        return [
            new ReportDataItem('aset', 'Aset', Aset::class, 'aset_tr_aset', ['kode']),
        ];
    }

    public function fields(): array
    {
        return [
            ...AssetReportFilters::fields(),
            ['key' => 'filter_dari', 'label' => 'Dari tanggal', 'table' => null, 'type' => 'date'],
            ['key' => 'filter_sampai', 'label' => 'Sampai tanggal', 'table' => null, 'type' => 'date'],
            ['key' => 'jumlah_aset', 'label' => 'Jumlah aset', 'table' => null],
            ['key' => 'total_nilai_perolehan', 'label' => 'Total nilai perolehan', 'table' => null, 'type' => 'money'],
            ['key' => 'dicetak_pada', 'label' => 'Tanggal cetak', 'table' => null, 'type' => 'datetime'],
            ['key' => 'baris.nomor', 'label' => 'No.', 'table' => 'baris'],
            ['key' => 'baris.kode', 'label' => 'Kode aset', 'table' => 'baris'],
            ['key' => 'baris.nama', 'label' => 'Nama aset', 'table' => 'baris'],
            ['key' => 'baris.group', 'label' => 'Group aset', 'table' => 'baris'],
            ['key' => 'baris.jenis', 'label' => 'Jenis aset', 'table' => 'baris'],
            ['key' => 'baris.lokasi', 'label' => 'Lokasi', 'table' => 'baris'],
            ['key' => 'baris.tanggal_perolehan', 'label' => 'Tanggal perolehan', 'table' => 'baris', 'type' => 'date'],
            ['key' => 'baris.tanggal_mulai_dipakai', 'label' => 'Tanggal mulai dipakai', 'table' => 'baris', 'type' => 'date'],
            ['key' => 'baris.cara_perolehan', 'label' => 'Cara perolehan', 'table' => 'baris'],
            ['key' => 'baris.dokumen_asal', 'label' => 'Dokumen asal', 'table' => 'baris'],
            ['key' => 'baris.status', 'label' => 'Status aset', 'table' => 'baris'],
            ['key' => 'baris.nilai_perolehan', 'label' => 'Nilai perolehan', 'table' => 'baris', 'type' => 'money'],
        ];
    }

    public function data(ReportContext $context, array $parameters): ReportData
    {
        $today = $context->now()->toDateString();
        $dari = is_string($parameters['dari'] ?? null) && $parameters['dari'] !== '' ? $parameters['dari'] : Carbon::parse($today)->startOfYear()->toDateString();
        $sampai = is_string($parameters['sampai'] ?? null) && $parameters['sampai'] !== '' ? $parameters['sampai'] : $today;

        $query = Aset::query()
            ->leftJoin('aset_tr_penerimaan_aset as penerimaan', fn (JoinClause $join) => $join->on('penerimaan.id', '=', 'aset_tr_aset.penerimaan_aset_id')->on('penerimaan.tenant_id', '=', 'aset_tr_aset.tenant_id'))
            ->leftJoin('aset_m_group_aset as group_aset', fn (JoinClause $join) => $join->on('group_aset.id', '=', 'aset_tr_aset.group_aset_id')->on('group_aset.tenant_id', '=', 'aset_tr_aset.tenant_id'))
            ->leftJoin('aset_m_jenis_aset as jenis', fn (JoinClause $join) => $join->on('jenis.id', '=', 'aset_tr_aset.jenis_aset_id')->on('jenis.tenant_id', '=', 'aset_tr_aset.tenant_id'))
            ->leftJoin('aset_m_lokasi_aset as lokasi', fn (JoinClause $join) => $join->on('lokasi.id', '=', 'aset_tr_aset.lokasi_aset_id')->on('lokasi.tenant_id', '=', 'aset_tr_aset.tenant_id'))
            ->whereBetween('aset_tr_aset.acquired_on', [$dari, $sampai])
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('aset_tr_reklasifikasi_aset_details as pecahan')
                ->whereColumn('pecahan.tenant_id', 'aset_tr_aset.tenant_id')
                ->whereColumn('pecahan.aset_baru_id', 'aset_tr_aset.id'));

        app(OrganizationScope::class)->asetQuery($query, $context->request());
        AssetReportFilters::apply($query, $parameters, 'aset_tr_aset');
        foreach ($this->dataItems() as $item) {
            AdditionalFilters::apply($query, $item, $parameters, $context);
        }

        $rows = $query
            ->select([
                'aset_tr_aset.kode', 'aset_tr_aset.nama', 'aset_tr_aset.acquired_on', 'aset_tr_aset.placed_in_service_on',
                'aset_tr_aset.acquisition_value', 'aset_tr_aset.lifecycle_state',
                'penerimaan.kode as penerimaan_kode', 'penerimaan.cara_perolehan',
                'group_aset.nama as group_nama', 'jenis.nama as jenis_nama', 'lokasi.nama as lokasi_nama',
            ])
            // Bagian yang sudah dipecah ke aset lain kembali dijumlahkan: nilai saat diperoleh.
            ->selectSub(
                AssetReclassificationLine::query()
                    ->selectRaw('coalesce(sum(nilai_perolehan_dipindah), 0)')
                    ->whereColumn('aset_tr_reklasifikasi_aset_details.aset_id', 'aset_tr_aset.id')
                    ->whereNotNull('aset_tr_reklasifikasi_aset_details.aset_baru_id'),
                'dipecah',
            )
            ->orderBy('aset_tr_aset.acquired_on')
            ->orderBy('aset_tr_aset.kode')
            ->toBase()
            ->get();

        $total = BigDecimal::zero();
        $lines = [];
        foreach ($rows as $row) {
            $nilai = BigDecimal::of((string) $row->acquisition_value)->plus((string) $row->dipecah);
            $total = $total->plus($nilai);
            $lines[] = [
                'nomor' => count($lines) + 1,
                'kode' => $row->kode,
                'nama' => $row->nama,
                'group' => $row->group_nama ?? '—',
                'jenis' => $row->jenis_nama ?? '—',
                'lokasi' => $row->lokasi_nama ?? '—',
                'tanggal_perolehan' => substr((string) $row->acquired_on, 0, 10),
                'tanggal_mulai_dipakai' => $row->placed_in_service_on === null ? null : substr((string) $row->placed_in_service_on, 0, 10),
                'cara_perolehan' => $row->penerimaan_kode === null ? self::WITHOUT_DOCUMENT : (AcquisitionMethod::LABELS[$row->cara_perolehan] ?? (string) $row->cara_perolehan),
                'dokumen_asal' => $row->penerimaan_kode ?? '—',
                'status' => StatusAset::LABELS[$row->lifecycle_state] ?? (string) $row->lifecycle_state,
                'nilai_perolehan' => (string) $nilai,
            ];
        }

        return new ReportData(
            fields: [
                ...AssetReportFilters::names($parameters),
                'filter_dari' => $dari,
                'filter_sampai' => $sampai,
                'jumlah_aset' => count($lines),
                'total_nilai_perolehan' => (string) $total,
                'dicetak_pada' => now('UTC')->toIso8601ZuluString(),
            ],
            tables: ['baris' => $lines],
            fileName: 'daftar-perolehan-aset-'.$dari.'-'.$sampai,
        );
    }
}
