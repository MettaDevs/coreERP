import { useMemo } from 'react';
import type { DataTableColumn } from '@apperp/ui/data-table';
import { ReportFilterBar } from './_shared/ReportFilterBar';
import { ReportPageLayout } from './_shared/ReportPageLayout';
import { useReportData } from './_shared/useReportData';

export type MaintenanceReportRow = {
    nomor?: number;
    no_bukti?: string;
    tanggal?: string;
    asset_kode?: string;
    asset_nama?: string;
    spesifikasi?: string;
    satuan?: string;
    jumlah?: number;
    checklist?: string;
    analisa_perbaikan?: string;
    jenis_pemeliharaan?: string;
    unit_organisasi?: string;
    pic?: string;
    status?: string;
} & Record<string, unknown>;

export default function LaporanPemeliharaanAsetPage() {
    const {
        filters,
        updateFilter,
        resetFilters,
        rows,
        loading,
        error,
        refetch,
    } = useReportData<MaintenanceReportRow>('laporan-pemeliharaan-aset');

    const columns: DataTableColumn<MaintenanceReportRow>[] = useMemo(
        () => [
            {
                id: 'no_bukti',
                header: 'No. Bukti',
                cell: (row) => (
                    <span className="font-mono text-xs font-semibold">
                        {String(row.no_bukti ?? row.kode ?? '-')}
                    </span>
                ),
            },
            {
                id: 'tanggal',
                header: 'Tgl Work Order',
                cell: (row) => String(row.tanggal ?? row.dibuat_pada ?? '-'),
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
                id: 'satuan',
                header: 'Satuan',
                cell: (row) => String(row.satuan ?? 'Unit'),
                align: 'center',
                width: 70,
            },
            {
                id: 'jumlah',
                header: 'Jumlah',
                cell: (row) => Number(row.jumlah ?? 1),
                align: 'right',
                width: 70,
            },
            {
                id: 'checklist',
                header: 'Item Checklist',
                cell: (row) => (
                    <span className="text-muted-foreground text-xs">
                        {String(row.checklist ?? '-')}
                    </span>
                ),
            },
            {
                id: 'analisa_perbaikan',
                header: 'Analisa Perbaikan',
                cell: (row) => String(row.analisa_perbaikan ?? '-'),
            },
            {
                id: 'jenis_pemeliharaan',
                header: 'Jenis Pemeliharaan',
                cell: (row) =>
                    String(
                        row.jenis_pemeliharaan ?? row.tipe_work_order ?? '-',
                    ),
            },
            {
                id: 'unit_organisasi',
                header: 'Unit Organisasi',
                cell: (row) => String(row.unit_organisasi ?? '-'),
            },
            {
                id: 'pic',
                header: 'PIC',
                cell: (row) => String(row.pic ?? '-'),
            },
            {
                id: 'status',
                header: 'Status',
                cell: (row) => (
                    <span className="bg-muted text-muted-foreground inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium capitalize">
                        {String(row.status ?? '-')}
                    </span>
                ),
                align: 'center',
            },
        ],
        [],
    );

    return (
        <ReportPageLayout<MaintenanceReportRow>
            title="Laporan Pemeliharaan Aset"
            description="Daftar pelaksanaan perawatan berkala dan perbaikan aset beserta riwayat checklist dan teknisi."
            reportCode="laporan-pemeliharaan-aset"
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
