import { useMemo } from 'react';
import type { DataTableColumn } from '@apperp/ui/data-table';
import { ReportFilterBar } from './_shared/ReportFilterBar';
import { ReportPageLayout } from './_shared/ReportPageLayout';
import { useReportData } from './_shared/useReportData';

export type DepreciationReportRow = {
    nomor?: number;
    asset_kode?: string;
    asset_nama?: string;
    spesifikasi?: string;
    group_aset?: string;
    golongan_aset?: string;
    jenis_aset?: string;
    bulan_perolehan?: string;
    tahun_perolehan?: string;
    ue_tahun?: number;
    ue_bulan?: number;
    ue_saat_ini?: number;
    sisa_ue_bulan?: number;
    persentase_penyusutan?: string;
    nilai_perolehan?: string;
    penyusutan_tahun?: string;
    penyusutan_bulan?: string;
    akumulasi_penyusutan?: string;
    nilai_buku_akhir?: string;
} & Record<string, unknown>;

export default function LaporanPenyusutanAsetPage() {
    const {
        filters,
        updateFilter,
        resetFilters,
        rows,
        loading,
        error,
        refetch,
    } = useReportData<DepreciationReportRow>('laporan-penyusutan-aset');

    const columns: DataTableColumn<DepreciationReportRow>[] = useMemo(
        () => [
            {
                id: 'asset_kode',
                header: 'Kode Aset',
                cell: (row) => (
                    <span className="font-mono text-xs font-semibold">
                        {String(row.asset_kode ?? '-')}
                    </span>
                ),
            },
            {
                id: 'asset_nama',
                header: 'Nama Aset',
                cell: (row) => String(row.asset_nama ?? '-'),
            },
            {
                id: 'group_aset',
                header: 'Group Aset',
                cell: (row) => String(row.group_aset ?? '-'),
            },
            {
                id: 'nilai_perolehan',
                header: 'Nilai Perolehan',
                cell: (row) => String(row.nilai_perolehan ?? '-'),
                align: 'right',
            },
            {
                id: 'akumulasi_penyusutan',
                header: 'Akum. Penyusutan',
                cell: (row) => String(row.akumulasi_penyusutan ?? '-'),
                align: 'right',
            },
            {
                id: 'nilai_buku_akhir',
                header: 'Nilai Buku Akhir',
                cell: (row) => (
                    <span className="text-primary font-semibold">
                        {String(row.nilai_buku_akhir ?? '-')}
                    </span>
                ),
                align: 'right',
            },
        ],
        [],
    );

    return (
        <ReportPageLayout<DepreciationReportRow>
            title="Laporan Penyusutan Aset"
            description="Perhitungan amortisasi dan penyusutan nilai buku aset tetap per periode buku fiskal."
            reportCode="laporan-penyusutan-aset"
            filters={filters}
            filterBar={
                <ReportFilterBar
                    filters={filters}
                    onFilterChange={updateFilter}
                    onReset={resetFilters}
                    onRefresh={refetch}
                    loading={loading}
                    showSingleMonth={true}
                    showDateRange={false}
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
