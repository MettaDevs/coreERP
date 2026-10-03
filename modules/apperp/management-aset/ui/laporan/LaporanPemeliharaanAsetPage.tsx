import { useMemo } from 'react';
import { Badge } from '@apperp/ui/badge';
import type { DataTableColumn } from '@apperp/ui/data-table';
import { Field } from '@apperp/ui/field';
import { MultiSelect } from '@apperp/ui/multi-select';
import { ReportFilterBar } from './_shared/ReportFilterBar';
import type { MultiFilterProps } from './_shared/ReportFilters';
import {
    AssetFilter,
    AssetGroupFilter,
    AssetTypeFilter,
    DateFilter,
    FiscalClassificationFilter,
    LocationFilter,
    MasterFilter,
    OrganizationUnitFilter,
} from './_shared/ReportFilters';
import { ReportPageLayout } from './_shared/ReportPageLayout';
import { useReportData } from './_shared/useReportData';

/** Satu baris dataset `laporan-pemeliharaan-aset`: satu aset yang dikerjakan pada satu work order. */
export type MaintenanceReportRow = {
    nomor?: number;
    no_bukti?: string;
    tanggal?: string;
    asset_kode?: string;
    asset_nama?: string;
    spesifikasi?: string;
    satuan?: string;
    jumlah?: number | string;
    checklist?: string;
    analisa_perbaikan?: string;
    jenis_pemeliharaan?: string;
    jenis_pekerjaan?: string;
    lokasi?: string;
    unit_organisasi?: string;
    pic?: string;
    tingkat_layanan?: string;
    status?: string;
    catatan?: string;
} & Record<string, unknown>;

const teks = (value: unknown) =>
    value === null || value === undefined || value === '' ? '—' : String(value);

/** Status work order; nilainya kode status yang dikenal server, labelnya sama dengan layar work order. */
const STATUS_ITEMS = [
    { value: 'draft', label: 'Draf' },
    { value: 'dijadwalkan', label: 'Dijadwalkan' },
    { value: 'dikerjakan', label: 'Dikerjakan' },
    { value: 'selesai', label: 'Selesai' },
    { value: 'ditutup', label: 'Ditutup' },
    { value: 'dibatalkan', label: 'Dibatalkan' },
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

export default function LaporanPemeliharaanAsetPage() {
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
    } = useReportData<MaintenanceReportRow>('laporan-pemeliharaan-aset');

    const columns: DataTableColumn<MaintenanceReportRow>[] = useMemo(
        () => [
            {
                id: 'no_bukti',
                header: 'No. bukti',
                cell: (row) => (
                    <span className="font-mono text-xs font-semibold">
                        {teks(row.no_bukti)}
                    </span>
                ),
            },
            {
                id: 'tanggal',
                header: 'Tgl work order',
                cell: (row) => teks(row.tanggal),
            },
            {
                id: 'asset_kode',
                header: 'Kode aset',
                cell: (row) => (
                    <span className="font-mono text-xs">
                        {teks(row.asset_kode)}
                    </span>
                ),
            },
            {
                id: 'asset_nama',
                header: 'Item aset',
                cell: (row) => teks(row.asset_nama),
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
                id: 'jumlah',
                header: 'Jumlah',
                cell: (row) => teks(row.jumlah),
                align: 'right',
                width: 70,
            },
            {
                id: 'checklist',
                header: 'Item checklist',
                cell: (row) => (
                    <span className="text-muted-foreground text-xs">
                        {teks(row.checklist)}
                    </span>
                ),
            },
            {
                id: 'analisa_perbaikan',
                header: 'Analisa perbaikan',
                cell: (row) => teks(row.analisa_perbaikan),
            },
            {
                id: 'jenis_pemeliharaan',
                header: 'Jenis pemeliharaan',
                cell: (row) => teks(row.jenis_pemeliharaan),
            },
            {
                id: 'jenis_pekerjaan',
                header: 'Jenis pekerjaan',
                cell: (row) => teks(row.jenis_pekerjaan),
            },
            {
                id: 'lokasi',
                header: 'Lokasi',
                cell: (row) => teks(row.lokasi),
            },
            {
                id: 'unit_organisasi',
                header: 'Unit organisasi',
                cell: (row) => teks(row.unit_organisasi),
            },
            {
                id: 'pic',
                header: 'PIC',
                cell: (row) => teks(row.pic),
            },
            {
                id: 'tingkat_layanan',
                header: 'Tingkat layanan',
                cell: (row) => teks(row.tingkat_layanan),
            },
            {
                id: 'status',
                header: 'Status',
                cell: (row) => (
                    <Badge variant="secondary">{teks(row.status)}</Badge>
                ),
                align: 'center',
            },
            {
                id: 'catatan',
                header: 'Catatan',
                cell: (row) => teks(row.catatan),
            },
        ],
        [],
    );

    return (
        <ReportPageLayout<MaintenanceReportRow>
            title="Laporan pemeliharaan aset"
            description="Riwayat perawatan dan perbaikan aset, satu baris per aset yang dikerjakan, beserta checklist, analisa perbaikan, dan teknisinya."
            reportCode="laporan-pemeliharaan-aset"
            filters={filters}
            filterBar={
                <ReportFilterBar
                    canReset={hasActiveFilters}
                    onReset={resetFilters}
                    additional={additional}
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
                    <AssetFilter {...bindFilter('asset_id')} />
                    <LocationFilter {...bindMultiFilter('lokasi_aset_id')} />
                    <StatusFilter {...bindMultiFilter('status')} />
                    <MasterFilter
                        {...bindMultiFilter('tingkat_layanan_id')}
                        resource="tingkat-layanan"
                        label="Tingkat layanan"
                        unavailable="Pilihan tingkat layanan tidak dapat dimuat."
                    />
                    <MasterFilter
                        {...bindMultiFilter('teknisi_user_id')}
                        resource="reference-data/anggota"
                        label="Teknisi"
                        unavailable="Pilihan teknisi tidak dapat dimuat."
                    />
                    <OrganizationUnitFilter
                        {...bindMultiFilter('org_unit_id')}
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
