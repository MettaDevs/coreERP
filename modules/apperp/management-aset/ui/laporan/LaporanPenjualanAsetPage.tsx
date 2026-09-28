import { useMemo } from 'react';
import type { DataTableColumn } from '@apperp/ui/data-table';
import { ReportFilterBar } from './_shared/ReportFilterBar';
import {
    AssetFilter,
    AssetGroupFilter,
    AssetTypeFilter,
    DateFilter,
    DepreciationBookFilter,
    FiscalClassificationFilter,
} from './_shared/ReportFilters';
import { ReportPageLayout } from './_shared/ReportPageLayout';
import { useReportData } from './_shared/useReportData';

/**
 * Satu baris laporan penjualan, satu aset yang dijual. Uang dan tanggal sudah diformat Core
 * persis seperti hasil cetaknya; nilai yang tidak ada datang sebagai teks kosong.
 */
export type SaleReportRow = {
    nomor: number;
    no_bukti: string;
    tanggal_penjualan: string;
    kode_aset: string;
    nama_aset: string;
    spesifikasi: string;
    buku: string;
    nilai_penjualan: string;
    nilai_buku: string;
    laba_rugi: string;
    keterangan: string;
};

const shown = (value: string | number | null | undefined) =>
    value === null || value === undefined || value === '' ? '—' : String(value);

/** Kolom uang: rata kanan, isinya teks yang sudah diformat Core. */
const moneyColumn = (
    id: keyof SaleReportRow,
    header: string,
): DataTableColumn<SaleReportRow> => ({
    id,
    header,
    cell: (row) => shown(row[id]),
    align: 'right',
});

export default function LaporanPenjualanAsetPage() {
    const {
        filters,
        bindFilter,
        hasActiveFilters,
        resetFilters,
        rows,
        fields,
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
                    <span className="text-primary font-mono text-xs font-semibold">
                        {shown(row.no_bukti)}
                    </span>
                ),
            },
            {
                id: 'tanggal_penjualan',
                header: 'Tanggal penjualan',
                cell: (row) => shown(row.tanggal_penjualan),
            },
            {
                id: 'kode_aset',
                header: 'Kode aset',
                cell: (row) => (
                    <span className="font-mono text-xs">
                        {shown(row.kode_aset)}
                    </span>
                ),
            },
            {
                id: 'nama_aset',
                header: 'Nama aset',
                cell: (row) => shown(row.nama_aset),
            },
            {
                id: 'spesifikasi',
                header: 'Spesifikasi',
                cell: (row) => (
                    <span className="text-muted-foreground text-xs">
                        {shown(row.spesifikasi)}
                    </span>
                ),
            },
            { id: 'buku', header: 'Buku', cell: (row) => shown(row.buku) },
            moneyColumn('nilai_penjualan', 'Nilai penjualan'),
            moneyColumn('nilai_buku', 'Nilai buku saat dijual'),
            moneyColumn('laba_rugi', 'Laba / rugi'),
            {
                id: 'keterangan',
                header: 'Keterangan',
                cell: (row) => (
                    <span className="text-muted-foreground text-xs">
                        {shown(row.keterangan)}
                    </span>
                ),
            },
        ],
        [],
    );

    return (
        <ReportPageLayout<SaleReportRow>
            title="Laporan penjualan aset"
            description="Aset yang dijual dalam rentang tanggal, beserta nilai penjualan, nilai buku saat dijual, dan laba/ruginya."
            reportCode="laporan-penjualan-aset"
            filters={filters}
            filterBar={
                <ReportFilterBar
                    canReset={hasActiveFilters}
                    onReset={resetFilters}
                >
                    <DateFilter label="Dari tanggal" {...bindFilter('dari')} />
                    <DateFilter
                        label="Sampai tanggal"
                        {...bindFilter('sampai')}
                    />
                    <DepreciationBookFilter {...bindFilter('buku_id')} />
                    <AssetGroupFilter {...bindFilter('group_aset_id')} />
                    <FiscalClassificationFilter
                        {...bindFilter('kelompok_harta_fiskal_id')}
                    />
                    <AssetTypeFilter {...bindFilter('jenis_aset_id')} />
                    <AssetFilter {...bindFilter('asset_id')} />
                </ReportFilterBar>
            }
            columns={columns}
            rows={rows}
            loading={loading}
            error={error}
            // Total dari dataset, sama dengan baris total hasil cetak; tidak dijumlah ulang di
            // sini dari teks yang sudah diformat.
            totalSummary={
                rows.length > 0 && (
                    <div className="flex flex-wrap items-center justify-between gap-2 text-sm font-semibold">
                        <span>
                            Total nilai penjualan (
                            {shown(fields.jumlah_penjualan)} penjualan)
                        </span>
                        <span className="font-mono">
                            {shown(fields.total_nilai_penjualan)}
                        </span>
                    </div>
                )
            }
            onRefresh={refetch}
        />
    );
}
