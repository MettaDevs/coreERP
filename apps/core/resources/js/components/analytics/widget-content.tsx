import { Skeleton } from '@apperp/ui/skeleton';
import { lazy, Suspense } from 'react';
import type { ChartWidgetType } from '@/components/analytics/chart-widget';
import { KpiTile } from '@/components/analytics/kpi-tile';
import { ResultTable } from '@/components/analytics/result-table';
import type {
    CartesianVisual,
    DonutVisual,
    KpiVisual,
    ResultSet,
    TableVisual,
    WidgetType,
    WidgetVisual,
    DatasetField,
    ResultColumn,
    ResultValue,
} from '@/lib/analytics/types';

/** Recharts hanya diunduh saat ada grafik yang digambar. */
const ChartWidget = lazy(() => import('@/components/analytics/chart-widget'));

/** Tinggi widget di grid (1–3 baris) sebagai tinggi area gambar dan batas tinggi tabel; kelas lengkap. */
export const CHART_HEIGHT: Record<number, string> = {
    1: 'h-40',
    2: 'h-64',
    3: 'h-96',
};

/** Tabel diberi sedikit lebih tinggi dari grafik supaya baris totalnya lebih sering terlihat tanpa digulir. */
const TABLE_HEIGHT: Record<number, string> = {
    1: 'max-h-48',
    2: 'max-h-80',
    3: 'max-h-[28rem]',
};

export const CHART_TYPES: readonly WidgetType[] = [
    'bar',
    'column',
    'line',
    'area',
    'donut',
];

export function isChartType(type: WidgetType): type is ChartWidgetType {
    return CHART_TYPES.includes(type);
}

/**
 * Isi satu widget dari hasil query yang sudah ada: tile, grafik, atau tabel menurut jenisnya. Tidak
 * memuat data dan tidak tahu dasbor, jadi pembangun widget dan penjelajah (area 8) memakainya untuk
 * pratinjau dengan hasil `POST query`. `asTable` menggambar padanan tabel sebuah grafik ("Lihat sebagai
 * tabel"). Widget teks tidak lewat sini karena tidak punya hasil.
 */
export function WidgetContent({
    type,
    visual,
    result,
    title,
    height = 2,
    asTable = false,
    fields = [],
    onDimensionSelect,
    onPointSelect,
    onDrillRow,
    drillableField,
}: {
    type: WidgetType;
    visual: WidgetVisual;
    result: ResultSet;
    title: string;
    /** Tinggi di grid, 1–3. */
    height?: number;
    asTable?: boolean;
    fields?: DatasetField[];
    onDimensionSelect?: (
        field: ResultColumn,
        row: Record<string, ResultValue>,
        label: string,
    ) => void;
    onPointSelect?: (row: Record<string, ResultValue>) => void;
    onDrillRow?: (row: Record<string, ResultValue>) => void;
    drillableField?: string;
}) {
    const chartHeight = CHART_HEIGHT[height] ?? CHART_HEIGHT[2];
    const tableHeight = TABLE_HEIGHT[height] ?? TABLE_HEIGHT[2];

    if (asTable || type === 'table') {
        const table = type === 'table' ? (visual as TableVisual) : null;

        return (
            <ResultTable
                result={result}
                columns={table?.columns}
                showTotals={table ? table.show_totals !== false : true}
                className={tableHeight}
                fields={fields}
                onDimensionSelect={onDimensionSelect}
                onDrillRow={onDrillRow}
                drillableField={drillableField}
            />
        );
    }

    if (type === 'kpi') {
        return <KpiTile result={result} visual={visual as KpiVisual} />;
    }

    if (isChartType(type)) {
        return (
            <Suspense
                fallback={<Skeleton className={`w-full ${chartHeight}`} />}
            >
                <ChartWidget
                    type={type}
                    visual={visual as CartesianVisual | DonutVisual}
                    result={result}
                    title={title}
                    heightClass={chartHeight}
                    onPointSelect={onPointSelect}
                />
            </Suspense>
        );
    }

    return null;
}
