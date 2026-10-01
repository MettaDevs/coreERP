import { useMemo } from 'react';
import type { DataTableColumn } from '@apperp/ui/data-table';
import { ReportFilterBar } from './_shared/ReportFilterBar';
import {
    AssetFilter,
    AssetGroupFilter,
    AssetTypeFilter,
    ConditionFilter,
    DateFilter,
    LocationFilter,
} from './_shared/ReportFilters';
import { ReportPageLayout } from './_shared/ReportPageLayout';
import { useReportData } from './_shared/useReportData';

/** Satu baris rekonsiliasi: satu group aset dan satu akun posting group-nya. */
export type ReconciliationReportRow = {
    nomor: number;
    kode_group: string;
    group: string;
    akun: string;
    kode_akun: string;
    nama_akun: string;
    sisi: string;
    saldo_register: string;
    sudah_dibukukan: string;
    dicatat_manual: string;
    menunggu: string;
    tertahan: string;
    ditolak: string;
    belum_diterbitkan: string;
    selisih: string;
};

const shown = (value: string | number | null | undefined) =>
    value === null || value === undefined || value === '' ? '—' : String(value);

const moneyColumn = (
    id: keyof ReconciliationReportRow,
    header: string,
): DataTableColumn<ReconciliationReportRow> => ({
    id,
    header,
    cell: (row) => shown(row[id]),
    align: 'right',
});

export default function LaporanRekonsiliasiAsetPage() {
    const {
        filters,
        bindFilter,
        bindMultiFilter,
        presets,
        additional,
        hasActiveFilters,
        resetFilters,
        rows,
        loading,
        error,
        refetch,
    } = useReportData<ReconciliationReportRow>(
        'laporan-rekonsiliasi-aset-buku-besar',
    );

    const columns: DataTableColumn<ReconciliationReportRow>[] = useMemo(
        () => [
            { id: 'group', header: 'Group', cell: (row) => shown(row.group) },
            { id: 'akun', header: 'Akun', cell: (row) => shown(row.akun) },
            {
                id: 'kode_akun',
                header: 'Nomor akun',
                cell: (row) => (
                    <span className="font-mono text-xs">
                        {shown(row.kode_akun)}
                    </span>
                ),
            },
            moneyColumn('saldo_register', 'Saldo register'),
            moneyColumn('sudah_dibukukan', 'Sudah dibukukan'),
            moneyColumn('dicatat_manual', 'Dicatat manual'),
            moneyColumn('menunggu', 'Menunggu'),
            moneyColumn('tertahan', 'Tertahan'),
            moneyColumn('ditolak', 'Ditolak'),
            moneyColumn('belum_diterbitkan', 'Belum dikirim'),
            {
                id: 'selisih',
                header: 'Belum ada di buku besar',
                cell: (row) => (
                    <span className="text-primary font-semibold">
                        {shown(row.selisih)}
                    </span>
                ),
                align: 'right',
            },
        ],
        [],
    );

    return (
        <ReportPageLayout<ReconciliationReportRow>
            title="Rekonsiliasi aset ke buku besar"
            description="Saldo aset per group dan akun menurut catatan aset, dibandingkan dengan jurnal yang sudah dikirim ke aplikasi finance beserta keadaannya. Saldo buku besar sendiri dicocokkan di aplikasi finance dengan kolom Sudah dibukukan dan Dicatat manual."
            reportCode="laporan-rekonsiliasi-aset-buku-besar"
            filters={filters}
            filterBar={
                <ReportFilterBar
                    canReset={hasActiveFilters}
                    onReset={resetFilters}
                    presets={presets}
                    additional={additional}
                >
                    <DateFilter
                        label="Per tanggal"
                        {...bindFilter('per_tanggal')}
                    />
                    <AssetGroupFilter {...bindMultiFilter('group_aset_id')} />
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
