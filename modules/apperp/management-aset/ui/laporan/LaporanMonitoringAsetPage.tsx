import { useMemo } from 'react';
import type { DataTableColumn } from '@apperp/ui/data-table';
import { ReportFilterBar } from './_shared/ReportFilterBar';
import { ReportPageLayout } from './_shared/ReportPageLayout';
import { useReportData } from './_shared/useReportData';

export type MonitoringReportRow = {
    nomor?: number;
    tgl_monitoring?: string;
    no_bukti?: string;
    asset_kode?: string;
    kode_aset?: string;
    asset_nama?: string;
    nama_aset?: string;
    spesifikasi?: string;
    satuan?: string;
    jumlah?: number;
    kondisi_sistem?: string;
    kondisi_fisik?: string;
    status_monitoring?: string;
    keterangan?: string;
    nilai_perolehan?: string | number;
    akumulasi_penyusutan?: string | number;
    nilai_buku_akhir?: string | number;
    nilai_akhir_buku?: string | number;
    penanggung_jawab?: string;
    unit_organisasi?: string;
} & Record<string, unknown>;

export default function LaporanMonitoringAsetPage() {
    const {
        filters,
        updateFilter,
        resetFilters,
        rows,
        loading,
        error,
        refetch,
    } = useReportData<MonitoringReportRow>('laporan-monitoring-aset');

    const columns: DataTableColumn<MonitoringReportRow>[] = useMemo(
        () => [
            {
                id: 'tgl_monitoring',
                header: 'Tgl Monitoring',
                cell: (row) => String(row.tgl_monitoring ?? '-'),
            },
            {
                id: 'no_bukti',
                header: 'No. Bukti',
                cell: (row) => (
                    <span className="text-primary font-mono text-xs font-semibold">
                        {String(row.no_bukti ?? '-')}
                    </span>
                ),
            },
            {
                id: 'asset_kode',
                header: 'Kode Aset',
                cell: (row) => (
                    <span className="font-mono text-xs">
                        {String(row.asset_kode ?? row.kode_aset ?? '-')}
                    </span>
                ),
            },
            {
                id: 'asset_nama',
                header: 'Nama Aset',
                cell: (row) =>
                    String(row.asset_nama ?? row.nama_aset ?? '-'),
            },
            {
                id: 'spesifikasi',
                header: 'Spesifikasi',
                cell: (row) => (
                    <span className="text-muted-foreground text-xs">
                        {String(row.spesifikasi ?? '-')}
                    </span>
                ),
            },
            {
                id: 'satuan',
                header: 'Satuan',
                cell: (row) => String(row.satuan ?? 'Unit'),
                align: 'center',
            },
            {
                id: 'jumlah',
                header: 'Jumlah',
                cell: (row) => Number(row.jumlah ?? 1),
                align: 'right',
            },
            {
                id: 'kondisi_sistem',
                header: 'Kondisi Sistem',
                cell: (row) => String(row.kondisi_sistem ?? '-'),
            },
            {
                id: 'kondisi_fisik',
                header: 'Kondisi Fisik',
                cell: (row) => String(row.kondisi_fisik ?? '-'),
            },
            {
                id: 'status_monitoring',
                header: 'Status Monitoring',
                cell: (row) => (
                    <span className="bg-muted text-muted-foreground inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium capitalize">
                        {String(row.status_monitoring ?? '-')}
                    </span>
                ),
                align: 'center',
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
                        {String(
                            row.nilai_buku_akhir ??
                                row.nilai_akhir_buku ??
                                '-',
                        )}
                    </span>
                ),
                align: 'right',
            },
            {
                id: 'penanggung_jawab',
                header: 'Penanggung Jawab',
                cell: (row) => String(row.penanggung_jawab ?? '-'),
            },
            {
                id: 'unit_organisasi',
                header: 'Unit Organisasi',
                cell: (row) => String(row.unit_organisasi ?? '-'),
            },
        ],
        [],
    );

    return (
        <ReportPageLayout<MonitoringReportRow>
            title="Laporan Monitoring Aset"
            description="Laporan kondisi fisik, utilisasi, dan pemantauan keberadaan aset di lapangan."
            reportCode="laporan-monitoring-aset"
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
