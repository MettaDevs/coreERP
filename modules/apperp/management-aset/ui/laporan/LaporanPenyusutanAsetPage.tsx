import { useMemo } from 'react';
import type { DataTableColumn } from '@apperp/ui/data-table';
import { ReportFilterBar } from './_shared/ReportFilterBar';
import {
    AssetFilter,
    AssetGroupFilter,
    AssetTypeFilter,
    ConditionFilter,
    DepreciationBookFilter,
    FiscalClassificationFilter,
    LocationFilter,
    PeriodFilter,
} from './_shared/ReportFilters';
import { ReportPageLayout } from './_shared/ReportPageLayout';
import { useReportData } from './_shared/useReportData';

/**
 * Satu baris laporan penyusutan, satu buku aset. Uang, angka, persen, dan bulan sudah
 * diformat Core persis seperti hasil cetaknya; nilai kosong datang sebagai teks kosong.
 */
export type DepreciationReportRow = {
    nomor: number;
    kode: string;
    nama: string;
    spesifikasi: string;
    group: string;
    golongan: string;
    jenis: string;
    buku: string;
    bulan_perolehan: string;
    umur_ekonomis_tahun: string;
    umur_ekonomis_bulan: string;
    umur_ekonomis_saat_ini: string;
    sisa_umur_ekonomis_bulan: string;
    persentase_penyusutan: string;
    nilai_perolehan: string;
    penyusutan_bulan_ini: string;
    penyusutan_tahun_berjalan: string;
    akumulasi_penyusutan: string;
    nilai_buku_akhir: string;
};

const shown = (value: string | number | null | undefined) =>
    value === null || value === undefined || value === '' ? '—' : String(value);

/** Kolom angka: rata kanan, isinya teks yang sudah diformat Core. */
const numberColumn = (
    id: keyof DepreciationReportRow,
    header: string,
): DataTableColumn<DepreciationReportRow> => ({
    id,
    header,
    cell: (row) => shown(row[id]),
    align: 'right',
});

export default function LaporanPenyusutanAsetPage() {
    const {
        filters,
        bindFilter,
        bindMultiFilter,
        presets,
        hasActiveFilters,
        resetFilters,
        rows,
        loading,
        error,
        refetch,
    } = useReportData<DepreciationReportRow>('laporan-penyusutan-aset');

    const columns: DataTableColumn<DepreciationReportRow>[] = useMemo(
        () => [
            {
                id: 'kode',
                header: 'Kode aset',
                cell: (row) => (
                    <span className="text-primary font-mono text-xs font-semibold">
                        {shown(row.kode)}
                    </span>
                ),
            },
            { id: 'nama', header: 'Nama aset', cell: (row) => shown(row.nama) },
            {
                id: 'spesifikasi',
                header: 'Spesifikasi',
                cell: (row) => (
                    <span className="text-muted-foreground text-xs">
                        {shown(row.spesifikasi)}
                    </span>
                ),
            },
            {
                id: 'group',
                header: 'Group aset',
                cell: (row) => shown(row.group),
            },
            {
                id: 'golongan',
                header: 'Kelompok harta fiskal',
                cell: (row) => shown(row.golongan),
            },
            {
                id: 'jenis',
                header: 'Jenis aset',
                cell: (row) => shown(row.jenis),
            },
            { id: 'buku', header: 'Buku', cell: (row) => shown(row.buku) },
            {
                id: 'bulan_perolehan',
                header: 'Perolehan',
                cell: (row) => shown(row.bulan_perolehan),
            },
            numberColumn('umur_ekonomis_tahun', 'Umur ekonomis (tahun)'),
            numberColumn('umur_ekonomis_bulan', 'Umur ekonomis (bulan)'),
            numberColumn('umur_ekonomis_saat_ini', 'Umur berjalan (bulan)'),
            numberColumn('sisa_umur_ekonomis_bulan', 'Sisa umur (bulan)'),
            numberColumn('persentase_penyusutan', 'Tarif per tahun'),
            numberColumn('nilai_perolehan', 'Nilai perolehan'),
            numberColumn('penyusutan_bulan_ini', 'Penyusutan bulan ini'),
            numberColumn(
                'penyusutan_tahun_berjalan',
                'Penyusutan tahun berjalan',
            ),
            numberColumn('akumulasi_penyusutan', 'Akumulasi penyusutan'),
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
        ],
        [],
    );

    return (
        <ReportPageLayout<DepreciationReportRow>
            title="Laporan penyusutan aset"
            description="Penyusutan, akumulasi, dan nilai buku tiap aset pada bulan yang dipilih, dibaca dari catatan buku aset."
            reportCode="laporan-penyusutan-aset"
            filters={filters}
            filterBar={
                <ReportFilterBar
                    canReset={hasActiveFilters}
                    onReset={resetFilters}
                    presets={presets}
                    dates={{ month: 'periode' }}
                >
                    <PeriodFilter {...bindFilter('periode')} />
                    <DepreciationBookFilter {...bindFilter('buku_id')} />
                    <AssetGroupFilter {...bindMultiFilter('group_aset_id')} />
                    <FiscalClassificationFilter
                        {...bindMultiFilter('kelompok_harta_fiskal_id')}
                    />
                    <AssetTypeFilter {...bindMultiFilter('jenis_aset_id')} />
                    <LocationFilter {...bindMultiFilter('lokasi_aset_id')} />
                    <ConditionFilter {...bindMultiFilter('kondisi_aset_id')} />
                    <AssetFilter {...bindFilter('asset_id')} />
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
