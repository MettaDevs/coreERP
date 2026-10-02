import { useMemo } from 'react';
import type { DataTableColumn } from '@apperp/ui/data-table';
import { Input } from '@apperp/ui/input';
import { ReportFilterBar } from './_shared/ReportFilterBar';
import type { FilterProps } from './_shared/ReportFilters';
import {
    AssetFilter,
    AssetGroupFilter,
    AssetTypeFilter,
    ConditionFilter,
    DepreciationBookFilter,
    FiscalClassificationFilter,
    LocationFilter,
} from './_shared/ReportFilters';
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

/** Bulan awal atau akhir proyeksi. */
function MonthFilter({
    label,
    value,
    onChange,
}: FilterProps & { label: string }) {
    return (
        <div className="w-full sm:w-44">
            <Input
                label={label}
                type="month"
                value={value ?? ''}
                onChange={(event) => onChange(event.target.value)}
            />
        </div>
    );
}

export default function LaporanProyeksiPenyusutanAsetPage() {
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
    } = useReportData<ProjectionReportRow>('laporan-proyeksi-penyusutan-aset');

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
            reportCode="laporan-proyeksi-penyusutan-aset"
            filters={filters}
            filterBar={
                <ReportFilterBar
                    canReset={hasActiveFilters}
                    onReset={resetFilters}
                    presets={presets}
                    additional={additional}
                >
                    <MonthFilter label="Dari bulan" {...bindFilter('dari')} />
                    <MonthFilter
                        label="Sampai bulan"
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
