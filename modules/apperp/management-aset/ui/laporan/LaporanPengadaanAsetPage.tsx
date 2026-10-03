import { useMemo } from 'react';
import { Badge } from '@apperp/ui/badge';
import type { DataTableColumn } from '@apperp/ui/data-table';
import { Field } from '@apperp/ui/field';
import { MultiSelect } from '@apperp/ui/multi-select';
import { ReportFilterBar } from './_shared/ReportFilterBar';
import type { MultiFilterProps } from './_shared/ReportFilters';
import {
    AssetTypeFilter,
    DateFilter,
    OrganizationUnitFilter,
} from './_shared/ReportFilters';
import { ReportPageLayout } from './_shared/ReportPageLayout';
import { useReportData } from './_shared/useReportData';

/**
 * Satu baris dataset `laporan-pengadaan-aset`: satu barang yang direncanakan, atau diminta tanpa rencana,
 * beserta permintaan dan penerimaannya.
 */
export type ProcurementReportRow = {
    nomor?: number;
    no_rencana?: string;
    tanggal_rencana?: string | null;
    tahun_anggaran?: string;
    sumber_dana?: string;
    item_aset?: string;
    spesifikasi?: string;
    satuan?: string;
    jumlah_rencana?: string | null;
    nilai_rencana?: string | null;
    no_permintaan?: string;
    tanggal_permintaan?: string | null;
    jumlah_diminta?: string;
    belum_diminta?: string | null;
    no_penerimaan?: string;
    tanggal_penerimaan?: string | null;
    vendor?: string;
    jumlah_diterima?: string;
    nilai_diterima?: string;
    belum_diterima?: string;
    unit_organisasi?: string;
    status?: string;
} & Record<string, unknown>;

const teks = (value: unknown) =>
    value === null || value === undefined || value === '' ? '—' : String(value);

/** Tahap pengadaan; nilainya kode yang dikenal server. */
const STATUS_ITEMS = [
    { value: 'belum_diminta', label: 'Belum diminta' },
    { value: 'belum_diterima', label: 'Belum diterima' },
    { value: 'diterima_sebagian', label: 'Diterima sebagian' },
    { value: 'diterima_penuh', label: 'Diterima penuh' },
];

function StatusFilter({ value, onChange }: MultiFilterProps) {
    return (
        <Field className="w-full sm:w-56">
            <MultiSelect
                label="Status"
                items={STATUS_ITEMS}
                value={value}
                onValueChange={onChange}
                searchPlaceholder="Cari status"
                emptyMessage="Status tidak ditemukan."
            />
        </Field>
    );
}

const mono = (value: unknown) => (
    <span className="font-mono text-xs">{teks(value)}</span>
);

export default function LaporanPengadaanAsetPage() {
    const {
        bindFilter,
        bindMultiFilter,
        filters,
        presets,
        additional,
        hasActiveFilters,
        resetFilters,
        rows,
        fields,
        loading,
        error,
        refetch,
    } = useReportData<ProcurementReportRow>('laporan-pengadaan-aset');

    const columns: DataTableColumn<ProcurementReportRow>[] = useMemo(
        () => [
            {
                id: 'no_rencana',
                header: 'No. rencana',
                cell: (row) => mono(row.no_rencana),
            },
            {
                id: 'tanggal_rencana',
                header: 'Tgl rencana',
                cell: (row) => teks(row.tanggal_rencana),
            },
            {
                id: 'item_aset',
                header: 'Item aset',
                cell: (row) => teks(row.item_aset),
            },
            {
                id: 'spesifikasi',
                header: 'Spesifikasi',
                cell: (row) => teks(row.spesifikasi),
            },
            {
                id: 'satuan',
                header: 'Satuan',
                cell: (row) => teks(row.satuan),
                align: 'center',
                width: 70,
            },
            {
                id: 'jumlah_rencana',
                header: 'Rencana',
                cell: (row) => teks(row.jumlah_rencana),
                align: 'right',
                width: 80,
            },
            {
                id: 'nilai_rencana',
                header: 'Nilai rencana',
                cell: (row) => teks(row.nilai_rencana),
                align: 'right',
            },
            {
                id: 'no_permintaan',
                header: 'No. permintaan',
                cell: (row) => mono(row.no_permintaan),
            },
            {
                id: 'jumlah_diminta',
                header: 'Diminta',
                cell: (row) => teks(row.jumlah_diminta),
                align: 'right',
                width: 80,
            },
            {
                id: 'no_penerimaan',
                header: 'No. penerimaan',
                cell: (row) => mono(row.no_penerimaan),
            },
            {
                id: 'vendor',
                header: 'Vendor',
                cell: (row) => teks(row.vendor),
            },
            {
                id: 'jumlah_diterima',
                header: 'Diterima',
                cell: (row) => teks(row.jumlah_diterima),
                align: 'right',
                width: 80,
            },
            {
                id: 'nilai_diterima',
                header: 'Nilai diterima',
                cell: (row) => teks(row.nilai_diterima),
                align: 'right',
            },
            {
                id: 'belum_diterima',
                header: 'Belum diterima',
                cell: (row) => teks(row.belum_diterima),
                align: 'right',
                width: 90,
            },
            {
                id: 'unit_organisasi',
                header: 'Unit organisasi',
                cell: (row) => teks(row.unit_organisasi),
            },
            {
                id: 'status',
                header: 'Status',
                cell: (row) => (
                    <Badge variant="secondary">{teks(row.status)}</Badge>
                ),
                align: 'center',
            },
        ],
        [],
    );

    return (
        <ReportPageLayout<ProcurementReportRow>
            title="Laporan pengadaan aset"
            description="Mengikuti pengadaan dari rencana, permintaan pembelian, sampai penerimaan: berapa yang direncanakan, diminta, dan sudah diterima per barang, beserta sisanya."
            reportCode="laporan-pengadaan-aset"
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
                    <AssetTypeFilter {...bindMultiFilter('jenis_aset_id')} />
                    <OrganizationUnitFilter
                        {...bindMultiFilter('org_unit_id')}
                    />
                    <StatusFilter {...bindMultiFilter('status')} />
                </ReportFilterBar>
            }
            columns={columns}
            rows={rows}
            loading={loading}
            error={error}
            totalSummary={
                rows.length > 0 && (
                    <div className="flex flex-wrap items-center justify-between gap-x-6 gap-y-1 text-sm font-semibold">
                        <span>{teks(fields.jumlah_baris)} barang</span>
                        <span>
                            Nilai rencana{' '}
                            <span className="font-mono">
                                {teks(fields.total_nilai_rencana)}
                            </span>
                        </span>
                        <span>
                            Nilai diterima{' '}
                            <span className="font-mono">
                                {teks(fields.total_nilai_diterima)}
                            </span>
                        </span>
                    </div>
                )
            }
            onRefresh={refetch}
        />
    );
}
