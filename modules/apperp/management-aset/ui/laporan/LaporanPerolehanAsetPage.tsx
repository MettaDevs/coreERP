import { useMemo } from 'react';
import type { DataTableColumn } from '@apperp/ui/data-table';
import { ReportFilterBar } from './_shared/ReportFilterBar';
import {
    AssetFilter,
    AssetGroupFilter,
    AssetTypeFilter,
    ConditionFilter,
    DateFilter,
    FiscalClassificationFilter,
    LocationFilter,
} from './_shared/ReportFilters';
import { ReportPageLayout } from './_shared/ReportPageLayout';
import { useReportData } from './_shared/useReportData';

/** Satu baris daftar perolehan, satu aset yang diperoleh dalam rentang. */
export type AcquisitionReportRow = {
    nomor: number;
    kode: string;
    nama: string;
    group: string;
    jenis: string;
    lokasi: string;
    tanggal_perolehan: string;
    tanggal_mulai_dipakai: string;
    cara_perolehan: string;
    dokumen_asal: string;
    status: string;
    nilai_perolehan: string;
};

const shown = (value: string | number | null | undefined) =>
    value === null || value === undefined || value === '' ? '—' : String(value);

export default function LaporanPerolehanAsetPage() {
    const {
        filters,
        bindFilter,
        bindMultiFilter,
        presets,
        additional,
        hasActiveFilters,
        resetFilters,
        rows,
        fields,
        loading,
        error,
        refetch,
    } = useReportData<AcquisitionReportRow>('laporan-perolehan-aset');

    const columns: DataTableColumn<AcquisitionReportRow>[] = useMemo(
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
            { id: 'jenis', header: 'Jenis', cell: (row) => shown(row.jenis) },
            {
                id: 'tanggal_perolehan',
                header: 'Tanggal perolehan',
                cell: (row) => shown(row.tanggal_perolehan),
            },
            {
                id: 'cara_perolehan',
                header: 'Cara perolehan',
                cell: (row) => shown(row.cara_perolehan),
            },
            {
                id: 'dokumen_asal',
                header: 'Dokumen asal',
                cell: (row) => (
                    <span className="font-mono text-xs">
                        {shown(row.dokumen_asal)}
                    </span>
                ),
            },
            {
                id: 'nilai_perolehan',
                header: 'Nilai perolehan',
                cell: (row) => shown(row.nilai_perolehan),
                align: 'right',
            },
        ],
        [],
    );

    return (
        <ReportPageLayout<AcquisitionReportRow>
            title="Daftar perolehan aset"
            description="Aset yang diperoleh dalam rentang tanggal, dengan nilai saat diperoleh, cara perolehan, dan dokumen asalnya. Aset hasil pemecahan tidak dihitung sebagai perolehan baru."
            reportCode="laporan-perolehan-aset"
            filters={filters}
            filterBar={
                <ReportFilterBar
                    canReset={hasActiveFilters}
                    onReset={resetFilters}
                    presets={presets}
                    additional={additional}
                    dates={{ from: 'dari', to: 'sampai' }}
                >
                    <DateFilter label="Dari tanggal" {...bindFilter('dari')} />
                    <DateFilter
                        label="Sampai tanggal"
                        {...bindFilter('sampai')}
                    />
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
            totalSummary={
                rows.length > 0 && (
                    <div className="flex flex-wrap items-center justify-between gap-2 text-sm font-semibold">
                        <span>
                            Total nilai perolehan ({shown(fields.jumlah_aset)}{' '}
                            aset)
                        </span>
                        <span className="font-mono">
                            {shown(fields.total_nilai_perolehan)}
                        </span>
                    </div>
                )
            }
            onRefresh={refetch}
        />
    );
}
