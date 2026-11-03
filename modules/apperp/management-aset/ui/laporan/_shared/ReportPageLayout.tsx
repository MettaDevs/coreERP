import { AlertCircle, Printer, RefreshCw } from 'lucide-react';
import { useMemo } from 'react';
import type { ReactNode } from 'react';
import { Alert, AlertDescription, AlertTitle } from '@apperp/ui/alert';
import { DataTable } from '@apperp/ui/data-table';
import type { DataTableColumn } from '@apperp/ui/data-table';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@apperp/ui/empty';
import { Spinner } from '@apperp/ui/spinner';
import RecordListPage from '../../_shared/RecordListPage';
import { requestPrint } from '../../print';
import { ReportFilterEngine } from './ReportFilterEngine';
import { filled, toParameters } from './reportOptions';
import type { ReportFilterState } from './useReportData';

export type ReportPageLayoutProps<T> = {
    title: string;
    description?: string;
    filterState: ReportFilterState;
    columns: DataTableColumn<T>[];
    rows: T[];
    loading?: boolean;
    error?: string;
    totalSummary?: ReactNode;
    onRefresh: () => void;
};

export function ReportPageLayout<T extends Record<string, unknown>>({
    title,
    description,
    filterState,
    columns,
    rows,
    loading = false,
    error,
    totalSummary,
    onRefresh,
}: ReportPageLayoutProps<T>) {
    const { reportCode, filters } = filterState;
    // Baris laporan tidak punya kunci alami: satu aset muncul sekali per buku, satu dokumen
    // sekali per aset. Daftarnya diganti utuh setiap kali dimuat dan tidak diurutkan ulang
    // di layar, jadi posisi baris cukup sebagai kunci.
    const keys = useMemo(
        () => new Map(rows.map((row, index) => [row, index])),
        [rows],
    );
    const activeFilterCount = Object.values(filters).filter(filled).length;

    const handlePrint = () => {
        // Hanya filter yang terisi; pilihan banyak dikirim sebagai daftar.
        requestPrint({
            report: reportCode,
            title: `Cetak atau unduh ${title.toLowerCase()}`,
            parameters: toParameters(filters),
        });
    };

    return (
        <RecordListPage
            aria-label={description ? `${title}. ${description}` : title}
            toolbar={{
                title,
                groups: [
                    {
                        id: 'home',
                        label: 'Home',
                        actions: [
                            {
                                id: 'refresh',
                                label: 'Segarkan',
                                icon: <RefreshCw />,
                                disabled: loading,
                                onSelect: onRefresh,
                            },
                            {
                                id: 'print',
                                label: 'Cetak / unduh',
                                icon: <Printer />,
                                disabled: loading,
                                onSelect: handlePrint,
                            },
                        ],
                    },
                ],
            }}
            filters={<ReportFilterEngine state={filterState} />}
            filtersActive={activeFilterCount > 0}
            footer={
                <div className="text-muted-foreground flex shrink-0 flex-wrap items-center justify-between gap-x-4 gap-y-1 border-t px-4 py-2 text-xs">
                    <span>
                        {loading
                            ? 'Memuat data laporan...'
                            : error
                              ? 'Data laporan belum tersedia.'
                              : `Menampilkan ${rows.length} baris data.`}
                    </span>
                    {!loading && !error && totalSummary && (
                        <span className="text-foreground font-medium">
                            {totalSummary}
                        </span>
                    )}
                </div>
            }
        >
            <section
                aria-label="Hasil laporan"
                className="flex min-h-0 min-w-0 flex-1 flex-col"
            >
                {error ? (
                    <Alert variant="destructive" className="m-4">
                        <AlertCircle />
                        <AlertTitle>Laporan tidak dapat dimuat</AlertTitle>
                        <AlertDescription>
                            {error} Klik Segarkan untuk mencoba lagi.
                        </AlertDescription>
                    </Alert>
                ) : loading ? (
                    <div className="text-muted-foreground flex min-h-0 flex-1 flex-col items-center justify-center gap-3">
                        <Spinner className="text-primary size-6 animate-spin" />
                        <p className="text-sm">Memuat data laporan...</p>
                    </div>
                ) : rows.length === 0 ? (
                    <Empty className="flex-1 border-0 py-12">
                        <EmptyHeader>
                            <EmptyTitle>Tidak ada data laporan</EmptyTitle>
                            <EmptyDescription>
                                Tidak ada catatan yang sesuai dengan filter yang
                                dipilih. Ubah kriteria filter untuk melihat
                                hasil.
                            </EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <DataTable
                        className="min-h-0 flex-1"
                        columns={columns}
                        data={rows}
                        getRowKey={(row) => keys.get(row) ?? -1}
                        showRowNumbers={true}
                        emptyMessage="Tidak ada data ditemukan."
                    />
                )}
            </section>
        </RecordListPage>
    );
}
