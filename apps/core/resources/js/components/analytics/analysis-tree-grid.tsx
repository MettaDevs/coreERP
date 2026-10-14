import { Button } from '@apperp/ui/button';
import { DataTable } from '@apperp/ui/data-table';
import { TableCell, TableRow } from '@apperp/ui/table';
import { ChevronDown, ChevronRight } from 'lucide-react';
import { Fragment, useState } from 'react';
import { analysisColumnTools } from '@/components/analytics/analysis-column-tools';
import {
    queryErrorPlacement,
    useQueryPreview,
} from '@/components/analytics/use-query-preview';
import {
    formatDimensionValue,
    formatMeasureValue,
} from '@/lib/analytics/format';
import type {
    AnalyticsQuery,
    DatasetDescription,
    ResultColumn,
    ResultSet,
    ResultValue,
} from '@/lib/analytics/types';
type Row = Record<string, ResultValue>;
type Layout = {
    columns: string[];
    groups: string[];
    measures: string[];
    filters: Record<string, string | string[]>;
    sort?: AnalyticsQuery['sort'];
};
function basis(dataset: DatasetDescription, layout: Layout): string[] {
    return [
        ...new Set([
            ...dataset.partitions,
            ...dataset.measures
                .filter((item) => layout.measures.includes(item.key))
                .flatMap((item) => [item.currency_key, item.unit_key])
                .filter((key): key is string => key !== undefined),
        ]),
    ];
}
function query(
    dataset: DatasetDescription,
    layout: Layout,
    level: number,
    drill: AnalyticsQuery['drill'] = {},
): AnalyticsQuery {
    const leaf = level >= layout.groups.length && level > 0;
    const fields = [
        ...new Set([
            ...layout.columns,
            ...layout.groups,
            ...basis(dataset, layout),
            ...dataset.measures
                .filter((item) => layout.measures.includes(item.key))
                .map((item) => item.field_key)
                .filter((key): key is string => key !== undefined),
        ]),
    ];
    const dimensions = leaf
        ? fields
        : layout.groups[level]
          ? [layout.groups[level]]
          : [];

    return {
        dataset: dataset.code,
        dimensions,
        measures: leaf ? [] : layout.measures,
        records: leaf,
        filters: layout.filters,
        drill,
        fill_gaps: false,
        totals: level === 0,
        sort: (layout.sort ?? []).filter(
            (item) =>
                dimensions.includes(item.key) ||
                (!leaf && layout.measures.includes(item.key)),
        ),
    };
}
function message(error: unknown, result: ResultSet | null): string | null {
    const place = queryErrorPlacement(error);

    return (
        Object.values(place.filters).join(' ') ||
        place.timeRange ||
        place.message ||
        (result?.meta.truncated
            ? 'Sebagian baris belum ditampilkan. Persempit filter untuk melihat sisanya.'
            : null)
    );
}
function text(column: ResultColumn, row: Row): string {
    return column.kind === 'measure'
        ? formatMeasureValue(column, row)
        : formatDimensionValue(column, row);
}
function Rows({
    dataset,
    layout,
    columns,
    root,
    level = 0,
    drill = {},
    refresh,
}: {
    dataset: DatasetDescription;
    layout: Layout;
    columns: ResultColumn[];
    root?: ResultSet;
    level?: number;
    drill?: AnalyticsQuery['drill'];
    refresh: boolean;
}) {
    const [expanded, setExpanded] = useState<string[]>([]);
    const preview = useQueryPreview(
        root ? null : query(dataset, layout, level, drill),
        refresh,
    );
    const result =
        root ?? preview.result ?? (preview.loading ? preview.previous : null);
    const leaf = level >= layout.groups.length;
    const warning = message(preview.error, result);

    return (
        <>
            {warning && (
                <TableRow>
                    <TableCell colSpan={columns.length}>{warning}</TableCell>
                </TableRow>
            )}
            {preview.loading && (
                <TableRow>
                    <TableCell colSpan={columns.length}>
                        Memuat detail...
                    </TableCell>
                </TableRow>
            )}
            {result?.rows.map((row, index) => {
                const dimensions = result.columns.filter(
                    (item) => item.kind === 'dimension',
                );
                const key = leaf
                    ? `row:${index}`
                    : JSON.stringify(dimensions.map((item) => row[item.key]));
                const open = expanded.includes(key);
                const current =
                    layout.groups[
                        leaf ? Math.max(0, layout.groups.length - 1) : level
                    ];
                const groupColumn = result.columns.find(
                    (item) => item.key === current,
                );
                const label = groupColumn
                    ? formatDimensionValue(groupColumn, row)
                    : leaf
                      ? String(index + 1)
                      : 'Keseluruhan';
                const next = { ...drill };

                if (!leaf) {
                    for (const column of dimensions) {
                        next[column.key] = row[column.key];
                    }
                }

                return (
                    <Fragment key={key}>
                        <TableRow>
                            {columns.map((column, columnIndex) => {
                                if (columnIndex === 0) {
                                    return (
                                        <TableCell
                                            key={column.key}
                                            className="border-b"
                                        >
                                            <div
                                                style={{
                                                    paddingInlineStart:
                                                        level * 16,
                                                }}
                                            >
                                                {leaf ? (
                                                    <span>{label}</span>
                                                ) : (
                                                    <Button
                                                        variant="ghost"
                                                        size="xs"
                                                        aria-expanded={open}
                                                        onClick={() =>
                                                            setExpanded(
                                                                open
                                                                    ? expanded.filter(
                                                                          (
                                                                              item,
                                                                          ) =>
                                                                              item !==
                                                                              key,
                                                                      )
                                                                    : [
                                                                          ...expanded,
                                                                          key,
                                                                      ],
                                                            )
                                                        }
                                                    >
                                                        {open ? (
                                                            <ChevronDown />
                                                        ) : (
                                                            <ChevronRight />
                                                        )}
                                                        {label}
                                                    </Button>
                                                )}
                                            </div>
                                        </TableCell>
                                    );
                                }

                                let resolved =
                                    result.columns.find(
                                        (item) => item.key === column.key,
                                    ) ?? column;
                                let values = row;

                                if (leaf && column.kind === 'measure') {
                                    const measure = dataset.measures.find(
                                        (item) => item.key === column.key,
                                    );
                                    const value = measure?.field_key
                                        ? row[measure.field_key]
                                        : null;
                                    values = {
                                        ...row,
                                        [column.key]:
                                            measure?.aggregate === 'count' ||
                                            measure?.aggregate ===
                                                'count_distinct'
                                                ? measure?.field_key &&
                                                  value === null
                                                    ? 0
                                                    : 1
                                                : (value ?? null),
                                    };
                                    resolved = column;
                                }

                                return (
                                    <TableCell
                                        key={column.key}
                                        className={
                                            column.kind === 'measure'
                                                ? 'border-b text-right tabular-nums'
                                                : 'border-b'
                                        }
                                    >
                                        {row[column.key] !== undefined || leaf
                                            ? text(resolved, values)
                                            : ''}
                                    </TableCell>
                                );
                            })}
                        </TableRow>
                        {open && (
                            <Rows
                                dataset={dataset}
                                layout={layout}
                                columns={columns}
                                level={level + 1}
                                drill={next}
                                refresh={refresh}
                            />
                        )}
                    </Fragment>
                );
            })}
            {result && result.rows.length === 0 && (
                <TableRow>
                    <TableCell colSpan={columns.length}>
                        Tidak ada transaksi yang sesuai filter.
                    </TableCell>
                </TableRow>
            )}
        </>
    );
}
/** Satu header dan satu viewport; grup serta detail hanya menambah baris, bukan tabel di dalam tabel. */
export function AnalysisTreeGrid({
    dataset,
    layout,
    refresh = false,
    onChange,
    onResetColumns,
}: {
    dataset: DatasetDescription;
    layout: Layout;
    refresh?: boolean;
    onChange: (patch: Partial<Layout>) => void;
    onResetColumns: () => void;
}) {
    const preview = useQueryPreview(query(dataset, layout, 0), refresh);
    const result =
        preview.result ??
        (preview.loading ||
        Object.keys(queryErrorPlacement(preview.error).filters).length > 0
            ? preview.previous
            : null);
    const measures = dataset.measures.filter((item) =>
        layout.measures.includes(item.key),
    );
    const fieldKeys = measures.map((item) => item.field_key);
    const fields = layout.columns.filter(
        (key) => !layout.groups.includes(key) && !fieldKeys.includes(key),
    );
    const columns: ResultColumn[] = [
        {
            key: '__group',
            kind: 'dimension',
            type: 'text',
            caption:
                layout.groups
                    .map(
                        (key) =>
                            dataset.fields.find((item) => item.key === key)
                                ?.caption ?? key,
                    )
                    .join(' / ') || 'Kelompok',
        },
        ...fields.flatMap((key) => {
            const field = dataset.fields.find((item) => item.key === key);

            return field
                ? [
                      {
                          key,
                          kind: 'dimension' as const,
                          type: field.type,
                          caption: field.caption,
                      },
                  ]
                : [];
        }),
        ...layout.measures.flatMap((key) => {
            const measure = dataset.measures.find((item) => item.key === key);

            return measure
                ? [
                      {
                          key,
                          kind: 'measure' as const,
                          type: 'number' as const,
                          caption: measure.caption,
                          format: measure.format,
                          currency_key: measure.currency_key,
                          unit_key: measure.unit_key,
                      },
                  ]
                : [];
        }),
    ];
    const tools = analysisColumnTools(dataset, layout, onChange);
    const warning = message(preview.error, result);

    return (
        <div className="flex min-h-0 min-w-0 flex-1 flex-col gap-2">
            {warning && <p role="status">{warning}</p>}
            {preview.loading && <p> Menghitung...</p>}
            {result && (
                <DataTable<Row>
                    showColumnMenu
                    onResetColumns={onResetColumns}
                    onResetFilters={() => onChange({ filters: {} })}
                    data={result.rows}
                    getRowKey={(row) => JSON.stringify(row)}
                    showRowNumbers={false}
                    columns={columns.map((column, index) => ({
                        ...tools[
                            column.key === '__group'
                                ? layout.groups[0]
                                : column.key
                        ],
                        id: column.key,
                        header: column.caption,
                        width:
                            index === 0
                                ? 260
                                : column.kind === 'measure'
                                  ? 190
                                  : 160,
                        align:
                            column.kind === 'measure'
                                ? ('right' as const)
                                : ('left' as const),
                        cell: (row) => text(column, row),
                    }))}
                    renderRows={() => (
                        <>
                            <Rows
                                dataset={dataset}
                                layout={layout}
                                columns={columns}
                                root={result}
                                refresh={refresh}
                            />
                            {layout.groups.length > 0 &&
                                result.totals.map((row, index) => (
                                    <TableRow key={`total:${index}`}>
                                        {columns.map((column, columnIndex) => (
                                            <TableCell
                                                key={column.key}
                                                className={
                                                    column.kind === 'measure'
                                                        ? 'border-b text-right font-medium tabular-nums'
                                                        : 'border-b font-medium'
                                                }
                                            >
                                                {columnIndex === 0
                                                    ? 'Total'
                                                    : row[column.key] !==
                                                        undefined
                                                      ? text(
                                                            result.columns.find(
                                                                (item) =>
                                                                    item.key ===
                                                                    column.key,
                                                            ) ?? column,
                                                            row,
                                                        )
                                                      : ''}
                                            </TableCell>
                                        ))}
                                    </TableRow>
                                ))}
                        </>
                    )}
                />
            )}
        </div>
    );
}
