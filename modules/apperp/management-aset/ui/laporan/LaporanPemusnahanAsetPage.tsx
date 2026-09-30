import { useMemo } from 'react';
import type { DataTableColumn } from '@apperp/ui/data-table';
import { ReportFilterBar } from './_shared/ReportFilterBar';
import {
    AssetFilter,
    AssetGroupFilter,
    AssetTypeFilter,
    ConditionFilter,
    DateFilter,
    DepreciationBookFilter,
    FiscalClassificationFilter,
    LocationFilter,
} from './_shared/ReportFilters';
import { ReportPageLayout } from './_shared/ReportPageLayout';
import { useReportData } from './_shared/useReportData';

/**
 * Satu baris laporan pemusnahan, satu aset yang dimusnahkan. Uang dan tanggal sudah diformat
 * Core persis seperti hasil cetaknya; nilai yang tidak ada datang sebagai teks kosong.
 */
export type DisposalReportRow = {
    nomor: number;
    no_bukti: string;
    tanggal: string;
    kode_aset: string;
    item_aset: string;
    spesifikasi: string;
    kondisi_aset: string;
    buku: string;
    nilai_perolehan: string;
    nilai_buku_akhir: string;
    keterangan: string;
};

const shown = (value: string | number | null | undefined) =>
    value === null || value === undefined || value === '' ? '—' : String(value);

export default function LaporanPemusnahanAsetPage() {
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
    } = useReportData<DisposalReportRow>('laporan-pemusnahan-aset');

    const columns: DataTableColumn<DisposalReportRow>[] = useMemo(
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
                id: 'tanggal',
                header: 'Tanggal pemusnahan',
                cell: (row) => shown(row.tanggal),
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
                id: 'item_aset',
                header: 'Nama aset',
                cell: (row) => shown(row.item_aset),
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
            {
                id: 'kondisi_aset',
                header: 'Kondisi aset',
                cell: (row) => shown(row.kondisi_aset),
            },
            { id: 'buku', header: 'Buku', cell: (row) => shown(row.buku) },
            {
                id: 'nilai_perolehan',
                header: 'Nilai perolehan',
                cell: (row) => shown(row.nilai_perolehan),
                align: 'right',
            },
            {
                id: 'nilai_buku_akhir',
                header: 'Nilai buku saat dimusnahkan',
                cell: (row) => shown(row.nilai_buku_akhir),
                align: 'right',
            },
            {
                id: 'keterangan',
                header: 'Keterangan / alasan',
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
        <ReportPageLayout<DisposalReportRow>
            title="Laporan pemusnahan aset"
            description="Aset yang dimusnahkan dalam rentang tanggal, beserta nilai perolehan dan nilai bukunya saat dimusnahkan."
            reportCode="laporan-pemusnahan-aset"
            filters={filters}
            filterBar={
                <ReportFilterBar
                    canReset={hasActiveFilters}
                    onReset={resetFilters}
                    presets={presets}
                    dates={{ from: 'dari', to: 'sampai' }}
                >
                    <DateFilter label="Dari tanggal" {...bindFilter('dari')} />
                    <DateFilter
                        label="Sampai tanggal"
                        {...bindFilter('sampai')}
                    />
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
