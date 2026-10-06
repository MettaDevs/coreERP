import { Button } from '@apperp/ui/button';
import { DataTable } from '@apperp/ui/data-table';
import type { DataTableColumn } from '@apperp/ui/data-table';
import { Rows3 } from 'lucide-react';
import {
    formatDimensionValue,
    formatMeasureValue,
} from '@/lib/analytics/format';
import { dimensionColumns, measureColumns } from '@/lib/analytics/query';
import type {
    AnalyticsResult,
    ResultColumn,
    ResultValue,
} from '@/lib/analytics/types';
import type { WidgetDatasetField } from '@/lib/analytics/slicer';
import { cn } from '@/lib/utils';

type Line = {
    key: string;
    row: Record<string, ResultValue>;
    /** Teks kolom pertama untuk baris total, misalnya "Total" atau "Total IDR". */
    total: string | null;
};

/**
 * Hasil query sebagai tabel teks biasa (`DataTable` SDK), dengan baris total per mata uang di bawahnya.
 * Dipakai widget tabel, "Lihat sebagai tabel" pada grafik, dan pratinjau pembangun widget (area 8).
 *
 * Urutan baris adalah urutan dari engine; tabel tidak mengurutkan ulang, supaya baris total tetap di bawah
 * dan top-N tetap top-N. Mata uang tidak mendapat kolom sendiri: nilai uang sudah menuliskannya.
 */
export function ResultTable({
    result,
    columns,
    showTotals = true,
    className,
    fields = [],
    onDimensionSelect,
    onDrillRow,
    drillableField,
}: {
    result: Pick<AnalyticsResult, 'columns' | 'rows' | 'totals' | 'meta'>;
    /** Kunci kolom yang ditampilkan, urut; kosong berarti semua pengelompok lalu semua nilai. */
    columns?: string[];
    showTotals?: boolean;
    /** Pembungkus bergulir, misalnya batas tinggi widget. */
    className?: string;
    fields?: WidgetDatasetField[];
    onDimensionSelect?: (
        field: ResultColumn,
        row: Record<string, ResultValue>,
        label: string,
    ) => void;
    onDrillRow?: (row: Record<string, ResultValue>) => void;
    drillableField?: string;
}) {
    // Kolom turunan (perbandingan periode, persen terhadap total; area 13) ikut di samping nilai asalnya walau
    // daftar kolom tabel hanya menyebut nilainya.
    const chosen: ResultColumn[] = (
        columns && columns.length > 0
            ? columns.flatMap((key) => [
                  result.columns.find((column) => column.key === key),
                  ...result.columns.filter(
                      (column) => column.derived_from === key,
                  ),
              ])
            : [...dimensionColumns(result), ...measureColumns(result)]
    ).filter(
        (column): column is ResultColumn =>
            column !== undefined && !column.implicit,
    );
    const firstIsDimension = chosen[0]?.kind === 'dimension';
    // Tanpa pengelompok, setiap baris sudah jumlah keseluruhan: baris total hanya mengulangnya.
    const totals =
        showTotals && firstIsDimension && result.totals.length > 0
            ? result.totals
            : [];
    const lines: Line[] = [
        ...result.rows.map((row, index) => ({
            key: `r${index}`,
            row,
            total: null,
        })),
        ...totals.map((row, index) => ({
            key: `t${index}`,
            row,
            total: totalCaption(result, row),
        })),
    ];

    const tableColumns: DataTableColumn<Line>[] = chosen.map(
        (column, index) => ({
            id: column.key,
            header: column.caption,
            width:
                column.kind === 'dimension'
                    ? 160
                    : column.format === 'money'
                      ? 190
                      : 120,
            minWidth: 96,
            align: column.kind === 'measure' ? 'right' : 'left',
            cell: (line) => {
                const text =
                    line.total !== null
                        ? index === 0
                            ? line.total
                            : column.kind === 'measure'
                              ? formatMeasureValue(column, line.row)
                              : ''
                        : column.kind === 'measure'
                          ? formatMeasureValue(column, line.row)
                          : formatDimensionValue(
                                column,
                                line.row,
                                result.meta.timezone,
                            );

                const canSelect =
                    column.kind === 'dimension' &&
                    line.total === null &&
                    fields.some((field) => field.key === column.key);

                return canSelect && (onDimensionSelect || onDrillRow) ? (
                    <div className="flex min-w-0 items-center gap-1">
                        {onDimensionSelect && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="h-auto min-w-0 justify-start truncate p-0 text-left font-normal"
                                title={text}
                                aria-label={`Saring bagian lain dengan ${column.caption}: ${text}`}
                                onClick={() =>
                                    onDimensionSelect(column, line.row, text)
                                }
                            >
                                <span className="truncate">{text}</span>
                            </Button>
                        )}
                        {onDrillRow &&
                            (drillableField === undefined ||
                                drillableField === column.key) && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    className="h-auto shrink-0 px-1 text-xs"
                                    aria-label={`Lihat baris untuk ${column.caption}: ${text}`}
                                    onClick={() => onDrillRow(line.row)}
                                >
                                    <Rows3 />
                                    Baris
                                </Button>
                            )}
                    </div>
                ) : (
                    <span
                        className={cn(
                            column.kind === 'measure' && 'tabular-nums',
                            line.total !== null && 'font-semibold',
                        )}
                        title={text}
                    >
                        {text}
                    </span>
                );
            },
        }),
    );

    // `DataTable` memotong isinya pada lebarnya sendiri; pembungkus selebar jumlah kolom membuat tabel lebar
    // menggulir mendatar di dalam widget, bukan terpotong, terutama di layar sempit.
    const minWidth = tableColumns.reduce(
        (sum, column) => sum + (column.width ?? 160),
        0,
    );

    return (
        <div className={cn('overflow-auto', className)}>
            <div style={{ minWidth }}>
                <DataTable
                    columns={tableColumns}
                    data={lines}
                    getRowKey={(line) => line.key}
                    showRowNumbers={false}
                    emptyMessage="Belum ada data untuk ditampilkan."
                />
            </div>
        </div>
    );
}

/** "Total", atau "Total IDR" bila total terbagi per mata uang atau satuan. */
function totalCaption(
    result: Pick<AnalyticsResult, 'columns' | 'rows' | 'totals' | 'meta'>,
    row: Record<string, ResultValue>,
): string {
    if (result.totals.length < 2) {
        return 'Total';
    }

    const implicit = result.columns
        .filter((column) => column.implicit)
        .map((column) => row[column.key])
        .filter((value) => value !== null && value !== '');

    return implicit.length > 0 ? `Total ${implicit.join(' · ')}` : 'Total';
}
