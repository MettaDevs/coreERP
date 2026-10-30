import { useMemo } from 'react';
import type { DataTableColumn } from '@apperp/ui/data-table';
import { ReportPageLayout } from './_shared/ReportPageLayout';
import { useReportData } from './_shared/useReportData';

/** Satu baris proyeksi: satu buku aset pada satu periode ke depan. */
export type ProjectionReportRow = {
    nomor: number;
    kode: string;
    nama: string;
    group: string;
    buku: string;
    periode: string;
    akhir_periode: string;
    penyusutan: string;
    akumulasi_penyusutan: string;
    nilai_buku: string;
};

const shown = (value: string | number | null | undefined) =>
    value === null || value === undefined || value === '' ? '—' : String(value);

const moneyColumn = (
    id: keyof ProjectionReportRow,
    header: string,
): DataTableColumn<ProjectionReportRow> => ({
    id,
    header,
    cell: (row) => shown(row[id]),
    align: 'right',
});

export default function LaporanProyeksiPenyusutanAsetPage() {
    const { filterState, rows, fields, loading, error, refetch } =
        useReportData<ProjectionReportRow>('laporan-proyeksi-penyusutan-aset');

    const columns: DataTableColumn<ProjectionReportRow>[] = useMemo(
        () => [
            {
                id: 'kode',
                header: 'Kode aset',
                cell: (row) => (
                    <span className="font-mono text-xs">{shown(row.kode)}</span>
                ),
            },
            { id: 'nama', header: 'Nama aset', cell: (row) => shown(row.nama) },
            { id: 'buku', header: 'Buku', cell: (row) => shown(row.buku) },
            {
                id: 'periode',
                header: 'Periode',
                cell: (row) => shown(row.periode),
            },
            moneyColumn('penyusutan', 'Penyusutan'),
            moneyColumn('akumulasi_penyusutan', 'Akumulasi penyusutan'),
            moneyColumn('nilai_buku', 'Nilai buku'),
        ],
        [],
    );

    return (
        <ReportPageLayout<ProjectionReportRow>
            title="Proyeksi penyusutan aset"
            description="Penyusutan per periode ke depan dari keadaan buku aset sekarang, dihitung dengan aturan yang sama dengan proses penyusutan. Paling panjang 60 bulan; tanpa pilihan, 12 bulan mulai bulan ini."
            filterState={filterState}

            columns={columns}
            rows={rows}
            loading={loading}
            error={error}
            totalSummary={
                rows.length > 0 && (
                    <div className="flex flex-wrap items-center justify-between gap-2 text-sm font-semibold">
                        <span>
                            Total proyeksi penyusutan (
                            {shown(fields.jumlah_aset)} buku aset)
                        </span>
                        <span className="font-mono">
                            {shown(fields.total_penyusutan)}
                        </span>
                    </div>
                )
            }
            onRefresh={refetch}
        />
    );
}
