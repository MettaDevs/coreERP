import { useMemo } from 'react';
import { Badge } from '@apperp/ui/badge';
import type { DataTableColumn } from '@apperp/ui/data-table';
import { ReportPageLayout } from './_shared/ReportPageLayout';
import { useReportData } from './_shared/useReportData';

/** Satu baris dataset `laporan-pemeliharaan-aset`: satu aset yang dikerjakan pada satu work order. */
export type MaintenanceReportRow = {
    nomor?: number;
    no_bukti?: string;
    tanggal?: string;
    asset_kode?: string;
    asset_nama?: string;
    spesifikasi?: string;
    satuan?: string;
    jumlah?: number | string;
    checklist?: string;
    analisa_perbaikan?: string;
    jenis_pemeliharaan?: string;
    jenis_pekerjaan?: string;
    lokasi?: string;
    unit_organisasi?: string;
    pic?: string;
    tingkat_layanan?: string;
    status?: string;
    catatan?: string;
} & Record<string, unknown>;

const teks = (value: unknown) =>
    value === null || value === undefined || value === '' ? '—' : String(value);

export default function LaporanPemeliharaanAsetPage() {
    const { filterState, rows, loading, error, refetch } =
        useReportData<MaintenanceReportRow>('laporan-pemeliharaan-aset');

    const columns: DataTableColumn<MaintenanceReportRow>[] = useMemo(
        () => [
            {
                id: 'no_bukti',
                header: 'No. bukti',
                cell: (row) => (
                    <span className="font-mono text-xs font-semibold">
                        {teks(row.no_bukti)}
                    </span>
                ),
            },
            {
                id: 'tanggal',
                header: 'Tgl work order',
                cell: (row) => teks(row.tanggal),
            },
            {
                id: 'asset_kode',
                header: 'Kode aset',
                cell: (row) => (
                    <span className="font-mono text-xs">
                        {teks(row.asset_kode)}
                    </span>
                ),
            },
            {
                id: 'asset_nama',
                header: 'Item aset',
                cell: (row) => teks(row.asset_nama),
            },
            {
                id: 'spesifikasi',
                header: 'Spesifikasi',
                cell: (row) => teks(row.spesifikasi),
            },
            {
                id: 'satuan',
                header: 'Satuan',
                cell: (row) => teks(row.satuan),
                align: 'center',
                width: 70,
            },
            {
                id: 'jumlah',
                header: 'Jumlah',
                cell: (row) => teks(row.jumlah),
                align: 'right',
                width: 70,
            },
            {
                id: 'checklist',
                header: 'Item checklist',
                cell: (row) => (
                    <span className="text-muted-foreground text-xs">
                        {teks(row.checklist)}
                    </span>
                ),
            },
            {
                id: 'analisa_perbaikan',
                header: 'Analisa perbaikan',
                cell: (row) => teks(row.analisa_perbaikan),
            },
            {
                id: 'jenis_pemeliharaan',
                header: 'Jenis pemeliharaan',
                cell: (row) => teks(row.jenis_pemeliharaan),
            },
            {
                id: 'jenis_pekerjaan',
                header: 'Jenis pekerjaan',
                cell: (row) => teks(row.jenis_pekerjaan),
            },
            {
                id: 'lokasi',
                header: 'Lokasi',
                cell: (row) => teks(row.lokasi),
            },
            {
                id: 'unit_organisasi',
                header: 'Unit organisasi',
                cell: (row) => teks(row.unit_organisasi),
            },
            {
                id: 'pic',
                header: 'PIC',
                cell: (row) => teks(row.pic),
            },
            {
                id: 'tingkat_layanan',
                header: 'Tingkat layanan',
                cell: (row) => teks(row.tingkat_layanan),
            },
            {
                id: 'status',
                header: 'Status',
                cell: (row) => (
                    <Badge variant="secondary">{teks(row.status)}</Badge>
                ),
                align: 'center',
            },
            {
                id: 'catatan',
                header: 'Catatan',
                cell: (row) => teks(row.catatan),
            },
        ],
        [],
    );

    return (
        <ReportPageLayout<MaintenanceReportRow>
            title="Laporan pemeliharaan aset"
            description="Riwayat perawatan dan perbaikan aset, satu baris per aset yang dikerjakan, beserta checklist, analisa perbaikan, dan teknisinya."
            filterState={filterState}

            columns={columns}
            rows={rows}
            loading={loading}
            error={error}
            onRefresh={refetch}
        />
    );
}
