import { AlertCircle, Filter, Printer, RefreshCw, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { Alert, AlertDescription, AlertTitle } from '@apperp/ui/alert';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import { DataTable } from '@apperp/ui/data-table';
import type { DataTableColumn } from '@apperp/ui/data-table';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@apperp/ui/empty';
import { ListPageLayout } from '@apperp/ui/list-page-layout';
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { Spinner } from '@apperp/ui/spinner';
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
    const [filterPanelOpen, setFilterPanelOpen] = useState(false);
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
        <ListPageLayout
            aria-label={description ? `${title}. ${description}` : title}
            header={
                <RecordActionBar
                    title={title}
                    trailing={
                        <Button
                            type="button"
                            variant={filterPanelOpen ? 'secondary' : 'ghost'}
                            size="sm"
                            aria-expanded={filterPanelOpen}
                            aria-controls="report-filter-panel"
                            aria-label={
                                filterPanelOpen
                                    ? 'Tutup filter laporan'
                                    : `Buka filter laporan${activeFilterCount ? `, ${activeFilterCount} filter aktif` : ''}`
                            }
                            title="Filter laporan"
                            onClick={() => setFilterPanelOpen((open) => !open)}
                        >
                            <Filter aria-hidden="true" />
                            <span className="hidden sm:inline">Filter</span>
                            {activeFilterCount > 0 && (
                                <Badge variant="secondary">
                                    {activeFilterCount}
                                </Badge>
                            )}
                        </Button>
                    }
                >
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={onRefresh}
                        disabled={loading}
                        title="Segarkan data terbaru"
                        aria-label="Segarkan data terbaru"
                    >
                        <RefreshCw
                            className={`size-3.5 ${loading ? 'animate-spin' : ''}`}
                        />
                        <span className="hidden sm:inline">Segarkan</span>
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={handlePrint}
                        disabled={loading}
                        title="Pilih format untuk mencetak atau mengunduh laporan"
                    >
                        <Printer className="size-3.5" />
                        <span>Cetak / unduh</span>
                    </Button>
                </RecordActionBar>
            }
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
            <div className="flex min-h-0 min-w-0 flex-1 flex-col lg:flex-row">
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
                                    Tidak ada catatan yang sesuai dengan filter
                                    yang dipilih. Ubah kriteria filter untuk
                                    melihat hasil.
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

                {filterPanelOpen && (
                    <aside
                        id="report-filter-panel"
                        aria-label="Filter laporan"
                        className="bg-background flex max-h-72 min-h-0 w-full shrink-0 flex-col border-t lg:max-h-none lg:w-80 lg:border-l lg:border-t-0 xl:w-96"
                    >
                        <header className="flex shrink-0 items-start justify-between gap-3 border-b px-4 py-3">
                            <div className="min-w-0">
                                <h2 className="text-sm font-semibold">
                                    Filter laporan
                                </h2>
                                <p className="text-muted-foreground text-xs">
                                    Hasil diperbarui otomatis saat filter
                                    diubah.
                                </p>
                            </div>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon-sm"
                                aria-label="Tutup filter laporan"
                                onClick={() => setFilterPanelOpen(false)}
                            >
                                <X aria-hidden="true" />
                            </Button>
                        </header>
                        <div className="min-h-0 flex-1 overflow-y-auto">
                            <ReportFilterEngine state={filterState} />
                        </div>
                    </aside>
                )}
            </div>
        </ListPageLayout>
    );
}
