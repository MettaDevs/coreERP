<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Reporting\Definitions;

use Brick\Math\BigDecimal;
use Illuminate\Database\Query\JoinClause;
use Modules\Apperp\ManagementAset\Models\master\BukuPenyusutan;
use Modules\Apperp\ManagementAset\Models\transaksi\DokumenSiklusAset\DokumenSiklusAset;
use Modules\Apperp\ManagementAset\Reporting\AssetReportFilters;
use Modules\Apperp\ManagementAset\Reporting\AssetSpecification;
use Modules\Apperp\ManagementAset\Reporting\Layouts\BuiltinLayout;
use Modules\Apperp\ManagementAset\Reporting\ReportContext;
use Modules\Apperp\ManagementAset\Reporting\ReportData;
use Modules\Apperp\ManagementAset\Reporting\ReportDefinition;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;

/**
 * Laporan penjualan aset: aset yang dijual dalam rentang tanggal, satu baris per dokumen
 * penjualan, dengan nilai penjualan, nilai buku saat dijual, dan laba/rugi pelepasannya.
 * Padanannya pelepasan "Disposal - sale" di Dynamics 365.
 *
 * Sama dengan pemusnahan, dokumen penjualan tidak punya alur persetujuan sendiri: persetujuannya
 * ada pada dekomisioning sebelumnya, dan saat dokumen penjualan disimpan asetnya langsung dilepas
 * dan bukunya ditutup pada tanggal dokumen. Status dokumennya selalu `draft`, jadi tidak
 * ditampilkan.
 *
 * Nilai buku dan laba/rugi dibaca dari satu buku, buku komersial bila tidak ada yang dipilih.
 * Buku itu dipilih di syarat join: menggabungkan semua buku membuat aset yang punya buku komersial
 * dan fiskal muncul dua kali, dan nilai penjualannya ikut terjumlah dua kali. Nilai penjualan yang
 * tidak diisi di dokumen tampil kosong, bukan nol, dan laba/ruginya ikut kosong.
 */
final class AssetDisposalSaleReport implements ReportDefinition
{
    public function code(): string
    {
        return 'laporan-penjualan-aset';
    }

    public function name(): string
    {
        return 'Laporan penjualan aset';
    }

    public function description(): string
    {
        return 'Aset yang dijual dalam rentang tanggal, beserta nilai penjualan, nilai buku saat dijual, dan laba/ruginya.';
    }

    public function permission(): string
    {
        return 'management-aset.penjualan-aset.read';
    }

    public function builtinLayouts(): array
    {
        return [
            new BuiltinLayout('standar', 'Laporan penjualan aset standar (Excel)', 'Satu baris per aset yang dijual: bukti, tanggal, nilai penjualan, nilai buku, dan laba/rugi.', 'xlsx'),
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

    public function fields(): array
    {
        return [
            ...AssetReportFilters::fields(),
            ['key' => 'filter_dari', 'label' => 'Filter tanggal awal', 'table' => null, 'type' => 'date'],
            ['key' => 'filter_sampai', 'label' => 'Filter tanggal akhir', 'table' => null, 'type' => 'date'],
            ['key' => 'jumlah_penjualan', 'label' => 'Jumlah penjualan', 'table' => null],
            ['key' => 'total_nilai_penjualan', 'label' => 'Total nilai penjualan', 'table' => null, 'type' => 'money'],
            ['key' => 'total_nilai_buku', 'label' => 'Total nilai buku saat dijual', 'table' => null, 'type' => 'money'],
            ['key' => 'total_laba_rugi', 'label' => 'Total laba/rugi', 'table' => null, 'type' => 'money'],
            ['key' => 'dicetak_pada', 'label' => 'Tanggal cetak', 'table' => null, 'type' => 'datetime'],
            ['key' => 'baris.nomor', 'label' => 'No.', 'table' => 'baris'],
            ['key' => 'baris.no_bukti', 'label' => 'No. bukti', 'table' => 'baris'],
            ['key' => 'baris.tanggal_penjualan', 'label' => 'Tanggal penjualan', 'table' => 'baris', 'type' => 'date'],
            ['key' => 'baris.kode_aset', 'label' => 'Kode aset', 'table' => 'baris'],
            ['key' => 'baris.nama_aset', 'label' => 'Nama aset', 'table' => 'baris'],
            ['key' => 'baris.spesifikasi', 'label' => 'Spesifikasi', 'table' => 'baris'],
            ['key' => 'baris.buku', 'label' => 'Buku penyusutan', 'table' => 'baris'],
            ['key' => 'baris.nilai_penjualan', 'label' => 'Nilai penjualan', 'table' => 'baris', 'type' => 'money'],
            ['key' => 'baris.nilai_buku', 'label' => 'Nilai buku saat dijual', 'table' => 'baris', 'type' => 'money'],
            ['key' => 'baris.laba_rugi', 'label' => 'Laba / rugi', 'table' => 'baris', 'type' => 'money'],
            ['key' => 'baris.keterangan', 'label' => 'Keterangan', 'table' => 'baris'],
        ];
    }

    public function data(ReportContext $context, array $parameters): ReportData
    {
        $bookId = is_string($parameters['buku_id'] ?? null) && $parameters['buku_id'] !== '' ? $parameters['buku_id'] : null;

        $query = DokumenSiklusAset::query()
            ->where('aset_tr_dokumen_siklus_aset.jenis_dokumen', 'penjualan-aset')
            ->join('aset_tr_aset as aset', fn (JoinClause $join) => $join->on('aset.id', '=', 'aset_tr_dokumen_siklus_aset.aset_id')->on('aset.tenant_id', '=', 'aset_tr_dokumen_siklus_aset.tenant_id'))
            ->leftJoin('aset_m_model_aset as model', fn (JoinClause $join) => $join->on('model.id', '=', 'aset.model_aset_id')->on('model.tenant_id', '=', 'aset.tenant_id'))
            ->leftJoin('aset_tr_buku_aset as buku', function (JoinClause $join) use ($bookId): void {
                $join->on('buku.aset_id', '=', 'aset.id')->on('buku.tenant_id', '=', 'aset.tenant_id');
                if ($bookId !== null) {
                    $join->where('buku.buku_id', '=', $bookId);
                } else {
                    $join->where(fn ($query) => $query->whereNull('buku.buku_id')->orWhereIn(
                        'buku.buku_id',
                        BukuPenyusutan::query()->where('posting_layer', 'current')->select('id'),
                    ));
                }
            })
            ->leftJoin('aset_m_buku_penyusutan as master_buku', fn (JoinClause $join) => $join->on('master_buku.id', '=', 'buku.buku_id')->on('master_buku.tenant_id', '=', 'buku.tenant_id'));

        app(OrganizationScope::class)->query($query, $context->request(), 'aset_tr_dokumen_siklus_aset.legal_entity_id', 'aset_tr_dokumen_siklus_aset.responsible_org_unit_id');
        AssetReportFilters::apply($query, $parameters, 'aset');
        if (! empty($parameters['dari'])) {
            $query->where('aset_tr_dokumen_siklus_aset.tanggal', '>=', $parameters['dari']);
        }
        if (! empty($parameters['sampai'])) {
            $query->where('aset_tr_dokumen_siklus_aset.tanggal', '<=', $parameters['sampai']);
        }

        $rows = $query
            ->select([
                'aset_tr_dokumen_siklus_aset.id', 'aset_tr_dokumen_siklus_aset.kode as no_bukti',
                'aset_tr_dokumen_siklus_aset.tanggal', 'aset_tr_dokumen_siklus_aset.nilai',
                'aset_tr_dokumen_siklus_aset.keterangan',
                'aset.kode as aset_kode', 'aset.nama as aset_nama', 'aset.model_number', 'aset.serial_number',
                'model.nama as model_nama', 'buku.book_code', 'buku.net_book_value', 'master_buku.nama as buku_nama',
            ])
            ->orderBy('aset_tr_dokumen_siklus_aset.tanggal')
            ->orderBy('aset_tr_dokumen_siklus_aset.kode')
            ->orderBy('buku.book_code')
            ->toBase()
            ->get();

        $totals = ['nilai_penjualan' => BigDecimal::zero(), 'nilai_buku' => BigDecimal::zero(), 'laba_rugi' => BigDecimal::zero()];
        $counted = [];
        $lines = [];
        foreach ($rows as $index => $row) {
            $sale = $row->nilai === null ? null : BigDecimal::of((string) $row->nilai);
            $bookValue = $row->net_book_value === null ? null : BigDecimal::of((string) $row->net_book_value);
            $gain = $sale !== null && $bookValue !== null ? $sale->minus($bookValue) : null;
            // Aset dengan dua buku yang cocok tampil dua baris, tetapi penjualannya satu: nilai
            // penjualan dijumlah sekali per dokumen.
            if ($sale !== null && ! isset($counted[$row->id])) {
                $totals['nilai_penjualan'] = $totals['nilai_penjualan']->plus($sale);
                $counted[$row->id] = true;
            }
            foreach (['nilai_buku' => $bookValue, 'laba_rugi' => $gain] as $key => $amount) {
                if ($amount !== null) {
                    $totals[$key] = $totals[$key]->plus($amount);
                }
            }

            $lines[] = [
                'nomor' => $index + 1,
                'no_bukti' => $row->no_bukti,
                'tanggal_penjualan' => substr((string) $row->tanggal, 0, 10),
                'kode_aset' => $row->aset_kode,
                'nama_aset' => $row->aset_nama,
                'spesifikasi' => AssetSpecification::describe($row->model_nama, $row->model_number, $row->serial_number),
                'buku' => $row->buku_nama ?? $row->book_code ?? '—',
                'nilai_penjualan' => $sale === null ? null : (string) $sale,
                'nilai_buku' => $bookValue === null ? null : (string) $bookValue,
                'laba_rugi' => $gain === null ? null : (string) $gain,
                'keterangan' => $row->keterangan ?: '—',
            ];
        }

        return new ReportData(
            fields: [
                ...AssetReportFilters::names($parameters),
                'filter_dari' => $parameters['dari'] ?? 'Semua',
                'filter_sampai' => $parameters['sampai'] ?? 'Semua',
                'jumlah_penjualan' => count(array_unique($rows->pluck('id')->all())),
                'total_nilai_penjualan' => (string) $totals['nilai_penjualan'],
                'total_nilai_buku' => (string) $totals['nilai_buku'],
                'total_laba_rugi' => (string) $totals['laba_rugi'],
                'dicetak_pada' => now('UTC')->toIso8601ZuluString(),
            ],
            tables: ['baris' => $lines],
            fileName: 'laporan-penjualan-aset-'.$context->now()->format('Ymd-Hi'),
        );
    }
}
