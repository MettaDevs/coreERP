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

/** Satu baris dataset `daftar-mutasi-aset`: satu aset pada satu dokumen mutasi yang selesai. */
export type MutationReportRow = {
    tanggal?: string | null;
    kode?: string | null;
    aset_kode?: string | null;
    aset_nama?: string | null;
    serial_number?: string | null;
    asal_lokasi?: string | null;
    tujuan_lokasi?: string | null;
    asal_unit_kerja?: string | null;
    tujuan_unit_kerja?: string | null;
    diserahkan_oleh?: string | null;
    diterima_oleh?: string | null;
    kondisi?: string | null;
    alasan?: string | null;
    keterangan?: string | null;
} & Record<string, unknown>;

const teks = (value: unknown) =>
    value === null || value === undefined || value === '' ? '—' : String(value);

export default function LaporanMutasiAsetPage() {
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
    } = useReportData<MutationReportRow>('daftar-mutasi-aset');

    const columns: DataTableColumn<MutationReportRow>[] = useMemo(
        () => [
            {
                id: 'tanggal',
                header: 'Tgl mutasi',
                cell: (row) => teks(row.tanggal),
            },
            {
                id: 'kode',
                header: 'No. bukti mutasi',
                cell: (row) => (
                    <span className="font-mono text-xs font-semibold">
                        {teks(row.kode)}
                    </span>
                ),
            },
            {
                id: 'aset_kode',
                header: 'Kode aset',
                cell: (row) => (
                    <span className="font-mono text-xs">
                        {teks(row.aset_kode)}
                    </span>
                ),
            },
            {
                id: 'aset_nama',
                header: 'Nama aset',
                cell: (row) => teks(row.aset_nama),
            },
            {
                id: 'serial_number',
                header: 'Nomor seri',
                cell: (row) => teks(row.serial_number),
            },
            {
                id: 'asal_lokasi',
                header: 'Lokasi asal',
                cell: (row) => teks(row.asal_lokasi),
            },
            {
                id: 'tujuan_lokasi',
                header: 'Lokasi tujuan',
                cell: (row) => teks(row.tujuan_lokasi),
            },
            {
                id: 'asal_unit_kerja',
                header: 'Unit asal',
                cell: (row) => teks(row.asal_unit_kerja),
            },
            {
                id: 'tujuan_unit_kerja',
                header: 'Unit tujuan',
                cell: (row) => teks(row.tujuan_unit_kerja),
            },
            {
                id: 'diserahkan_oleh',
                header: 'Diserahkan oleh',
                cell: (row) => teks(row.diserahkan_oleh),
            },
            {
                id: 'diterima_oleh',
                header: 'PIC penerima',
                cell: (row) => teks(row.diterima_oleh),
            },
            {
                id: 'kondisi',
                header: 'Kondisi aset',
                cell: (row) => teks(row.kondisi),
            },
            {
                id: 'alasan',
                header: 'Alasan mutasi',
                cell: (row) => teks(row.alasan),
            },
            {
                id: 'keterangan',
                header: 'Keterangan',
                cell: (row) => (
                    <span className="text-muted-foreground text-xs">
                        {teks(row.keterangan)}
                    </span>
                ),
            },
        ],
        [],
    );

    return (
        <ReportPageLayout<MutationReportRow>
            title="Laporan mutasi aset"
            description="Perpindahan aset yang sudah selesai, satu baris per aset: dari mana ke mana, siapa yang menyerahkan dan menerima, dan kondisinya."
            reportCode="daftar-mutasi-aset"
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
