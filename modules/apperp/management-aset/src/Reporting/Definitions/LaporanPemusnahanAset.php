<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Reporting\Definitions;

use Modules\Apperp\ManagementAset\Models\transaksi\DokumenSiklusAset\DokumenSiklusAset;
use Modules\Apperp\ManagementAset\Reporting\Layouts\BuiltinLayout;
use Modules\Apperp\ManagementAset\Reporting\ReportContext;
use Modules\Apperp\ManagementAset\Reporting\ReportData;
use Modules\Apperp\ManagementAset\Reporting\ReportDefinition;
use Modules\Apperp\ManagementAset\Reporting\ReportHelper;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;

final class LaporanPemusnahanAset implements ReportDefinition
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
        return 'Daftar pemusnahan aset afkir/rusak beserta nilai perolehan dan nilai buku akhir.';
    }

    public function permission(): string
    {
        return 'management-aset.pemusnahan-aset.read';
    }

    public function builtinLayouts(): array
    {
        return [
            new BuiltinLayout('standar', 'Laporan pemusnahan aset standar (Excel)', 'Berita acara pemusnahan aset.', 'xlsx'),
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
            'total_nilai_buku' => 'Total nilai buku akhir',
            'jumlah_dokumen' => 'Jumlah pemusnahan',
            'dicetak_pada' => 'Tanggal cetak',
        ];

        $rows = [
            'baris.nomor' => 'No.',
            'baris.no_bukti' => 'No. bukti',
            'baris.tanggal' => 'Tanggal pemusnahan',
            'baris.kode_aset' => 'Kode aset',
            'baris.item_aset' => 'Item aset',
            'baris.spesifikasi' => 'Spesifikasi',
            'baris.kondisi_aset' => 'Kondisi aset',
            'baris.nilai_perolehan' => 'Nilai perolehan',
            'baris.nilai_buku_akhir' => 'Nilai buku akhir',
            'baris.status' => 'Status dokumen',
            'baris.keterangan' => 'Keterangan / Alasan',
        ];

        return [
            ...array_map(fn (string $k, string $l): array => ['key' => $k, 'label' => $l, 'table' => null], array_keys($header), $header),
            ...array_map(fn (string $k, string $l): array => ['key' => $k, 'label' => $l, 'table' => 'baris'], array_keys($rows), $rows),
        ];
    }

    public function data(ReportContext $context, array $parameters): ReportData
    {
        $query = DokumenSiklusAset::query()
            ->where('aset_tr_dokumen_siklus_aset.jenis_dokumen', 'pemusnahan-aset')
            ->where('aset_tr_dokumen_siklus_aset.status', '!=', 'dibatalkan')
            ->join('aset_tr_penerimaan_aset as asset', fn ($j) => $j->on('asset.id', '=', 'aset_tr_dokumen_siklus_aset.asset_id')->on('asset.tenant_id', '=', 'aset_tr_dokumen_siklus_aset.tenant_id'))
            ->leftJoin('aset_m_group_aset as group_aset', fn ($j) => $j->on('group_aset.id', '=', 'asset.group_aset_id')->on('group_aset.tenant_id', '=', 'asset.tenant_id'))
            ->leftJoin('aset_m_kelompok_harta_fiskal as fiskal', fn ($j) => $j->on('fiskal.id', '=', 'asset.kelompok_harta_fiskal_id')->on('fiskal.tenant_id', '=', 'asset.tenant_id'))
            ->leftJoin('aset_m_jenis_aset as jenis', fn ($j) => $j->on('jenis.id', '=', 'asset.jenis_aset_id')->on('jenis.tenant_id', '=', 'asset.tenant_id'))
            ->leftJoin('aset_m_model_aset as model', fn ($j) => $j->on('model.id', '=', 'asset.model_aset_id')->on('model.tenant_id', '=', 'asset.tenant_id'))
            ->leftJoin('aset_m_kondisi_aset as kondisi', fn ($j) => $j->on('kondisi.id', '=', 'asset.kondisi_aset_id')->on('kondisi.tenant_id', '=', 'asset.tenant_id'))
            ->leftJoin('aset_tr_buku_aset as buku', fn ($j) => $j->on('buku.asset_id', '=', 'asset.id')->on('buku.tenant_id', '=', 'asset.tenant_id'))
            ->leftJoin('aset_m_buku_penyusutan as master_buku', fn ($j) => $j->on('master_buku.id', '=', 'buku.buku_id')->on('master_buku.tenant_id', '=', 'buku.tenant_id'))
            ->where(function ($q): void {
                $q->where('master_buku.posting_layer', 'current')
                    ->orWhereNull('buku.id');
            });

        app(OrganizationScope::class)->query($query, $context->request(), 'aset_tr_dokumen_siklus_aset.legal_entity_id', 'aset_tr_dokumen_siklus_aset.responsible_org_unit_id');

        if (! empty($parameters['group_aset_id'])) {
            $query->where('asset.group_aset_id', $parameters['group_aset_id']);
        }
        if (! empty($parameters['kelompok_harta_fiskal_id'])) {
            $query->where('asset.kelompok_harta_fiskal_id', $parameters['kelompok_harta_fiskal_id']);
        }
        if (! empty($parameters['jenis_aset_id'])) {
            $query->where('asset.jenis_aset_id', $parameters['jenis_aset_id']);
        }
        if (! empty($parameters['asset_id'])) {
            $query->where('asset.id', $parameters['asset_id']);
        }
        if (! empty($parameters['dari'])) {
            $query->where('aset_tr_dokumen_siklus_aset.tanggal', '>=', $parameters['dari']);
        }
        if (! empty($parameters['sampai'])) {
            $query->where('aset_tr_dokumen_siklus_aset.tanggal', '<=', $parameters['sampai']);
        }

        $rows = $query
            ->addSelect([
                'aset_tr_dokumen_siklus_aset.id',
                'aset_tr_dokumen_siklus_aset.kode as dokumen_kode',
                'aset_tr_dokumen_siklus_aset.tanggal as dokumen_tanggal',
                'aset_tr_dokumen_siklus_aset.status as dokumen_status',
                'aset_tr_dokumen_siklus_aset.keterangan as dokumen_keterangan',
                'asset.kode as asset_kode',
                'asset.nama as asset_nama',
                'asset.model_number',
                'asset.serial_number',
                'asset.acquisition_value as asset_perolehan',
                'model.nama as model_nama',
                'kondisi.nama as kondisi_nama',
                'buku.net_book_value as buku_nilai_buku',
            ])
            ->orderByDesc('aset_tr_dokumen_siklus_aset.tanggal')
            ->toBase()
            ->get();

        $totPerolehan = 0.0;
        $totNilaiBuku = 0.0;

        $barisData = [];
        $nomor = 1;

        foreach ($rows as $item) {
            $tglStr = $item->dokumen_tanggal ? ReportHelper::tanggalIndo((string) $item->dokumen_tanggal) : '—';
            $perolehan = (float) ($item->asset_perolehan ?? 0.0);
            $nilaiBuku = (float) ($item->buku_nilai_buku ?? 0.0);

            $totPerolehan += $perolehan;
            $totNilaiBuku += $nilaiBuku;

            $statusLabel = match ($item->dokumen_status) {
                'draft' => 'Draf',
                'submitted' => 'Diajukan',
                'approved' => 'Disetujui',
                'closed' => 'Selesai',
                default => (string) $item->dokumen_status,
            };

            $barisData[] = [
                'nomor' => $nomor++,
                'no_bukti' => $item->dokumen_kode,
                'tanggal' => $tglStr,
                'kode_aset' => $item->asset_kode,
                'item_aset' => $item->asset_nama,
                'spesifikasi' => ReportHelper::spesifikasi($item->model_nama, $item->model_number, $item->serial_number),
                'kondisi_aset' => $item->kondisi_nama ?? 'Rusak',
                'nilai_perolehan' => ReportHelper::rupiah($perolehan),
                'nilai_buku_akhir' => ReportHelper::rupiah($nilaiBuku, true),
                'status' => $statusLabel,
                'keterangan' => $item->dokumen_keterangan ?: '—',
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
                'jumlah_dokumen' => count($barisData),
                'dicetak_pada' => now()->format('d/m/Y H:i'),
            ],
            tables: [
                'baris' => $barisData,
            ],
            fileName: 'laporan-pemusnahan-aset-'.now()->format('Ymd-Hi'),
        );
    }
}
