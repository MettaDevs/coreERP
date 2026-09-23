import { useMemo } from 'react';
import type { DataTableColumn } from '@apperp/ui/data-table';
import { ReportFilterBar } from './_shared/ReportFilterBar';
import { ReportPageLayout } from './_shared/ReportPageLayout';
import { useReportData } from './_shared/useReportData';

export type MutationReportRow = {
    nomor?: number | string;
    tanggal_mutasi?: string;
    nomor_bukti?: string;
    kode_aset?: string;
    nama_aset?: string;
    spesifikasi?: string;
    lokasi_asal?: string;
    lokasi_tujuan?: string;
    penanggung_jawab?: string;
    pic_penerima?: string;
    kondisi_aset?: string;
    keterangan?: string;
} & Record<string, unknown>;

export default function LaporanMutasiAsetPage() {
    const {
        filters,
        updateFilter,
        resetFilters,
        rows,
        loading,
        error,
        refetch,
    } = useReportData<MutationReportRow>('laporan-mutasi-aset');

    const columns: DataTableColumn<MutationReportRow>[] = useMemo(
        () => [
            {
                id: 'tanggal_mutasi',
                header: 'Tgl Mutasi',
                cell: (row) => String(row.tanggal_mutasi ?? '-'),
            },
            {
                id: 'nomor_bukti',
                header: 'No. Bukti Mutasi',
                cell: (row) => (
                    <span className="font-mono text-xs font-semibold">
                        {String(row.nomor_bukti ?? row.no_bukti ?? '-')}
                    </span>
                ),
            },
            {
                id: 'kode_aset',
                header: 'Kode Aset',
                cell: (row) => (
                    <span className="font-mono text-xs">
                        {String(row.kode_aset ?? row.asset_kode ?? '-')}
                    </span>
                ),
            },
            {
                id: 'nama_aset',
                header: 'Nama Aset',
                cell: (row) => String(row.nama_aset ?? row.asset_nama ?? '-'),
            },
            {
                id: 'spesifikasi',
                header: 'Spesifikasi',
                cell: (row) => String(row.spesifikasi ?? '-'),
            },
            {
                id: 'lokasi_asal',
                header: 'Lokasi Asal',
                cell: (row) => String(row.lokasi_asal ?? '-'),
            },
            {
                id: 'lokasi_tujuan',
                header: 'Lokasi Tujuan',
                cell: (row) => String(row.lokasi_tujuan ?? '-'),
            },
            {
                id: 'penanggung_jawab',
                header: 'Penanggung Jawab',
                cell: (row) => String(row.penanggung_jawab ?? '-'),
            },
            {
                id: 'pic_penerima',
                header: 'PIC Penerima',
                cell: (row) => String(row.pic_penerima ?? '-'),
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
                id: 'keterangan',
                header: 'Keterangan',
                cell: (row) => (
                    <span className="text-muted-foreground text-xs">
                        {String(row.keterangan ?? '-')}
                    </span>
                ),
            },
        ],
        [],
    );

    return (
        <ReportPageLayout<MutationReportRow>
            title="Laporan Mutasi Aset"
            description="Histori perpindahan lokasi, penanggung jawab, dan serah terima aset antar unit organisasi."
            reportCode="laporan-mutasi-aset"
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
