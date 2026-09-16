import { Printer, RefreshCw } from 'lucide-react';
import type { Key, ReactNode } from 'react';
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

export type ReportPageLayoutProps<T> = {
    title: string;
    description?: string;
    reportCode: string;
    filters: Record<string, string>;
    filterBar: ReactNode;
    columns: DataTableColumn<T>[];
    rows: T[];
    loading?: boolean;
    error?: string;
    totalSummary?: ReactNode;
    getRowKey?: (row: T) => Key;
    onRefresh?: () => void;
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
    getRowKey = (row) =>
        (row.id as Key) ??
        (row.kode as Key) ??
        (row.no_bukti as Key) ??
        (row.nomor as Key) ??
        JSON.stringify(row),
    onRefresh,
}: ReportPageLayoutProps<T>) {
    const handlePrint = () => {
        // Bersihkan parameter filter kosong sebelum dikirim ke Core
        const cleanParams: Record<string, unknown> = {};

        for (const [key, value] of Object.entries(filters)) {
            if (value !== '' && value !== undefined && value !== null) {
                cleanParams[key] = value;
            }
        }

        requestPrint({
            report: reportCode,
            title: `Cetak ${title}`,
            parameters: cleanParams,
        });
    };

    return (
        <Card className="min-h-full rounded-none border-0 shadow-none">
            <CardHeader className="min-h-0 border-b px-5 py-3">
                <CardTitle className="text-base">{title}</CardTitle>
                {description && (
                    <CardDescription>{description}</CardDescription>
                )}
                <CardAction className="flex items-center gap-2">
                    {onRefresh && (
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
                    )}
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
                        <div className="border-destructive/30 bg-destructive/10 text-destructive rounded-md border p-3 text-sm">
                            {error}
                        </div>
                    )}

                    {loading ? (
                        <div className="text-muted-foreground flex h-64 flex-col items-center justify-center gap-3">
                            <Spinner className="text-primary size-6 animate-spin" />
                            <p className="text-sm">Memuat data laporan...</p>
                        </div>
                    ) : rows.length === 0 ? (
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
                                getRowKey={getRowKey}
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
