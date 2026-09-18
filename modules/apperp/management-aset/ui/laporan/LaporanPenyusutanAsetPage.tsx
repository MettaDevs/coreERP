import { useMemo } from 'react';
import type { DataTableColumn } from '@apperp/ui/data-table';
import { ReportFilterBar } from './_shared/ReportFilterBar';
import { ReportPageLayout } from './_shared/ReportPageLayout';
import { useReportData } from './_shared/useReportData';

export type DepreciationReportRow = {
    nomor?: number;
    asset_kode?: string;
    kode?: string;
    asset_nama?: string;
    nama?: string;
    spesifikasi?: string;
    group_aset?: string;
    group?: string;
    golongan_aset?: string;
    golongan?: string;
    jenis_aset?: string;
    jenis?: string;
    bulan_perolehan?: string;
    tahun_perolehan?: string;
    ue_tahun?: number;
    umur_ekonomis_tahun?: number;
    ue_bulan?: number;
    umur_ekonomis_bulan?: number;
    ue_saat_ini?: number;
    umur_ekonomis_saat_ini?: number;
    sisa_ue_bulan?: number;
    sisa_umur_ekonomis_bulan?: number;
    persentase_penyusutan?: string;
    nilai_perolehan?: string;
    penyusutan_tahun?: string;
    penyusutan_per_tahun?: string;
    penyusutan_bulan?: string;
    penyusutan_per_bulan?: string;
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
                    <span className="text-primary font-mono text-xs font-semibold">
                        {String(row.asset_kode ?? row.kode ?? '-')}
                    </span>
                ),
            },
            {
                id: 'asset_nama',
                header: 'Nama Aset',
                cell: (row) => String(row.asset_nama ?? row.nama ?? '-'),
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
                id: 'group_aset',
                header: 'Group Aset',
                cell: (row) => String(row.group_aset ?? row.group ?? '-'),
            },
            {
                id: 'golongan_aset',
                header: 'Golongan',
                cell: (row) => String(row.golongan_aset ?? row.golongan ?? '-'),
            },
            {
                id: 'jenis_aset',
                header: 'Jenis Aset',
                cell: (row) => String(row.jenis_aset ?? row.jenis ?? '-'),
            },
            {
                id: 'perolehan',
                header: 'Perolehan',
                cell: (row) => {
                    const bln = row.bulan_perolehan ?? '';
                    const thn = row.tahun_perolehan ?? '';
                    return bln || thn ? `${bln} ${thn}`.trim() : '-';
                },
                align: 'center',
            },
            {
                id: 'ue_tahun',
                header: 'UE (Thn)',
                cell: (row) =>
                    Number(row.ue_tahun ?? row.umur_ekonomis_tahun ?? 0),
                align: 'right',
            },
            {
                id: 'ue_bulan',
                header: 'UE (Bln)',
                cell: (row) =>
                    Number(row.ue_bulan ?? row.umur_ekonomis_bulan ?? 0),
                align: 'right',
            },
            {
                id: 'ue_saat_ini',
                header: 'UE Berjalan',
                cell: (row) =>
                    Number(
                        row.ue_saat_ini ?? row.umur_ekonomis_saat_ini ?? 0,
                    ),
                align: 'right',
            },
            {
                id: 'sisa_ue_bulan',
                header: 'Sisa UE',
                cell: (row) =>
                    Number(
                        row.sisa_ue_bulan ?? row.sisa_umur_ekonomis_bulan ?? 0,
                    ),
                align: 'right',
            },
            {
                id: 'persentase_penyusutan',
                header: '% Susut',
                cell: (row) => String(row.persentase_penyusutan ?? '-'),
                align: 'center',
            },
            {
                id: 'nilai_perolehan',
                header: 'Nilai Perolehan',
                cell: (row) => String(row.nilai_perolehan ?? '-'),
                align: 'right',
            },
            {
                id: 'penyusutan_tahun',
                header: 'Susut / Thn',
                cell: (row) =>
                    String(
                        row.penyusutan_tahun ??
                            row.penyusutan_per_tahun ??
                            '-',
                    ),
                align: 'right',
            },
            {
                id: 'penyusutan_bulan',
                header: 'Susut / Bln',
                cell: (row) =>
                    String(
                        row.penyusutan_bulan ??
                            row.penyusutan_per_bulan ??
                            '-',
                    ),
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
