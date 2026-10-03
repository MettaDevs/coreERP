import { Button } from '@apperp/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@apperp/ui/card';
import type { ChartConfig } from '@apperp/ui/chart';
import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
} from '@apperp/ui/chart';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@apperp/ui/empty';
import { Skeleton } from '@apperp/ui/skeleton';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@apperp/ui/table';
import { Head } from '@inertiajs/react';
import { RotateCw } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Bar, BarChart, CartesianGrid, XAxis, YAxis } from 'recharts';
import Heading from '@/components/heading';
import { useDateTimeFormat } from '@/hooks/use-date-time';
import {
    formatDimensionValue,
    formatMeasureValue,
} from '@/lib/analytics/format';
import type {
    AnalyticsQuery,
    ResultColumn,
    ResultSet,
    ResultValue,
} from '@/lib/analytics/types';
import { apiJson, errorText } from '@/lib/core-api';
import type { BreadcrumbItem } from '@/types/navigation';

type PreviewQuery = { caption: string; query: AnalyticsQuery };

type Props = {
    preview: {
        dataset: { code: string; caption: string };
        tile: PreviewQuery;
        chart: PreviewQuery | null;
    } | null;
};

type Row = Record<string, ResultValue>;

/**
 * Analisis data, halaman sementara kerangka berjalan engine analitik (area 0): satu tile dan satu
 * grafik kolom dari `POST /api/v1/analytics/query`. Query-nya disusun server dari dataset pertama yang
 * boleh dibaca pengguna ini, jadi layar ini tidak menyebut module mana pun. Area 8 menggantinya dengan
 * penjelajah sungguhan.
 */
export default function AnalyticsExplore({ preview }: Props) {
    return (
        <>
            <Head title="Analisis data" />
            <main className="mx-auto flex w-full max-w-7xl min-w-0 flex-col gap-6 p-6">
                <Heading title="Analisis data" />
                {preview === null ? (
                    <Card>
                        <CardContent>
                            <Empty>
                                <EmptyHeader>
                                    <EmptyTitle>
                                        Belum ada data yang dapat dianalisis
                                    </EmptyTitle>
                                    <EmptyDescription>
                                        Data muncul di sini setelah aplikasi
                                        yang menyediakannya terpasang dan Anda
                                        mendapat akses untuk melihatnya.
                                    </EmptyDescription>
                                </EmptyHeader>
                            </Empty>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="grid grid-cols-1 gap-4 lg:grid-cols-12">
                        <div className="lg:col-span-4">
                            <TileCard {...preview.tile} />
                        </div>
                        {preview.chart && (
                            <div className="lg:col-span-8">
                                <ColumnChartCard {...preview.chart} />
                            </div>
                        )}
                    </div>
                )}
            </main>
        </>
    );
}

AnalyticsExplore.layout = {
    breadcrumbs: [
        { title: 'Analisis data', href: '/analytics/explore' },
    ] satisfies BreadcrumbItem[],
};

/**
 * Hasil satu query, dimuat ulang saat query berubah atau saat diminta. Hasil yang tersimpan diberi
 * kunci query-nya, sehingga hasil query sebelumnya tidak pernah tampil sebagai hasil query sekarang.
 */
function useQueryResult(query: AnalyticsQuery) {
    const [attempt, setAttempt] = useState(0);
    const [loaded, setLoaded] = useState<{
        key: string;
        result: ResultSet | null;
        error: string | null;
    } | null>(null);
    const body = JSON.stringify(query);
    const key = `${attempt}:${body}`;

    useEffect(() => {
        const controller = new AbortController();

        apiJson<ResultSet>('/api/v1/analytics/query', {
            method: 'POST',
            body,
            signal: controller.signal,
        })
            .then((result) => setLoaded({ key, result, error: null }))
            .catch((caught: unknown) => {
                if (!controller.signal.aborted) {
                    setLoaded({
                        key,
                        result: null,
                        error: errorText(caught, 'Data belum dapat dimuat.'),
                    });
                }
            });

        return () => controller.abort();
    }, [body, key]);

    const current = loaded !== null && loaded.key === key ? loaded : null;

    return {
        loading: current === null,
        result: current?.result ?? null,
        error: current?.error ?? null,
        reload: () => setAttempt((value) => value + 1),
    };
}

function TileCard({ caption, query }: PreviewQuery) {
    const { loading, result, error, reload } = useQueryResult(query);
    const measure = result?.columns.find((column) => column.kind === 'measure');

    return (
        <Card className="h-full">
            <CardHeader>
                <CardTitle>{caption}</CardTitle>
            </CardHeader>
            <CardContent className="space-y-2">
                {loading && <Skeleton className="h-9 w-32" />}
                {error !== null && (
                    <QueryFailure message={error} onRetry={reload} />
                )}
                {result && measure && (
                    <>
                        {/* Uang per mata uang ditulis berdampingan, tidak pernah dijumlah. */}
                        <p className="text-3xl font-semibold tabular-nums">
                            {result.rows
                                .map((row) => formatMeasureValue(measure, row))
                                .join(' · ')}
                        </p>
                        <ComputedAt result={result} />
                    </>
                )}
            </CardContent>
        </Card>
    );
}

function ColumnChartCard({ caption, query }: PreviewQuery) {
    const { loading, result, error, reload } = useQueryResult(query);
    const dimension = result?.columns.find(
        (column) => column.kind === 'dimension' && !column.implicit,
    );
    const measure = result?.columns.find((column) => column.kind === 'measure');
    const currency = result?.columns.find((column) => column.implicit);

    return (
        <Card className="h-full">
            <CardHeader>
                <CardTitle>{caption}</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
                {loading && <Skeleton className="h-64 w-full" />}
                {error !== null && (
                    <QueryFailure message={error} onRetry={reload} />
                )}
                {result &&
                    dimension &&
                    measure &&
                    (result.rows.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Belum ada data untuk digambar.
                        </p>
                    ) : (
                        <>
                            {/* Satu panel per mata uang: uang lintas mata uang tidak digambar sebagai satu seri. */}
                            {groupRows(result.rows, currency).map(
                                ([label, rows]) => (
                                    <ColumnPanel
                                        key={label}
                                        title={currency ? label : null}
                                        rows={rows}
                                        dimension={dimension}
                                        measure={measure}
                                    />
                                ),
                            )}
                            <ComputedAt result={result} />
                        </>
                    ))}
            </CardContent>
        </Card>
    );
}

function ColumnPanel({
    title,
    rows,
    dimension,
    measure,
}: {
    title: string | null;
    rows: Row[];
    dimension: ResultColumn;
    measure: ResultColumn;
}) {
    const config = {
        value: { label: measure.caption, color: 'var(--chart-1)' },
    } satisfies ChartConfig;
    // Angka diubah ke Number hanya untuk menggambar; tooltip dan tabel memformat dari nilai aslinya.
    const data = rows.map((row) => ({
        label: formatDimensionValue(dimension, row),
        value: Number(row[measure.key] ?? 0),
        row,
    }));
    const summary = `${measure.caption} per ${dimension.caption.toLowerCase()}${title ? ` (${title})` : ''}: ${data
        .map((item) => `${item.label} ${formatMeasureValue(measure, item.row)}`)
        .join('; ')}`;

    return (
        <section className="space-y-2">
            {title && <h3 className="text-sm font-medium">{title}</h3>}
            <ChartContainer
                config={config}
                className="aspect-auto h-64 w-full"
                role="figure"
                aria-label={summary}
            >
                <BarChart data={data} accessibilityLayer>
                    <CartesianGrid vertical={false} />
                    <XAxis dataKey="label" tickLine={false} axisLine={false} />
                    <YAxis
                        width={88}
                        tickLine={false}
                        axisLine={false}
                        tickFormatter={(value: number) =>
                            formatMeasureValue(
                                measure,
                                { ...rows[0], [measure.key]: value },
                                true,
                            )
                        }
                    />
                    <ChartTooltip
                        content={
                            <ChartTooltipContent
                                formatter={(_value, _name, item) => (
                                    <div className="flex w-full justify-between gap-4">
                                        <span className="text-muted-foreground">
                                            {measure.caption}
                                        </span>
                                        <span className="font-mono font-medium tabular-nums">
                                            {formatMeasureValue(
                                                measure,
                                                (item.payload as { row: Row })
                                                    .row,
                                            )}
                                        </span>
                                    </div>
                                )}
                            />
                        }
                    />
                    <Bar dataKey="value" fill="var(--color-value)" radius={4} />
                </BarChart>
            </ChartContainer>
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>{dimension.caption}</TableHead>
                        <TableHead className="text-right">
                            {measure.caption}
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {data.map((item) => (
                        <TableRow key={String(item.row[dimension.key] ?? '')}>
                            <TableCell>{item.label}</TableCell>
                            <TableCell className="text-right tabular-nums">
                                {formatMeasureValue(measure, item.row)}
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </section>
    );
}

function QueryFailure({
    message,
    onRetry,
}: {
    message: string;
    onRetry: () => void;
}) {
    return (
        <div className="space-y-2">
            <p className="text-sm text-destructive">{message}</p>
            <Button type="button" size="sm" variant="outline" onClick={onRetry}>
                <RotateCw />
                Muat ulang
            </Button>
        </div>
    );
}

function ComputedAt({ result }: { result: ResultSet }) {
    const formatTime = useDateTimeFormat();

    return (
        <p className="text-xs text-muted-foreground">
            Dihitung {formatTime(result.meta.generated_at)}
            {result.meta.truncated && ' · hasil dipotong'}
        </p>
    );
}

/** Baris dikelompokkan per mata uang tersirat, urutan kemunculannya dipertahankan. */
function groupRows(
    rows: Row[],
    currency: ResultColumn | undefined,
): Array<[string, Row[]]> {
    const groups = new Map<string, Row[]>();

    for (const row of rows) {
        const label = currency ? String(row[currency.key] ?? '') : '';
        groups.set(label, [...(groups.get(label) ?? []), row]);
    }

    return [...groups.entries()];
}
