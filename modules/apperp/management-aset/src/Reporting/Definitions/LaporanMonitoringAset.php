<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Reporting\Definitions;

use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Asset;
use Modules\Apperp\ManagementAset\Reporting\Layouts\BuiltinLayout;
use Modules\Apperp\ManagementAset\Reporting\ReportContext;
use Modules\Apperp\ManagementAset\Reporting\ReportData;
use Modules\Apperp\ManagementAset\Reporting\ReportDefinition;
use Modules\Apperp\ManagementAset\Reporting\ReportHelper;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;

final class LaporanMonitoringAset implements ReportDefinition
{
    public function code(): string
    {
        return 'laporan-monitoring-aset';
    }

    public function name(): string
    {
        return 'Laporan monitoring aset';
    }

    public function description(): string
    {
        return 'Pemantauan kondisi fisik, status kesesuaian, dan nilai buku aset.';
    }

    public function permission(): string
    {
        return 'management-aset.monitoring-aset.read';
    }

    public function builtinLayouts(): array
    {
        return [
            new BuiltinLayout('standar', 'Laporan monitoring aset standar (Excel)', 'Daftar monitoring aset berkala.', 'xlsx'),
        ];
    }

    public function parameterRules(): array
    {
        return [
            'group_aset_id' => ['nullable', 'ulid'],
            'kelompok_harta_fiskal_id' => ['nullable', 'ulid'],
            'jenis_aset_id' => ['nullable', 'ulid'],
            'asset_id' => ['nullable', 'ulid'],
            'dari' => ['nullable', 'date_format:Y-m-d'],
            'sampai' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:dari'],
        ];
    }

    public function fields(): array
    {
        $header = [
            'filter_group' => 'Filter group aset',
            'filter_golongan' => 'Filter golongan aset',
            'filter_jenis' => 'Filter jenis aset',
            'filter_aset' => 'Filter nama aset',
            'filter_dari' => 'Filter tanggal awal',
            'filter_sampai' => 'Filter tanggal akhir',
            'total_nilai_perolehan' => 'Total nilai perolehan',
            'total_nilai_buku' => 'Total nilai akhir buku',
            'jumlah_aset' => 'Jumlah aset terpantau',
            'dicetak_pada' => 'Tanggal cetak',
        ];

        $rows = [
            'baris.nomor' => 'No.',
            'baris.tgl_monitoring' => 'Tgl monitoring',
            'baris.no_bukti' => 'No. bukti',
            'baris.kode_aset' => 'Kode aset',
            'baris.nama_aset' => 'Nama aset',
            'baris.spesifikasi' => 'Spesifikasi',
            'baris.satuan' => 'Satuan',
            'baris.jumlah' => 'Jumlah',
            'baris.kondisi_sistem' => 'Kondisi sistem',
            'baris.kondisi_fisik' => 'Kondisi fisik',
            'baris.status_monitoring' => 'Status monitoring',
            'baris.keterangan' => 'Keterangan',
            'baris.nilai_perolehan' => 'Nilai perolehan',
            'baris.akumulasi_penyusutan' => 'Akumulasi penyusutan',
            'baris.nilai_akhir_buku' => 'Nilai akhir buku',
            'baris.penanggung_jawab' => 'Penanggung jawab',
            'baris.unit_organisasi' => 'Unit organisasi',
        ];

        return [
            ...array_map(fn (string $k, string $l): array => ['key' => $k, 'label' => $l, 'table' => null], array_keys($header), $header),
            ...array_map(fn (string $k, string $l): array => ['key' => $k, 'label' => $l, 'table' => 'baris'], array_keys($rows), $rows),
        ];
    }

    public function data(ReportContext $context, array $parameters): ReportData
    {
        $query = Asset::query()
            ->leftJoin('aset_m_group_aset as group_aset', fn ($j) => $j->on('group_aset.id', '=', 'aset_tr_penerimaan_aset.group_aset_id')->on('group_aset.tenant_id', '=', 'aset_tr_penerimaan_aset.tenant_id'))
            ->leftJoin('aset_m_kelompok_harta_fiskal as fiskal', fn ($j) => $j->on('fiskal.id', '=', 'aset_tr_penerimaan_aset.kelompok_harta_fiskal_id')->on('fiskal.tenant_id', '=', 'aset_tr_penerimaan_aset.tenant_id'))
            ->leftJoin('aset_m_jenis_aset as jenis', fn ($j) => $j->on('jenis.id', '=', 'aset_tr_penerimaan_aset.jenis_aset_id')->on('jenis.tenant_id', '=', 'aset_tr_penerimaan_aset.tenant_id'))
            ->leftJoin('aset_m_model_aset as model', fn ($j) => $j->on('model.id', '=', 'aset_tr_penerimaan_aset.model_aset_id')->on('model.tenant_id', '=', 'aset_tr_penerimaan_aset.tenant_id'))
            ->leftJoin('aset_m_kondisi_aset as kondisi', fn ($j) => $j->on('kondisi.id', '=', 'aset_tr_penerimaan_aset.kondisi_aset_id')->on('kondisi.tenant_id', '=', 'aset_tr_penerimaan_aset.tenant_id'))
            ->leftJoin('aset_tr_buku_aset as buku', fn ($j) => $j->on('buku.asset_id', '=', 'aset_tr_penerimaan_aset.id')->on('buku.tenant_id', '=', 'aset_tr_penerimaan_aset.tenant_id'));

        app(OrganizationScope::class)->assetQuery($query, $context->request(), 'aset_tr_penerimaan_aset.legal_entity_id', 'aset_tr_penerimaan_aset.responsible_org_unit_id');

        if (! empty($parameters['group_aset_id'])) {
            $query->where('aset_tr_penerimaan_aset.group_aset_id', $parameters['group_aset_id']);
        }
        if (! empty($parameters['kelompok_harta_fiskal_id'])) {
            $query->where('aset_tr_penerimaan_aset.kelompok_harta_fiskal_id', $parameters['kelompok_harta_fiskal_id']);
        }
        if (! empty($parameters['jenis_aset_id'])) {
            $query->where('aset_tr_penerimaan_aset.jenis_aset_id', $parameters['jenis_aset_id']);
        }
        if (! empty($parameters['asset_id'])) {
            $query->where('aset_tr_penerimaan_aset.id', $parameters['asset_id']);
        }

        $rows = $query
            ->addSelect([
                'aset_tr_penerimaan_aset.id',
                'aset_tr_penerimaan_aset.kode',
                'aset_tr_penerimaan_aset.nama',
                'aset_tr_penerimaan_aset.model_number',
                'aset_tr_penerimaan_aset.serial_number',
                'aset_tr_penerimaan_aset.acquisition_value',
                'aset_tr_penerimaan_aset.lifecycle_state',
                'aset_tr_penerimaan_aset.keterangan',
                'aset_tr_penerimaan_aset.responsible_org_unit_id',
                'model.nama as model_nama',
                'kondisi.nama as kondisi_nama',
                'buku.accumulated_depreciation as buku_akumulasi',
                'buku.net_book_value as buku_nilai_buku',
            ])
            ->orderBy('aset_tr_penerimaan_aset.kode')
            ->toBase()
            ->get();

        $totPerolehan = 0.0;
        $totNilaiBuku = 0.0;
        $barisData = [];
        $nomor = 1;

        foreach ($rows as $item) {
            $perolehan = (float) ($item->acquisition_value ?? 0.0);
            $akumulasi = (float) ($item->buku_akumulasi ?? 0.0);
            $nilaiBuku = (float) ($item->buku_nilai_buku ?? max(0.0, $perolehan - $akumulasi));

            $totPerolehan += $perolehan;
            $totNilaiBuku += $nilaiBuku;

            $barisData[] = [
                'nomor' => $nomor++,
                'tgl_monitoring' => now()->format('d/m/Y'),
                'no_bukti' => 'MON-'.substr((string) $item->id, 0, 8),
                'kode_aset' => $item->kode,
                'nama_aset' => $item->nama,
                'spesifikasi' => ReportHelper::spesifikasi($item->model_nama, $item->model_number, $item->serial_number),
                'satuan' => 'Unit',
                'jumlah' => 1,
                'kondisi_sistem' => $item->lifecycle_state ?? 'Aktif',
                'kondisi_fisik' => $item->kondisi_nama ?? 'Baik',
                'status_monitoring' => 'Sesuai',
                'keterangan' => $item->keterangan ?: '—',
                'nilai_perolehan' => ReportHelper::rupiah($perolehan),
                'akumulasi_penyusutan' => ReportHelper::rupiah($akumulasi, true),
                'nilai_akhir_buku' => ReportHelper::rupiah($nilaiBuku, true),
                'penanggung_jawab' => '—',
                'unit_organisasi' => $item->responsible_org_unit_id ?? '—',
            ];
        }

        return new ReportData(
            fields: [
                'filter_group' => $parameters['group_aset_id'] ?? 'Semua',
                'filter_golongan' => $parameters['kelompok_harta_fiskal_id'] ?? 'Semua',
                'filter_jenis' => $parameters['jenis_aset_id'] ?? 'Semua',
                'filter_aset' => $parameters['asset_id'] ?? 'Semua',
                'filter_dari' => ! empty($parameters['dari']) ? ReportHelper::tanggalIndo($parameters['dari']) : 'Semua',
                'filter_sampai' => ! empty($parameters['sampai']) ? ReportHelper::tanggalIndo($parameters['sampai']) : 'Semua',
                'total_nilai_perolehan' => ReportHelper::rupiah($totPerolehan),
                'total_nilai_buku' => ReportHelper::rupiah($totNilaiBuku, true),
                'jumlah_aset' => count($barisData),
                'dicetak_pada' => now()->format('d/m/Y H:i'),
            ],
            tables: [
                'baris' => $barisData,
            ],
            fileName: 'laporan-monitoring-aset-'.now()->format('Ymd-Hi'),
        );
    }
}
