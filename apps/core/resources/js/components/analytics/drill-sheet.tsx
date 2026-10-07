import { Button } from '@apperp/ui/button';
import { DataTable } from '@apperp/ui/data-table';
import type { DataTableColumn } from '@apperp/ui/data-table';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@apperp/ui/empty';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@apperp/ui/sheet';
import { Download, Rows3 } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { ResultTable } from '@/components/analytics/result-table';
import {
    enqueueAnalyticsExport,
    fetchDrillDown,
    fetchDrillPage,
} from '@/lib/analytics/api';
import type { SlicerValues } from '@/lib/analytics/slicer';
import type { WidgetDatasetField } from '@/lib/analytics/slicer';
import type {
    AnalyticsQuery,
    DashboardWidget,
    DrillDownResult,
    DrillColumn,
    DrillPage,
    DrillValue,
    ResultValue,
} from '@/lib/analytics/types';
import { toastSaveError } from '@/lib/core-api';

type DrillRow = Record<string, ResultValue> & {
    id: string;
    record_url: string | null;
};

const SCREEN_LIMIT = 1000;

/** Daftar sumber satu nilai, di halaman 100 baris dan maksimal 1.000 baris sebelum ekspor. */
export function DrillSheet({
    widget,
    values,
    query,
    fields,
    hierarchies,
    slicers,
    crossFilters,
    onClose,
}: {
    widget: DashboardWidget;
    values: DrillValue[];
    query: AnalyticsQuery;
    fields: WidgetDatasetField[];
    hierarchies: Record<string, string[]>;
    slicers: SlicerValues;
    crossFilters: Record<string, string | string[]>;
    onClose: () => void;
}) {
    const [path, setPath] = useState(values);
    const [pageState, setPageState] = useState<{
        key: string;
        pages: DrillPage[];
        error: string | null;
    } | null>(null);
    const [down, setDown] = useState<DrillDownResult | null>(null);
    const [history, setHistory] = useState<DrillDownResult[]>([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [exporting, setExporting] = useState(false);
    const [attempt, setAttempt] = useState(0);
    const requestKey = JSON.stringify([
        path,
        slicers,
        crossFilters,
        attempt,
        down === null,
    ]);
    const activePageState = pageState?.key === requestKey ? pageState : null;
    const pages = activePageState?.pages ?? [];
    const initialLoading = down === null && activePageState === null;
    const isLoading = loading || initialLoading;
    const visibleError =
        down === null && activePageState === null
            ? null
            : (activePageState?.error ?? error);
    const rows = pages.flatMap((page) => page.rows) as DrillRow[];
    const lastPage = pages.at(-1);
    const columns = useMemo(() => lastPage?.columns ?? [], [lastPage]);

    useEffect(() => {
        if (down !== null) {
            return;
        }

        const controller = new AbortController();
        const [valueSnapshot, slicerSnapshot, crossFilterSnapshot] = JSON.parse(
            requestKey,
        ) as [
            DrillValue[],
            SlicerValues,
            Record<string, string | string[]>,
            number,
            boolean,
        ];
        fetchDrillPage(
            widget.id,
            valueSnapshot,
            null,
            slicerSnapshot,
            crossFilterSnapshot,
            controller.signal,
        )
            .then((page) => {
                setPageState({ key: requestKey, pages: [page], error: null });
                setError(null);
            })
            .catch((caught: unknown) => {
                if (!controller.signal.aborted) {
                    setPageState({
                        key: requestKey,
                        pages: [],
                        error:
                            caught instanceof Error
                                ? caught.message
                                : 'Baris belum dapat dimuat.',
                    });
                    setError(null);
                }
            });

        return () => controller.abort();
    }, [widget.id, attempt, requestKey, down]);

    const tableColumns = useMemo(() => makeColumns(columns), [columns]);
    const loadMore = async () => {
        if (
            lastPage?.next_cursor === null ||
            lastPage?.next_cursor === undefined ||
            loading ||
            rows.length >= SCREEN_LIMIT
        ) {
            return;
        }

        setLoading(true);
        setError(null);

        try {
            const page = await fetchDrillPage(
                widget.id,
                path,
                lastPage.next_cursor,
                slicers,
                crossFilters,
            );
            setPageState((current) =>
                current?.key === requestKey
                    ? { ...current, pages: [...current.pages, page] }
                    : current,
            );
        } catch (caught) {
            setError(
                caught instanceof Error
                    ? caught.message
                    : 'Baris berikutnya belum dapat dimuat.',
            );
        } finally {
            setLoading(false);
        }
    };

    const exportRows = async () => {
        setExporting(true);

        try {
            await enqueueAnalyticsExport({
                widget_id: widget.id,
                kind: 'drill',
                values: path,
                slicers,
                cross_filters: crossFilters,
            });
            toast.success(
                'Ekspor daftar masuk ke antrean. Buka menu Ekspor untuk melihat hasilnya.',
            );
        } catch (caught) {
            toastSaveError(caught, 'Ekspor belum diminta.');
        } finally {
            setExporting(false);
        }
    };

    const initialDrills = useMemo(
        () => drillOptions(query, fields, hierarchies),
        [query, fields, hierarchies],
    );

    const startDrill = async (field: string) => {
        setLoading(true);
        setError(null);

        try {
            const result = await fetchDrillDown(
                widget.id,
                field,
                path,
                slicers,
                crossFilters,
            );
            setHistory([]);
            setDown(result);
        } catch (caught) {
            setError(
                caught instanceof Error
                    ? caught.message
                    : 'Perincian belum dapat dimuat.',
            );
        } finally {
            setLoading(false);
        }
    };

    const drillRow = async (row: Record<string, ResultValue>) => {
        if (down === null) {
            return;
        }

        const value = row[down.next.field];

        if (value === null || value === undefined) {
            return;
        }

        const nextPath = [
            ...down.path,
            {
                field: down.next.field,
                value,
                ...(down.next.granularity === null
                    ? {}
                    : { granularity: down.next.granularity }),
            },
        ];

        if (
            !canDescend(
                down.next.field,
                down.next.granularity,
                fields,
                hierarchies,
            )
        ) {
            setPath(nextPath);
            setDown(null);
            setHistory([]);
            setAttempt((current) => current + 1);

            return;
        }

        setLoading(true);
        setError(null);

        try {
            const result = await fetchDrillDown(
                widget.id,
                down.next.field,
                nextPath,
                slicers,
                crossFilters,
            );
            setHistory((current) => [...current, down]);
            setDown(result);
        } catch (caught) {
            setError(
                caught instanceof Error
                    ? caught.message
                    : 'Perincian belum dapat dimuat.',
            );
        } finally {
            setLoading(false);
        }
    };

    const goBack = () => {
        const previous = history.at(-1);

        if (previous === undefined) {
            setDown(null);

            return;
        }

        setDown(previous);
        setHistory((current) => current.slice(0, -1));
    };

    return (
        <Sheet open onOpenChange={(open) => !open && onClose()}>
            <SheetContent
                side="right"
                className="w-full gap-0 p-0 sm:max-w-5xl"
            >
                <SheetHeader className="border-b px-6 py-5 pr-12">
                    <SheetTitle>
                        {down === null
                            ? `Baris di balik ${widget.title}`
                            : `Perincian ${down.next.caption}`}
                    </SheetTitle>
                    <SheetDescription>
                        Angka mengikuti hak akses Anda. Tampilkan sampai 1.000
                        baris di sini; daftar lengkap dapat dikirim ke Ekspor.
                    </SheetDescription>
                </SheetHeader>
                <div className="min-h-0 flex-1 overflow-auto px-6 py-5">
                    {visibleError && (
                        <p
                            className="mb-3 text-sm text-destructive"
                            role="alert"
                        >
                            {visibleError}
                        </p>
                    )}
                    {down !== null ? (
                        <>
                            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                                <p className="text-sm text-muted-foreground">
                                    Pilih satu kelompok untuk melihat tingkat
                                    berikutnya atau baris sumber.
                                </p>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={goBack}
                                >
                                    Kembali
                                </Button>
                            </div>
                            {down.result.rows.length === 0 && !isLoading ? (
                                <Empty className="py-10">
                                    <EmptyHeader>
                                        <EmptyTitle>
                                            Kelompok ini tidak memiliki rincian
                                        </EmptyTitle>
                                    </EmptyHeader>
                                </Empty>
                            ) : (
                                <ResultTable
                                    result={down.result}
                                    showTotals={false}
                                    fields={fields}
                                    drillableField={down.next.field}
                                    onDrillRow={(row) => void drillRow(row)}
                                />
                            )}
                        </>
                    ) : rows.length === 0 && !isLoading ? (
                        <Empty className="py-10">
                            <EmptyHeader>
                                <EmptyTitle>
                                    Baris yang cocok tidak ditemukan
                                </EmptyTitle>
                                <EmptyDescription>
                                    Data mungkin berubah sejak angka ini
                                    dihitung.
                                </EmptyDescription>
                            </EmptyHeader>
                        </Empty>
                    ) : (
                        <>
                            {initialDrills.length > 0 && (
                                <div className="mb-3 flex flex-wrap items-center gap-2">
                                    <span className="text-sm text-muted-foreground">
                                        Perinci kelompok:
                                    </span>
                                    {initialDrills.map((option) => (
                                        <Button
                                            key={option.field}
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            disabled={isLoading}
                                            onClick={() =>
                                                void startDrill(option.field)
                                            }
                                        >
                                            {option.caption}
                                        </Button>
                                    ))}
                                </div>
                            )}
                            <DataTable
                                columns={tableColumns}
                                data={rows}
                                getRowKey={(row) => row.id}
                                showRowNumbers={false}
                                emptyMessage="Belum ada baris."
                            />
                        </>
                    )}
                    {isLoading && (
                        <p
                            className="mt-3 text-sm text-muted-foreground"
                            role="status"
                        >
                            Memuat baris…
                        </p>
                    )}
                    {down === null &&
                        rows.length < SCREEN_LIMIT &&
                        lastPage?.next_cursor && (
                            <div className="flex justify-center py-4">
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={isLoading}
                                    onClick={() => void loadMore()}
                                >
                                    <Rows3 />
                                    Muat 100 baris lagi
                                </Button>
                            </div>
                        )}
                    {down === null &&
                        rows.length >= SCREEN_LIMIT &&
                        lastPage?.next_cursor && (
                            <p className="py-3 text-center text-sm text-muted-foreground">
                                Tampilan dibatasi sampai 1.000 baris. Minta
                                ekspor untuk mengambil daftar lengkap.
                            </p>
                        )}
                </div>
                <SheetFooter className="border-t px-6 py-4 sm:flex-row sm:justify-between">
                    <span className="self-center text-sm text-muted-foreground">
                        {down === null
                            ? `${rows.length.toLocaleString('id-ID')} baris ditampilkan`
                            : `${down.result.rows.length.toLocaleString('id-ID')} kelompok ditampilkan`}
                    </span>
                    <div className="flex gap-2">
                        {down !== null && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={goBack}
                            >
                                Kembali
                            </Button>
                        )}
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setAttempt((value) => value + 1)}
                            disabled={isLoading}
                        >
                            Muat ulang
                        </Button>
                        {down === null && lastPage !== undefined && (
                            <Button
                                type="button"
                                onClick={() => void exportRows()}
                                disabled={exporting}
                            >
                                <Download />
                                {exporting
                                    ? 'Meminta ekspor…'
                                    : 'Ekspor ke Excel'}
                            </Button>
                        )}
                    </div>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}

function drillOptions(
    query: AnalyticsQuery,
    fields: WidgetDatasetField[],
    hierarchies: Record<string, string[]>,
): Array<{ field: string; caption: string }> {
    return (query.dimensions ?? []).flatMap((dimension) => {
        const fieldKey =
            typeof dimension === 'string' ? dimension : dimension.field;
        const granularity =
            typeof dimension === 'string'
                ? null
                : (dimension.granularity ?? null);
        const field = fields.find((item) => item.key === fieldKey);

        return field && canDescend(fieldKey, granularity, fields, hierarchies)
            ? [{ field: fieldKey, caption: field.caption }]
            : [];
    });
}

function canDescend(
    field: string,
    granularity: string | null,
    fields: WidgetDatasetField[],
    hierarchies: Record<string, string[]>,
): boolean {
    const source = fields.find((item) => item.key === field);

    if (source?.time) {
        return (
            granularity === null ||
            ['year', 'quarter', 'month'].includes(granularity)
        );
    }

    return Object.values(hierarchies).some((levels) => {
        const index = levels.indexOf(field);

        return index >= 0 && index < levels.length - 1;
    });
}

function makeColumns(columns: DrillColumn[]): DataTableColumn<DrillRow>[] {
    const output: DataTableColumn<DrillRow>[] = columns.map((column) => ({
        id: column.key,
        header: column.caption,
        width: 180,
        minWidth: 100,
        cell: (row) => {
            const value = column.label_key
                ? (row[column.label_key] ?? row[column.key])
                : row[column.key];
            const text =
                value === null || value === undefined ? '—' : String(value);

            return row.record_url ? (
                <a
                    className="text-primary underline-offset-4 hover:underline"
                    href={row.record_url}
                    title={`Buka ${column.caption}: ${text}`}
                >
                    {text}
                </a>
            ) : (
                <span title={text}>{text}</span>
            );
        },
    }));

    return output;
}
