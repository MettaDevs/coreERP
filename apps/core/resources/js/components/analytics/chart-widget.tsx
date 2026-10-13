import type { ChartConfig } from '@apperp/ui/chart';
import {
    ChartContainer,
    ChartLegend,
    ChartLegendContent,
    ChartTooltip,
    ChartTooltipContent,
} from '@apperp/ui/chart';
import {
    Area,
    AreaChart,
    Bar,
    BarChart,
    CartesianGrid,
    Cell,
    LabelList,
    Line,
    LineChart,
    Pie,
    PieChart,
    XAxis,
    YAxis,
} from 'recharts';
import {
    formatAxisValue,
    formatDimensionValue,
    formatMeasureValue,
    sumDecimals,
} from '@/lib/analytics/format';
import { groupRowsByImplicit, implicitColumns } from '@/lib/analytics/query';
import type {
    CartesianVisual,
    DonutVisual,
    ResultColumn,
    ResultSet,
    ResultValue,
} from '@/lib/analytics/types';
import { cn } from '@/lib/utils';

/**
 * Grafik widget lewat `@apperp/ui/chart` (Recharts), dimuat malas oleh `WidgetContent` sehingga dasbor
 * tanpa grafik tidak mengunduh Recharts (`docs/todo/analitik/dasbor-dan-visual.md`, bagian *Grafik*).
 *
 * - Uang lintas mata uang tidak pernah menjadi satu seri: hasil dibagi per mata uang (dan satuan), satu
 *   panel per kelompok.
 * - Angka diubah ke `Number` hanya untuk menggambar; tooltip, label nilai, dan `aria-label` memformat dari
 *   string aslinya lewat `format.ts`.
 * - Warna seri dari token `--chart-1` … `--chart-5`, jadi tema gelap ikut. Kunci warna `s0`, `s1`, …,
 *   bukan nilai data, karena nilai data dapat memuat karakter yang tidak sah di nama variabel CSS.
 * - Tanpa animasi: dasbor berisi banyak grafik tidak bergerak serentak saat dibuka, dan gambarnya langsung
 *   sesuai angkanya bagi yang memilih gerak dikurangi.
 * - Titik dibatasi: grafik yang terlalu padat meminta pengelompokan yang lebih kasar alih-alih menggambar
 *   ribuan titik; padanan tabelnya tetap ada di menu widget.
 */

type Row = Record<string, ResultValue>;

export type ChartWidgetType = 'bar' | 'column' | 'line' | 'area' | 'donut';

/** Titik terbanyak grafik garis dan area (setahun per hari), dan kelompok terbanyak grafik batang. */
const MAX_TIME_POINTS = 366;

const MAX_CATEGORIES = 60;

const MAX_SERIES = 12;

const DEFAULT_DONUT_SLICES = 8;

/** Sebutan isi grafik untuk pembaca layar: sampai sekian butir, sisanya disebut jumlahnya. */
const SUMMARY_ITEMS = 12;

const color = (index: number) => `var(--chart-${(index % 5) + 1})`;

const tick = (label: string, length = 18) =>
    label.length > length ? `${label.slice(0, length - 1)}…` : label;

export default function ChartWidget({
    type,
    visual,
    result,
    title,
    heightClass,
    onPointSelect,
}: {
    type: ChartWidgetType;
    visual: CartesianVisual | DonutVisual;
    result: ResultSet;
    title: string;
    /** Tinggi area gambar, kelas Tailwind lengkap, misalnya `h-64`. */
    heightClass: string;
    onPointSelect?: (row: Row) => void;
}) {
    const panels = groupRowsByImplicit(result.rows, implicitColumns(result));

    return (
        <div className="space-y-4">
            {panels.map((panel) => (
                <section key={panel.label} className="space-y-2">
                    {panels.length > 1 && panel.label !== '' && (
                        <h3 className="text-sm font-medium">{panel.label}</h3>
                    )}
                    {type === 'donut' ? (
                        <DonutPanel
                            result={result}
                            visual={visual as DonutVisual}
                            rows={panel.rows}
                            title={title}
                            panel={panel.label}
                            heightClass={heightClass}
                            onPointSelect={onPointSelect}
                        />
                    ) : (
                        <CartesianPanel
                            type={type}
                            result={result}
                            visual={visual as CartesianVisual}
                            rows={panel.rows}
                            title={title}
                            panel={panel.label}
                            heightClass={heightClass}
                            onPointSelect={onPointSelect}
                        />
                    )}
                </section>
            ))}
        </div>
    );
}

function columnOf(result: ResultSet, key: string | undefined) {
    return key === undefined
        ? undefined
        : result.columns.find((column) => column.key === key);
}

/** Kalimat pengganti grafik yang tidak digambar, dengan saran yang dapat dilakukan. */
function ChartNotice({
    heightClass,
    children,
}: {
    heightClass: string;
    children: string;
}) {
    return (
        <div
            className={cn(
                'flex items-center justify-center rounded-md border border-dashed p-4 text-center text-sm text-muted-foreground',
                heightClass,
            )}
        >
            {children}
        </div>
    );
}

type SeriesKey = {
    id: string;
    label: string;
    measure: ResultColumn;
};

type Point = {
    __x: string;
    /** Baris asal tiap seri, untuk memformat tooltip dari nilai aslinya. */
    __rows: Record<string, Row>;
    [series: string]: number | null | string | Record<string, Row>;
};

function rowFromChartEvent(value: unknown, seriesId?: string): Row | null {
    if (typeof value !== 'object' || value === null) {
        return null;
    }

    const event = value as { payload?: unknown };
    const point = (event.payload ?? value) as Record<string, unknown>;
    const rows = point.__rows;

    if (typeof rows !== 'object' || rows === null) {
        return null;
    }

    const selected =
        seriesId === undefined
            ? Object.values(rows as Record<string, unknown>)[0]
            : (rows as Record<string, unknown>)[seriesId];

    return typeof selected === 'object' && selected !== null
        ? (selected as Row)
        : null;
}

function CartesianPanel({
    type,
    result,
    visual,
    rows,
    title,
    panel,
    heightClass,
    onPointSelect,
}: {
    type: Exclude<ChartWidgetType, 'donut'>;
    result: ResultSet;
    visual: CartesianVisual;
    rows: Row[];
    title: string;
    panel: string;
    heightClass: string;
    onPointSelect?: (row: Row) => void;
}) {
    const x = columnOf(result, visual.x);
    const series = columnOf(result, visual.series);
    const measures = visual.y
        .map((key) => columnOf(result, key))
        .filter((column): column is ResultColumn => column !== undefined);

    if (x === undefined || measures.length === 0) {
        return (
            <ChartNotice heightClass={heightClass}>
                Grafik ini belum dapat digambar. Lihat sebagai tabel dari menu
                bagian ini.
            </ChartNotice>
        );
    }

    // Satu seri per nilai pengelompok kedua (dikali nilai bila lebih dari satu), urut kemunculan.
    const keys: SeriesKey[] = [];
    const keyIndex = new Map<string, SeriesKey>();
    const points = new Map<string, Point>();

    for (const row of rows) {
        const xValue = String(row[x.key] ?? '');
        const point =
            points.get(xValue) ??
            ({
                __x: formatDimensionValue(x, row, result.meta.timezone),
                __rows: {},
            } as Point);
        points.set(xValue, point);

        const seriesValue = series ? String(row[series.key] ?? '') : '';

        for (const measure of measures) {
            const identity = `${seriesValue}\u0000${measure.key}`;
            let key = keyIndex.get(identity);

            if (key === undefined) {
                const seriesLabel = series
                    ? formatDimensionValue(series, row, result.meta.timezone)
                    : '';
                key = {
                    id: `s${keys.length}`,
                    label:
                        series === undefined
                            ? measure.caption
                            : measures.length > 1
                              ? `${measure.caption} · ${seriesLabel}`
                              : seriesLabel,
                    measure,
                };
                keyIndex.set(identity, key);
                keys.push(key);
            }

            const raw = row[measure.key];
            point[key.id] =
                raw === null || raw === undefined ? null : Number(raw);
            point.__rows[key.id] = row;
        }
    }

    const data = [...points.values()];
    const timeAxis = type === 'line' || type === 'area';

    if (data.length > (timeAxis ? MAX_TIME_POINTS : MAX_CATEGORIES)) {
        return (
            <ChartNotice heightClass={heightClass}>
                {timeAxis
                    ? `Terlalu banyak titik untuk digambar (${data.length}). Pilih pengelompokan waktu yang lebih kasar, misalnya per bulan, atau lihat sebagai tabel.`
                    : `Terlalu banyak kelompok untuk digambar (${data.length}). Persempit saringannya atau lihat sebagai tabel.`}
            </ChartNotice>
        );
    }

    if (keys.length > MAX_SERIES) {
        return (
            <ChartNotice heightClass={heightClass}>
                {`Terlalu banyak seri untuk digambar (${keys.length}). Lihat sebagai tabel dari menu bagian ini.`}
            </ChartNotice>
        );
    }

    const config = Object.fromEntries(
        keys.map((key, index) => [
            key.id,
            { label: key.label, color: color(index) },
        ]),
    ) satisfies ChartConfig;
    const sample = rows[0] ?? {};
    const percent = visual.stacked === 'percent';
    const stackId =
        visual.stacked === 'stacked' || percent ? 'stack' : undefined;
    const horizontal = type === 'bar';
    const valueTick = (value: number) =>
        percent
            ? `${Math.round(value * 100)}%`
            : formatAxisValue(keys[0].measure, sample, value);
    const summary = summarize(
        title,
        panel,
        data.map((point) => ({
            label: point.__x,
            values: keys
                .filter((key) => point.__rows[key.id] !== undefined)
                .map(
                    (key) =>
                        `${keys.length > 1 ? `${key.label} ` : ''}${formatMeasureValue(key.measure, point.__rows[key.id])}`,
                ),
        })),
    );

    const tooltip = (
        <ChartTooltip
            content={
                <ChartTooltipContent
                    formatter={(_value, name, item) => {
                        const key = keys.find((entry) => entry.id === name);
                        const row = (item.payload as Point).__rows[
                            String(name)
                        ];

                        return key && row ? (
                            <div className="flex w-full items-center justify-between gap-4">
                                <span className="flex items-center gap-1.5 text-muted-foreground">
                                    <span
                                        className="size-2.5 shrink-0 rounded-[2px]"
                                        style={{
                                            backgroundColor: `var(--color-${key.id})`,
                                        }}
                                    />
                                    {key.label}
                                </span>
                                <span className="font-mono font-medium text-foreground tabular-nums">
                                    {formatMeasureValue(key.measure, row)}
                                </span>
                            </div>
                        ) : null;
                    }}
                />
            }
        />
    );
    const legend = keys.length > 1 && (
        <ChartLegend content={<ChartLegendContent />} />
    );
    // Nilai bulat (jumlah) tidak diberi garis bantu pecahan seperti "0,5 aset".
    const whole = data.every((point) =>
        keys.every((key) => {
            const value = point[key.id];

            return typeof value !== 'number' || Number.isInteger(value);
        }),
    );
    // Jumlah kecil (misalnya paling banyak 1) tidak diberi sumbu sampai 4: satu garis bantu per bilangan bulat.
    const highest = Math.max(
        0,
        ...data.map((point) => {
            const values = keys.map((key) => {
                const value = point[key.id];

                return typeof value === 'number' ? value : 0;
            });

            return stackId
                ? values.reduce((sum, value) => sum + value, 0)
                : Math.max(0, ...values);
        }),
    );
    const valueAxisProps = {
        tickLine: false,
        axisLine: false,
        allowDecimals: percent || !whole,
        tickCount:
            whole && !percent && highest >= 1 && highest < 4
                ? highest + 1
                : undefined,
        tickFormatter: valueTick,
        domain: percent ? ([0, 1] as [number, number]) : undefined,
    };
    const categoryAxisProps = {
        dataKey: '__x',
        tickLine: false,
        axisLine: false,
        tickFormatter: (label: string) => tick(label),
    };
    const labels = (key: SeriesKey) =>
        visual.show_values && (
            <LabelList
                dataKey={key.id}
                position={stackId ? 'inside' : horizontal ? 'right' : 'top'}
                className="fill-foreground"
                fontSize={11}
                formatter={(value: unknown) =>
                    typeof value === 'number'
                        ? formatAxisValue(key.measure, sample, value)
                        : ''
                }
            />
        );

    return (
        <ChartContainer
            config={config}
            className={cn('aspect-auto w-full', heightClass)}
            role="figure"
            aria-label={summary}
        >
            {type === 'line' ? (
                <LineChart data={data} accessibilityLayer>
                    <CartesianGrid vertical={false} />
                    <XAxis {...categoryAxisProps} minTickGap={16} />
                    <YAxis {...valueAxisProps} width={88} />
                    {tooltip}
                    {legend}
                    {keys.map((key) => (
                        <Line
                            key={key.id}
                            dataKey={key.id}
                            type="linear"
                            stroke={`var(--color-${key.id})`}
                            strokeWidth={2}
                            dot={data.length <= 31}
                            connectNulls={false}
                            isAnimationActive={false}
                            onClick={(event: unknown) => {
                                const row = rowFromChartEvent(event, key.id);

                                if (row) {
                                    onPointSelect?.(row);
                                }
                            }}
                        >
                            {labels(key)}
                        </Line>
                    ))}
                </LineChart>
            ) : type === 'area' ? (
                <AreaChart
                    data={data}
                    accessibilityLayer
                    stackOffset={percent ? 'expand' : undefined}
                >
                    <CartesianGrid vertical={false} />
                    <XAxis {...categoryAxisProps} minTickGap={16} />
                    <YAxis {...valueAxisProps} width={88} />
                    {tooltip}
                    {legend}
                    {keys.map((key) => (
                        <Area
                            key={key.id}
                            dataKey={key.id}
                            type="linear"
                            stroke={`var(--color-${key.id})`}
                            fill={`var(--color-${key.id})`}
                            fillOpacity={0.25}
                            strokeWidth={2}
                            stackId={stackId}
                            isAnimationActive={false}
                            onClick={(event: unknown) => {
                                const row = rowFromChartEvent(event, key.id);

                                if (row) {
                                    onPointSelect?.(row);
                                }
                            }}
                        >
                            {labels(key)}
                        </Area>
                    ))}
                </AreaChart>
            ) : (
                <BarChart
                    data={data}
                    accessibilityLayer
                    layout={horizontal ? 'vertical' : 'horizontal'}
                    stackOffset={percent ? 'expand' : undefined}
                >
                    <CartesianGrid
                        vertical={horizontal}
                        horizontal={!horizontal}
                    />
                    {horizontal ? (
                        <>
                            <XAxis type="number" {...valueAxisProps} />
                            <YAxis
                                type="category"
                                {...categoryAxisProps}
                                width={128}
                            />
                        </>
                    ) : (
                        <>
                            <XAxis {...categoryAxisProps} />
                            <YAxis {...valueAxisProps} width={88} />
                        </>
                    )}
                    {tooltip}
                    {legend}
                    {keys.map((key) => (
                        <Bar
                            key={key.id}
                            dataKey={key.id}
                            fill={`var(--color-${key.id})`}
                            radius={stackId ? 0 : 4}
                            stackId={stackId}
                            isAnimationActive={false}
                            onClick={(event: unknown) => {
                                const row = rowFromChartEvent(event, key.id);

                                if (row) {
                                    onPointSelect?.(row);
                                }
                            }}
                        >
                            {labels(key)}
                        </Bar>
                    ))}
                </BarChart>
            )}
        </ChartContainer>
    );
}

type Slice = {
    key: string;
    label: string;
    value: number;
    /** Nilai asli dari server (atau jumlah persisnya untuk potongan "Lainnya"). */
    raw: string | number | null;
    row?: Row;
};

function DonutPanel({
    result,
    visual,
    rows,
    title,
    panel,
    heightClass,
    onPointSelect,
}: {
    result: ResultSet;
    visual: DonutVisual;
    rows: Row[];
    title: string;
    panel: string;
    heightClass: string;
    onPointSelect?: (row: Row) => void;
}) {
    const category = columnOf(result, visual.category);
    const measure = columnOf(result, visual.value);

    if (category === undefined || measure === undefined) {
        return (
            <ChartNotice heightClass={heightClass}>
                Grafik ini belum dapat digambar. Lihat sebagai tabel dari menu
                bagian ini.
            </ChartNotice>
        );
    }

    const sample = rows[0] ?? {};
    const sorted = rows
        .map((row) => ({
            label: formatDimensionValue(category, row, result.meta.timezone),
            value: Number(row[measure.key] ?? 0),
            raw: row[measure.key] as string | number | null,
            row,
        }))
        .sort((a, b) => b.value - a.value);
    const limit = visual.max_slices ?? DEFAULT_DONUT_SLICES;
    const kept = sorted.length > limit ? sorted.slice(0, limit - 1) : sorted;
    const rest = sorted.length > limit ? sorted.slice(limit - 1) : [];
    const slices: Slice[] = [
        ...kept.map((slice, index) => ({ key: `p${index}`, ...slice })),
        ...(rest.length > 0
            ? [
                  {
                      key: `p${kept.length}`,
                      label: `Lainnya (${rest.length})`,
                      value: rest.reduce((sum, slice) => sum + slice.value, 0),
                      raw: sumDecimals(rest.map((slice) => slice.raw)),
                  },
              ]
            : []),
    ].filter((slice) => slice.value > 0);

    if (slices.length === 0) {
        return (
            <ChartNotice heightClass={heightClass}>
                Semua nilai nol, jadi tidak ada bagian untuk digambar.
            </ChartNotice>
        );
    }

    const total = slices.reduce((sum, slice) => sum + slice.value, 0);
    const format = (slice: Slice) =>
        formatMeasureValue(measure, { ...sample, [measure.key]: slice.raw });
    const share = (slice: Slice) =>
        `${new Intl.NumberFormat('id-ID', { maximumFractionDigits: 1 }).format((slice.value / total) * 100)}%`;
    const config = Object.fromEntries(
        slices.map((slice, index) => [
            slice.key,
            { label: slice.label, color: color(index) },
        ]),
    ) satisfies ChartConfig;
    const summary = summarize(
        title,
        panel,
        slices.map((slice) => ({
            label: slice.label,
            values: [`${format(slice)} (${share(slice)})`],
        })),
    );

    return (
        <ChartContainer
            config={config}
            className={cn('aspect-auto w-full', heightClass)}
            role="figure"
            aria-label={summary}
        >
            <PieChart accessibilityLayer>
                <ChartTooltip
                    content={
                        <ChartTooltipContent
                            nameKey="key"
                            formatter={(_value, _name, item) => {
                                const slice = item.payload as Slice;

                                return (
                                    <div className="flex w-full items-center justify-between gap-4">
                                        <span className="text-muted-foreground">
                                            {slice.label}
                                        </span>
                                        <span className="font-mono font-medium text-foreground tabular-nums">
                                            {format(slice)} · {share(slice)}
                                        </span>
                                    </div>
                                );
                            }}
                        />
                    }
                />
                <Pie
                    data={slices}
                    dataKey="value"
                    nameKey="key"
                    innerRadius="55%"
                    outerRadius="85%"
                    strokeWidth={2}
                    isAnimationActive={false}
                    onClick={(entry: unknown) => {
                        if (typeof entry !== 'object' || entry === null) {
                            return;
                        }

                        const payload = (entry as { payload?: unknown })
                            .payload;

                        if (typeof payload !== 'object' || payload === null) {
                            return;
                        }

                        const row = (payload as Slice).row;

                        if (row) {
                            onPointSelect?.(row);
                        }
                    }}
                >
                    {slices.map((slice, index) => (
                        <Cell
                            key={slice.key}
                            fill={`var(--color-${slice.key})`}
                            fillOpacity={
                                index < 5 ? 1 : index < 10 ? 0.7 : 0.45
                            }
                        />
                    ))}
                </Pie>
                <ChartLegend
                    content={
                        <ChartLegendContent
                            nameKey="key"
                            className="flex-wrap gap-x-4 gap-y-1"
                        />
                    }
                />
            </PieChart>
        </ChartContainer>
    );
}

/**
 * `aria-label` grafik: judul, kelompok mata uang bila ada, lalu nilai per kelompok — padanan lisan dari
 * gambarnya. Daftar panjang dipotong dan sisanya disebut jumlahnya.
 */
function summarize(
    title: string,
    panel: string,
    items: Array<{ label: string; values: string[] }>,
): string {
    const listed = items
        .slice(0, SUMMARY_ITEMS)
        .map((item) => `${item.label}: ${item.values.join(', ')}`);
    const more =
        items.length > SUMMARY_ITEMS
            ? `; dan ${items.length - SUMMARY_ITEMS} lainnya`
            : '';

    return `${title}${panel ? ` (${panel})` : ''}. ${listed.join('; ')}${more}`;
}
