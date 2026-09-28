import { useMemo } from 'react';
import type { DataTableColumn } from '@apperp/ui/data-table';
import { ReportFilterBar } from './_shared/ReportFilterBar';
import {
    AssetFilter,
    AssetGroupFilter,
    AssetTypeFilter,
    DateFilter,
    FiscalClassificationFilter,
} from './_shared/ReportFilters';
import { ReportPageLayout } from './_shared/ReportPageLayout';
import { useReportData } from './_shared/useReportData';

export type SaleReportRow = {
    nomor?: number;
    no_bukti?: string;
    tanggal_penjualan?: string;
    asset_kode?: string;
    asset_nama?: string;
    spesifikasi?: string;
    nilai_penjualan?: string | number;
    nilai_buku?: string | number;
    laba_rugi?: string | number;
    keterangan?: string;
    status_dokumen?: string;
} & Record<string, unknown>;

export default function LaporanPenjualanAsetPage() {
    const {
        filters,
        bindFilter,
        hasActiveFilters,
        resetFilters,
        rows,
        loading,
        error,
        refetch,
    } = useReportData<SaleReportRow>('laporan-penjualan-aset');

    const columns: DataTableColumn<SaleReportRow>[] = useMemo(
        () => [
            {
                id: 'no_bukti',
                header: 'No. bukti',
                cell: (row) => (
                    <span className="font-mono text-xs font-semibold">
                        {String(row.no_bukti ?? '-')}
                    </span>
                ),
            },
            {
                id: 'tanggal_penjualan',
                header: 'Tgl penjualan',
                cell: (row) => String(row.tanggal_penjualan ?? '-'),
            },
            {
                id: 'asset_kode',
                header: 'Kode aset',
                cell: (row) => (
                    <span className="font-mono text-xs">
                        {String(row.asset_kode ?? '-')}
                    </span>
                ),
            },
            {
                id: 'asset_nama',
                header: 'Item aset',
                cell: (row) => String(row.asset_nama ?? '-'),
            },
            {
                id: 'spesifikasi',
                header: 'Spesifikasi',
                cell: (row) => String(row.spesifikasi ?? '-'),
            },
            {
                id: 'nilai_penjualan',
                header: 'Nilai penjualan',
                cell: (row) => (
                    <span className="font-medium">
                        {String(row.nilai_penjualan ?? '-')}
                    </span>
                ),
                align: 'right',
            },
            {
                id: 'nilai_buku',
                header: 'Nilai buku',
                cell: (row) => String(row.nilai_buku ?? '-'),
                align: 'right',
            },
            {
                id: 'laba_rugi',
                header: 'Laba / (rugi)',
                cell: (row) => (
                    <span className="text-primary font-medium">
                        {String(row.laba_rugi ?? '-')}
                    </span>
                ),
                align: 'right',
            },
            {
                id: 'keterangan',
                header: 'Keterangan',
                cell: (row) => (
                    <span className="text-muted-foreground text-xs">
                        {String(row.keterangan ?? '-')}
                    </span>
                ),
            },
            {
                id: 'status_dokumen',
                header: 'Status dokumen',
                cell: (row) => (
                    <span className="bg-muted text-muted-foreground inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium capitalize">
                        {String(row.status_dokumen ?? '-')}
                    </span>
                ),
                align: 'center',
            },
        ],
        [],
    );

    return (
        <ReportPageLayout<SaleReportRow>
            title="Laporan penjualan aset"
            description="Rekapitulasi transaksi pelepasan aset secara komersial beserta nilai realisasi dan laba/rugi pelepasan."
            reportCode="laporan-penjualan-aset"
            filters={filters}
            filterBar={
                <ReportFilterBar
                    canReset={hasActiveFilters}
                    onReset={resetFilters}
                >
                    <AssetGroupFilter {...bindFilter('group_aset_id')} />
                    <FiscalClassificationFilter
                        {...bindFilter('kelompok_harta_fiskal_id')}
                    />
                    <AssetTypeFilter {...bindFilter('jenis_aset_id')} />
                    <AssetFilter {...bindFilter('asset_id')} />
                    <DateFilter label="Dari tanggal" {...bindFilter('dari')} />
                    <DateFilter
                        label="Sampai tanggal"
                        {...bindFilter('sampai')}
                    />
                </ReportFilterBar>
            }
            columns={columns}
            rows={rows}
            loading={loading}
            error={error}
            onRefresh={refetch}
        />
    );
}
