import { useMemo } from 'react';
import type { DataTableColumn } from '@apperp/ui/data-table';
import { ReportFilterBar } from './_shared/ReportFilterBar';
import { ReportPageLayout } from './_shared/ReportPageLayout';
import { useReportData } from './_shared/useReportData';

export type DisposalReportRow = {
    nomor?: number;
    no_bukti?: string;
    tanggal_pemusnahan?: string;
    asset_kode?: string;
    asset_nama?: string;
    spesifikasi?: string;
    kondisi_aset?: string;
    nilai_perolehan?: string | number;
    nilai_buku_akhir?: string | number;
    keterangan?: string;
    status_dokumen?: string;
} & Record<string, unknown>;

export default function LaporanPemusnahanAsetPage() {
    const {
        filters,
        updateFilter,
        resetFilters,
        rows,
        loading,
        error,
        refetch,
    } = useReportData<DisposalReportRow>('laporan-pemusnahan-aset');

    const columns: DataTableColumn<DisposalReportRow>[] = useMemo(
        () => [
            {
                id: 'no_bukti',
                header: 'No. Bukti',
                cell: (row) => (
                    <span className="font-mono text-xs font-semibold">
                        {String(row.no_bukti ?? '-')}
                    </span>
                ),
            },
            {
                id: 'tanggal_pemusnahan',
                header: 'Tgl Pemusnahan',
                cell: (row) => String(row.tanggal_pemusnahan ?? '-'),
            },
            {
                id: 'asset_kode',
                header: 'Kode Aset',
                cell: (row) => (
                    <span className="font-mono text-xs">
                        {String(row.asset_kode ?? '-')}
                    </span>
                ),
            },
            {
                id: 'asset_nama',
                header: 'Item Aset',
                cell: (row) => String(row.asset_nama ?? '-'),
            },
            {
                id: 'spesifikasi',
                header: 'Spesifikasi',
                cell: (row) => String(row.spesifikasi ?? '-'),
            },
            {
                id: 'kondisi_aset',
                header: 'Kondisi Aset',
                cell: (row) => (
                    <span className="bg-muted text-muted-foreground inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium capitalize">
                        {String(row.kondisi_aset ?? '-')}
                    </span>
                ),
                align: 'center',
            },
            {
                id: 'nilai_perolehan',
                header: 'Nilai Perolehan',
                cell: (row) => String(row.nilai_perolehan ?? '-'),
                align: 'right',
            },
            {
                id: 'nilai_buku_akhir',
                header: 'Nilai Buku Akhir',
                cell: (row) => String(row.nilai_buku_akhir ?? '-'),
                align: 'right',
            },
            {
                id: 'keterangan',
                header: 'Keterangan / Alasan',
                cell: (row) => (
                    <span className="text-muted-foreground text-xs">
                        {String(row.keterangan ?? '-')}
                    </span>
                ),
            },
            {
                id: 'status_dokumen',
                header: 'Status Dokumen',
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
        <ReportPageLayout<DisposalReportRow>
            title="Laporan Pemusnahan Aset"
            description="Daftar penghapusan aset rusak berat atau tidak bernilai ekonomis beserta berita acara pemusnahan."
            reportCode="laporan-pemusnahan-aset"
            filters={filters}
            filterBar={
                <ReportFilterBar
                    filters={filters}
                    onFilterChange={updateFilter}
                    onReset={resetFilters}
                    onRefresh={refetch}
                    loading={loading}
                />
            }
            columns={columns}
            rows={rows}
            loading={loading}
            error={error}
            onRefresh={refetch}
        />
    );
}
