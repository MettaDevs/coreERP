import { useMemo } from 'react';
import { Badge } from '@apperp/ui/badge';
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
    OrganizationUnitFilter,
    PersonFilter,
} from './_shared/ReportFilters';
import { ReportPageLayout } from './_shared/ReportPageLayout';
import { useReportData } from './_shared/useReportData';

/** Satu baris dataset `laporan-monitoring-aset`; uang dan tanggal sudah diformat server. */
export type MonitoringReportRow = {
    nomor?: number;
    tgl_monitoring?: string;
    no_bukti?: string;
    lokasi?: string;
    asset_kode?: string;
    asset_nama?: string;
    spesifikasi?: string;
    kondisi_sistem?: string;
    kondisi_fisik?: string;
    kondisi_aset?: string;
    status_monitoring?: string;
    keterangan?: string;
    nilai_perolehan?: string | number | null;
    akumulasi_penyusutan?: string | number | null;
    nilai_buku_akhir?: string | number | null;
    penanggung_jawab?: string;
    unit_organisasi?: string;
} & Record<string, unknown>;

const teks = (value: unknown) =>
    value === null || value === undefined || value === '' ? '—' : String(value);

export default function LaporanMonitoringAsetPage() {
    const {
        filters,
        bindFilter,
        bindMultiFilter,
        hasActiveFilters,
        resetFilters,
        rows,
        loading,
        error,
        refetch,
    } = useReportData<MonitoringReportRow>('laporan-monitoring-aset');

    const columns: DataTableColumn<MonitoringReportRow>[] = useMemo(
        () => [
            {
                id: 'tgl_monitoring',
                header: 'Tgl monitoring',
                cell: (row) => teks(row.tgl_monitoring),
            },
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
                id: 'lokasi',
                header: 'Lokasi',
                cell: (row) => teks(row.lokasi),
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
                header: 'Nama aset',
                cell: (row) => teks(row.asset_nama),
            },
            {
                id: 'spesifikasi',
                header: 'Spesifikasi',
                cell: (row) => teks(row.spesifikasi),
            },
            {
                id: 'kondisi_sistem',
                header: 'Status di sistem',
                cell: (row) => teks(row.kondisi_sistem),
            },
            {
                id: 'kondisi_fisik',
                header: 'Keberadaan fisik',
                cell: (row) => teks(row.kondisi_fisik),
            },
            {
                id: 'kondisi_aset',
                header: 'Kondisi fisik',
                cell: (row) => teks(row.kondisi_aset),
            },
            {
                id: 'status_monitoring',
                header: 'Status monitoring',
                cell: (row) => (
                    <Badge
                        variant={
                            row.status_monitoring === 'Tidak sesuai'
                                ? 'destructive'
                                : 'secondary'
                        }
                    >
                        {teks(row.status_monitoring)}
                    </Badge>
                ),
                align: 'center',
            },
            {
                id: 'keterangan',
                header: 'Keterangan',
                cell: (row) => teks(row.keterangan),
            },
            {
                id: 'nilai_perolehan',
                header: 'Nilai perolehan',
                cell: (row) => teks(row.nilai_perolehan),
                align: 'right',
            },
            {
                id: 'akumulasi_penyusutan',
                header: 'Akumulasi penyusutan',
                cell: (row) => teks(row.akumulasi_penyusutan),
                align: 'right',
            },
            {
                id: 'nilai_buku_akhir',
                header: 'Nilai buku akhir',
                cell: (row) => teks(row.nilai_buku_akhir),
                align: 'right',
            },
            {
                id: 'penanggung_jawab',
                header: 'Penanggung jawab',
                cell: (row) => teks(row.penanggung_jawab),
            },
            {
                id: 'unit_organisasi',
                header: 'Unit organisasi',
                cell: (row) => teks(row.unit_organisasi),
            },
        ],
        [],
    );

    return (
        <ReportPageLayout<MonitoringReportRow>
            title="Laporan monitoring aset"
            description="Hasil pemeriksaan fisik aset yang sudah diselesaikan: keberadaan, kondisi, kecocokan dengan catatan aset, dan nilai bukunya saat diperiksa."
            reportCode="laporan-monitoring-aset"
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
                    <AssetGroupFilter {...bindMultiFilter('group_aset_id')} />
                    <FiscalClassificationFilter
                        {...bindMultiFilter('kelompok_harta_fiskal_id')}
                    />
                    <AssetTypeFilter {...bindMultiFilter('jenis_aset_id')} />
                    <AssetFilter {...bindFilter('asset_id')} />
                    <ConditionFilter {...bindMultiFilter('kondisi_aset_id')} />
                    <LocationFilter {...bindMultiFilter('lokasi_aset_id')} />
                    <PersonFilter
                        {...bindMultiFilter('penanggung_jawab_user_id')}
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
