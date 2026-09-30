import { AlertCircle, Printer, RefreshCw } from 'lucide-react';
import { useMemo } from 'react';
import type { ReactNode } from 'react';
import { Alert, AlertDescription, AlertTitle } from '@apperp/ui/alert';
import { Button } from '@apperp/ui/button';
import {
    Card,
    CardAction,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@apperp/ui/card';
import { DataTable } from '@apperp/ui/data-table';
import type { DataTableColumn } from '@apperp/ui/data-table';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@apperp/ui/empty';
import { Spinner } from '@apperp/ui/spinner';
import { requestPrint } from '../../print';
import { cleanFilters } from './reportOptions';
import type { Filters } from './reportOptions';

export type ReportPageLayoutProps<T> = {
    title: string;
    description?: string;
    reportCode: string;
    filters: Filters;
    filterBar: ReactNode;
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
    reportCode,
    filters,
    filterBar,
    columns,
    rows,
    loading = false,
    error,
    totalSummary,
    onRefresh,
}: ReportPageLayoutProps<T>) {
    // Baris laporan tidak punya kunci alami: satu aset muncul sekali per buku, satu dokumen
    // sekali per aset. Daftarnya diganti utuh setiap kali dimuat dan tidak diurutkan ulang
    // di layar, jadi posisi baris cukup sebagai kunci.
    const keys = useMemo(
        () => new Map(rows.map((row, index) => [row, index])),
        [rows],
    );

    const handlePrint = () => {
        // Hanya filter yang terisi; pilihan banyak dikirim sebagai daftar.
        requestPrint({
            report: reportCode,
            title: `Cetak ${title.toLowerCase()}`,
            parameters: cleanFilters(filters),
        });
    };

    return (
        <Card className="min-h-full rounded-none border-0 shadow-none">
            <CardHeader className="min-h-0 border-b px-5 py-3">
                <CardTitle>{title}</CardTitle>
                {description && (
                    <CardDescription>{description}</CardDescription>
                )}
                <CardAction className="flex items-center gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={onRefresh}
                        disabled={loading}
                        title="Segarkan data terbaru"
                        aria-label="Segarkan data terbaru"
                        className="gap-1.5"
                    >
                        <RefreshCw
                            className={`size-3.5 ${loading ? 'animate-spin' : ''}`}
                        />
                        <span className="hidden sm:inline">Segarkan</span>
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={handlePrint}
                        disabled={loading}
                        className="gap-1.5"
                    >
                        <Printer className="size-3.5" />
                        <span>Cetak</span>
                    </Button>
                </CardAction>
            </CardHeader>

            <CardContent className="px-0">
                {filterBar}

                <div className="space-y-4 px-5 py-4">
                    {error && (
                        <Alert variant="destructive">
                            <AlertCircle />
                            <AlertTitle>Laporan tidak dapat dimuat</AlertTitle>
                            <AlertDescription>{error}</AlertDescription>
                        </Alert>
                    )}

                    {loading ? (
                        <div className="text-muted-foreground flex h-64 flex-col items-center justify-center gap-3">
                            <Spinner className="text-primary size-6 animate-spin" />
                            <p className="text-sm">Memuat data laporan...</p>
                        </div>
                    ) : error ? null : rows.length === 0 ? (
                        // Tanpa syarat `error` di atas, kegagalan memuat tampil bersama
                        // "tidak ada yang sesuai dengan filter" — seolah filternya yang salah.
                        <Empty className="py-12">
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
                        <div className="overflow-x-auto">
                            <DataTable
                                columns={columns}
                                data={rows}
                                getRowKey={(row) => keys.get(row) ?? -1}
                                showRowNumbers={true}
                                emptyMessage="Tidak ada data ditemukan."
                            />
                        </div>
                    )}
                </div>
            </CardContent>

            <CardFooter className="text-muted-foreground flex flex-col items-start justify-between gap-2 border-t px-5 py-3 text-xs sm:flex-row sm:items-center">
                <div>
                    Menampilkan{' '}
                    <span className="text-foreground font-medium">
                        {rows.length}
                    </span>{' '}
                    baris data.
                </div>
                {totalSummary && (
                    <div className="text-foreground font-medium">
                        {totalSummary}
                    </div>
                )}
            </CardFooter>
        </Card>
    );
}
