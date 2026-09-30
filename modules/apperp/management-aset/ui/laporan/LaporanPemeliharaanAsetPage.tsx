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
        bindFilter,
        bindMultiFilter,
        additional,
        hasActiveFilters,
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
                header: 'No. bukti',
                cell: (row) => (
                    <span className="font-mono text-xs font-semibold">
                        {String(row.no_bukti ?? row.kode ?? '-')}
                    </span>
                ),
            },
            {
                id: 'tanggal',
                header: 'Tgl work order',
                cell: (row) => String(row.tanggal ?? row.dibuat_pada ?? '-'),
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
                header: 'Item checklist',
                cell: (row) => (
                    <span className="text-muted-foreground text-xs">
                        {String(row.checklist ?? '-')}
                    </span>
                ),
            },
            {
                id: 'analisa_perbaikan',
                header: 'Analisa perbaikan',
                cell: (row) => String(row.analisa_perbaikan ?? '-'),
            },
            {
                id: 'jenis_pemeliharaan',
                header: 'Jenis pemeliharaan',
                cell: (row) =>
                    String(
                        row.jenis_pemeliharaan ?? row.tipe_work_order ?? '-',
                    ),
            },
            {
                id: 'unit_organisasi',
                header: 'Unit organisasi',
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
            title="Laporan pemeliharaan aset"
            description="Daftar pelaksanaan perawatan berkala dan perbaikan aset beserta riwayat checklist dan teknisi."
            reportCode="laporan-pemeliharaan-aset"
            filters={filters}
            filterBar={
                <ReportFilterBar
                    canReset={hasActiveFilters}
                    onReset={resetFilters}
                    additional={additional}
                >
                    <AssetGroupFilter {...bindMultiFilter('group_aset_id')} />
                    <FiscalClassificationFilter
                        {...bindMultiFilter('kelompok_harta_fiskal_id')}
                    />
                    <AssetTypeFilter {...bindMultiFilter('jenis_aset_id')} />
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
