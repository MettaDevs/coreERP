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

export type MutationReportRow = {
    nomor?: number;
    tanggal_mutasi?: string;
    no_bukti?: string;
    asset_kode?: string;
    asset_nama?: string;
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
        bindFilter,
        bindMultiFilter,
        additional,
        hasActiveFilters,
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
                header: 'Tgl mutasi',
                cell: (row) => String(row.tanggal_mutasi ?? '-'),
            },
            {
                id: 'no_bukti',
                header: 'No. bukti mutasi',
                cell: (row) => (
                    <span className="font-mono text-xs font-semibold">
                        {String(row.no_bukti ?? '-')}
                    </span>
                ),
            },
            {
                id: 'asset_kode',
                header: 'Kode aset',
                cell: (row) => (
                    <span className="font-mono text-xs">
                        {String(row.asset_kode ?? '-')}
                    </span>
                ),
            },
            {
                id: 'asset_nama',
                header: 'Nama aset',
                cell: (row) => String(row.asset_nama ?? '-'),
            },
            {
                id: 'spesifikasi',
                header: 'Spesifikasi',
                cell: (row) => String(row.spesifikasi ?? '-'),
            },
            {
                id: 'lokasi_asal',
                header: 'Lokasi asal',
                cell: (row) => String(row.lokasi_asal ?? '-'),
            },
            {
                id: 'lokasi_tujuan',
                header: 'Lokasi tujuan',
                cell: (row) => String(row.lokasi_tujuan ?? '-'),
            },
            {
                id: 'penanggung_jawab',
                header: 'Penanggung jawab',
                cell: (row) => String(row.penanggung_jawab ?? '-'),
            },
            {
                id: 'pic_penerima',
                header: 'PIC penerima',
                cell: (row) => String(row.pic_penerima ?? '-'),
            },
            {
                id: 'kondisi_aset',
                header: 'Kondisi aset',
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
            title="Laporan mutasi aset"
            description="Histori perpindahan lokasi, penanggung jawab, dan serah terima aset antar unit organisasi."
            reportCode="laporan-mutasi-aset"
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
