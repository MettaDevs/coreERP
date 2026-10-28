import { useMemo } from 'react';
import type { DataTableColumn } from '@apperp/ui/data-table';
import { ReportPageLayout } from './_shared/ReportPageLayout';
import { useReportData } from './_shared/useReportData';

/**
 * Satu baris laporan mutasi nilai buku, satu buku aset. Uang dan tanggal sudah diformat Core persis seperti
 * hasil cetaknya.
 */
export type BookValueReportRow = {
    nomor: number;
    kode: string;
    nama: string;
    group: string;
    buku: string;
    tanggal_perolehan: string;
    harga_perolehan_awal: string;
    perolehan: string;
    reklasifikasi_harga_perolehan: string;
    pelepasan_harga_perolehan: string;
    harga_perolehan_akhir: string;
    akumulasi_awal: string;
    penyusutan: string;
    reklasifikasi_akumulasi: string;
    pelepasan_akumulasi: string;
    akumulasi_akhir: string;
    nilai_buku_awal: string;
    penurunan_nilai: string;
    kenaikan_nilai: string;
    reklasifikasi_masuk: string;
    reklasifikasi_keluar: string;
    pelepasan: string;
    nilai_buku_akhir: string;
};

const shown = (value: string | number | null | undefined) =>
    value === null || value === undefined || value === '' ? '—' : String(value);

const moneyColumn = (
    id: keyof BookValueReportRow,
    header: string,
): DataTableColumn<BookValueReportRow> => ({
    id,
    header,
    cell: (row) => shown(row[id]),
    align: 'right',
});

export default function LaporanNilaiBukuAsetPage() {
    const { filterState, rows, fields, loading, error, refetch } =
        useReportData<BookValueReportRow>('laporan-nilai-buku-aset');

    const columns: DataTableColumn<BookValueReportRow>[] = useMemo(
        () => [
            {
                id: 'kode',
                header: 'Kode aset',
                cell: (row) => (
                    <span className="font-mono text-xs">{shown(row.kode)}</span>
                ),
            },
            { id: 'nama', header: 'Nama aset', cell: (row) => shown(row.nama) },
            { id: 'group', header: 'Group', cell: (row) => shown(row.group) },
            { id: 'buku', header: 'Buku', cell: (row) => shown(row.buku) },
            moneyColumn('nilai_buku_awal', 'Nilai buku awal'),
            moneyColumn('perolehan', 'Perolehan'),
            moneyColumn('penyusutan', 'Penyusutan'),
            moneyColumn('penurunan_nilai', 'Penurunan nilai'),
            moneyColumn('kenaikan_nilai', 'Kenaikan nilai'),
            moneyColumn('reklasifikasi_masuk', 'Reklasifikasi masuk'),
            moneyColumn('reklasifikasi_keluar', 'Reklasifikasi keluar'),
            moneyColumn('pelepasan', 'Pelepasan'),
            {
                id: 'nilai_buku_akhir',
                header: 'Nilai buku akhir',
                cell: (row) => (
                    <span className="text-primary font-semibold">
                        {shown(row.nilai_buku_akhir)}
                    </span>
                ),
                align: 'right',
            },
            moneyColumn('harga_perolehan_akhir', 'Harga perolehan akhir'),
            moneyColumn('akumulasi_akhir', 'Akumulasi penyusutan akhir'),
        ],
        [],
    );

    return (
        <ReportPageLayout<BookValueReportRow>
            title="Laporan mutasi nilai buku aset"
            description="Saldo awal, perolehan, penyusutan, penyesuaian nilai, reklasifikasi, pelepasan, dan saldo akhir per aset dalam rentang tanggal. Hasil cetaknya juga memuat harga perolehan dan akumulasi penyusutan dari awal sampai akhir."
            filterState={filterState}

            columns={columns}
            rows={rows}
            loading={loading}
            error={error}
            totalSummary={
                rows.length > 0 && (
                    <div className="flex flex-wrap items-center justify-between gap-2 text-sm font-semibold">
                        <span>
                            Nilai buku awal{' '}
                            {shown(fields.total_nilai_buku_awal)} menjadi akhir
                            ({shown(fields.jumlah_aset)} buku aset)
                        </span>
                        <span className="font-mono">
                            {shown(fields.total_nilai_buku_akhir)}
                        </span>
                    </div>
                )
            }
            onRefresh={refetch}
        />
    );
}
