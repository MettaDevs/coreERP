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
 * Laporan pemusnahan aset: aset yang dimusnahkan dalam rentang tanggal, satu baris per dokumen
 * pemusnahan, beserta nilai perolehan dan nilai bukunya saat dimusnahkan. Padanannya pelepasan
 * "Disposal - scrap" di Dynamics 365.
 *
 * Dokumen pemusnahan tidak punya alur persetujuan sendiri: persetujuannya ada pada dekomisioning
 * sebelumnya, dan saat dokumen pemusnahan disimpan asetnya langsung dilepas dan bukunya ditutup
 * pada tanggal dokumen. Setiap dokumen di sini karena itu pemusnahan yang sudah terjadi. Status
 * dokumennya tidak ditampilkan — nilainya `draft` untuk semua, dan "Draf" pada laporan pemusnahan
 * justru menyesatkan.
 *
 * Nilai perolehan dan nilai buku dibaca dari buku aset yang sama, buku komersial bila tidak ada
 * yang dipilih. Buku itu ditutup saat pemusnahan, jadi nilai bukunya adalah nilai pada saat aset
 * dimusnahkan. Aset tanpa buku yang cocok tetap tampil, dengan nilai kosong: pemusnahannya tetap
 * terjadi, dan nilai yang tidak ada tidak ditulis nol.
 */
final class AssetDisposalScrapReport implements ReportDefinition
{
    public function code(): string
    {
        return 'laporan-pemusnahan-aset';
    }

    public function name(): string
    {
        return 'Laporan pemusnahan aset';
    }

    public function description(): string
    {
        return 'Aset yang dimusnahkan dalam rentang tanggal, beserta nilai perolehan dan nilai bukunya saat dimusnahkan.';
    }

    public function permission(): string
    {
        return 'management-aset.pemusnahan-aset.read';
    }

    public function builtinLayouts(): array
    {
        return [
            new BuiltinLayout('standar', 'Laporan pemusnahan aset standar (Excel)', 'Satu baris per aset yang dimusnahkan: bukti, tanggal, kondisi, nilai perolehan, dan nilai buku.', 'xlsx'),
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
        return [];
    }

    public function fields(): array
    {
        return [
            ...AssetReportFilters::fields(),
            ['key' => 'filter_dari', 'label' => 'Filter tanggal awal', 'table' => null, 'type' => 'date'],
            ['key' => 'filter_sampai', 'label' => 'Filter tanggal akhir', 'table' => null, 'type' => 'date'],
            ['key' => 'total_nilai_perolehan', 'label' => 'Total nilai perolehan', 'table' => null, 'type' => 'money'],
            ['key' => 'total_nilai_buku', 'label' => 'Total nilai buku akhir', 'table' => null, 'type' => 'money'],
            ['key' => 'jumlah_dokumen', 'label' => 'Jumlah pemusnahan', 'table' => null],
            ['key' => 'dicetak_pada', 'label' => 'Tanggal cetak', 'table' => null, 'type' => 'datetime'],
            ['key' => 'baris.nomor', 'label' => 'No.', 'table' => 'baris'],
            ['key' => 'baris.no_bukti', 'label' => 'No. bukti', 'table' => 'baris'],
            ['key' => 'baris.tanggal', 'label' => 'Tanggal pemusnahan', 'table' => 'baris', 'type' => 'date'],
            ['key' => 'baris.kode_aset', 'label' => 'Kode aset', 'table' => 'baris'],
            ['key' => 'baris.item_aset', 'label' => 'Nama aset', 'table' => 'baris'],
            ['key' => 'baris.spesifikasi', 'label' => 'Spesifikasi', 'table' => 'baris'],
            ['key' => 'baris.kondisi_aset', 'label' => 'Kondisi aset', 'table' => 'baris'],
            ['key' => 'baris.buku', 'label' => 'Buku penyusutan', 'table' => 'baris'],
            ['key' => 'baris.nilai_perolehan', 'label' => 'Nilai perolehan', 'table' => 'baris', 'type' => 'money'],
            ['key' => 'baris.nilai_buku_akhir', 'label' => 'Nilai buku saat dimusnahkan', 'table' => 'baris', 'type' => 'money'],
            ['key' => 'baris.keterangan', 'label' => 'Keterangan / alasan', 'table' => 'baris'],
        ];
    }

    public function data(ReportContext $context, array $parameters): ReportData
    {
        $bookId = is_string($parameters['buku_id'] ?? null) && $parameters['buku_id'] !== '' ? $parameters['buku_id'] : null;

        $query = DokumenSiklusAset::query()
            ->where('aset_tr_dokumen_siklus_aset.jenis_dokumen', 'pemusnahan-aset')
            ->join('aset_tr_aset as aset', fn (JoinClause $join) => $join->on('aset.id', '=', 'aset_tr_dokumen_siklus_aset.aset_id')->on('aset.tenant_id', '=', 'aset_tr_dokumen_siklus_aset.tenant_id'))
            ->leftJoin('aset_m_model_aset as model', fn (JoinClause $join) => $join->on('model.id', '=', 'aset.model_aset_id')->on('model.tenant_id', '=', 'aset.tenant_id'))
            ->leftJoin('aset_m_kondisi_aset as kondisi', fn (JoinClause $join) => $join->on('kondisi.id', '=', 'aset.kondisi_aset_id')->on('kondisi.tenant_id', '=', 'aset.tenant_id'))
            // Buku dipilih di syarat join, bukan di WHERE: dokumen yang asetnya tidak punya buku
            // yang cocok tetap tampil, hanya nilainya yang kosong.
            ->leftJoin('aset_tr_buku_aset as buku', function (JoinClause $join) use ($bookId): void {
                $join->on('buku.aset_id', '=', 'aset.id')->on('buku.tenant_id', '=', 'aset.tenant_id');
                if ($bookId !== null) {
                    $join->where('buku.buku_id', '=', $bookId);
                } else {
                    // Tanpa pilihan: buku komersial saja. Buku fiskal atau memorandum mencatat aset
                    // yang sama, dan menjumlahkan keduanya membuat totalnya dobel.
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
                'aset_tr_dokumen_siklus_aset.tanggal', 'aset_tr_dokumen_siklus_aset.keterangan',
                'aset.kode as aset_kode', 'aset.nama as aset_nama', 'aset.model_number', 'aset.serial_number',
                'model.nama as model_nama', 'kondisi.nama as kondisi_nama',
                'buku.book_code', 'buku.acquisition_value', 'buku.net_book_value', 'master_buku.nama as buku_nama',
            ])
            ->orderBy('aset_tr_dokumen_siklus_aset.tanggal')
            ->orderBy('aset_tr_dokumen_siklus_aset.kode')
            ->orderBy('buku.book_code')
            ->toBase()
            ->get();

        $totalAcquisition = BigDecimal::zero();
        $totalBookValue = BigDecimal::zero();
        $lines = [];
        foreach ($rows as $index => $row) {
            $acquisition = $row->acquisition_value === null ? null : (string) $row->acquisition_value;
            $bookValue = $row->net_book_value === null ? null : (string) $row->net_book_value;
            $totalAcquisition = $totalAcquisition->plus($acquisition ?? 0);
            $totalBookValue = $totalBookValue->plus($bookValue ?? 0);

            $lines[] = [
                'nomor' => $index + 1,
                'no_bukti' => $row->no_bukti,
                'tanggal' => substr((string) $row->tanggal, 0, 10),
                'kode_aset' => $row->aset_kode,
                'item_aset' => $row->aset_nama,
                'spesifikasi' => AssetSpecification::describe($row->model_nama, $row->model_number, $row->serial_number),
                'kondisi_aset' => $row->kondisi_nama ?? '—',
                'buku' => $row->buku_nama ?? $row->book_code ?? '—',
                'nilai_perolehan' => $acquisition,
                'nilai_buku_akhir' => $bookValue,
                'keterangan' => $row->keterangan ?: '—',
            ];
        }

        return new ReportData(
            fields: [
                ...AssetReportFilters::names($parameters),
                'filter_dari' => $parameters['dari'] ?? 'Semua',
                'filter_sampai' => $parameters['sampai'] ?? 'Semua',
                'total_nilai_perolehan' => (string) $totalAcquisition,
                'total_nilai_buku' => (string) $totalBookValue,
                'jumlah_dokumen' => count(array_unique($rows->pluck('id')->all())),
                'dicetak_pada' => now('UTC')->toIso8601ZuluString(),
            ],
            tables: ['baris' => $lines],
            fileName: 'laporan-pemusnahan-aset-'.$context->now()->format('Ymd-Hi'),
        );
    }
}
