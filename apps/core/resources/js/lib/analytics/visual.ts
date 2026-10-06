import {
    dimensionField,
    isRelativeRange,
    RELATIVE_RANGES,
    TIME_GRANULARITIES,
} from '@/lib/analytics/query';
import type {
    AnalyticsQuery,
    DatasetDescription,
    WidgetType,
    WidgetVisual,
} from '@/lib/analytics/types';

/**
 * Jenis tampilan untuk hasil sebuah query di pembangun bagian dasbor dan penjelajah (area 8): mana yang cocok
 * dengan pilihan pengguna, alasannya bila tidak, dan tampilan bawaannya. Aturannya sama dengan pemeriksaan
 * server saat bagian disimpan (`Dashboards\WidgetDefinition`); server tetap yang memutuskan.
 */

/** Jenis yang menampilkan hasil query; bagian teks tidak memakai data. */
export type DataWidgetType = Exclude<WidgetType, 'text' | 'blend'>;

export const VISUAL_TYPES: ReadonlyArray<{
    type: DataWidgetType;
    caption: string;
}> = [
    { type: 'table', caption: 'Tabel' },
    { type: 'kpi', caption: 'Angka' },
    { type: 'column', caption: 'Batang tegak' },
    { type: 'bar', caption: 'Batang mendatar' },
    { type: 'line', caption: 'Garis' },
    { type: 'area', caption: 'Area' },
    { type: 'donut', caption: 'Donat' },
];

/** Nilai terbanyak yang digambar satu grafik, sama dengan batas server. */
const MAX_CHART_MEASURES = 4;

export function isDataWidgetType(value: unknown): value is DataWidgetType {
    return VISUAL_TYPES.some((item) => item.type === value);
}

/**
 * Alasan jenis tampilan ini tidak cocok dengan query, dalam kalimat untuk pengguna, atau `null` bila cocok.
 * `times` adalah kolom tanggal datanya.
 */
export function visualUnavailableReason(
    type: DataWidgetType,
    query: AnalyticsQuery,
    times: string[],
): string | null {
    const dimensions = (query.dimensions ?? []).map(dimensionField);
    const measures = query.measures.length;

    if (measures === 0) {
        return 'Pilih sedikitnya satu nilai lebih dulu.';
    }

    switch (type) {
        case 'table':
            return null;
        case 'kpi':
            if (dimensions.length > 0) {
                return 'Angka tidak memakai pengelompokan.';
            }

            return measures === 1
                ? null
                : 'Angka menampilkan tepat satu nilai.';
        case 'donut':
            if (dimensions.length !== 1) {
                return 'Grafik donat butuh tepat satu pengelompokan.';
            }

            return measures === 1
                ? null
                : 'Grafik donat menggambar tepat satu nilai.';
        case 'line':
        case 'area':
            if (!dimensions.some((field) => times.includes(field))) {
                return `Grafik ${type === 'line' ? 'garis' : 'area'} butuh pengelompokan menurut tanggal.`;
            }

            return chartLimits(dimensions.length, measures);
        default:
            if (dimensions.length === 0) {
                return 'Grafik batang butuh sedikitnya satu pengelompokan.';
            }

            return chartLimits(dimensions.length, measures);
    }
}

function chartLimits(dimensions: number, measures: number): string | null {
    if (dimensions > 2) {
        return 'Grafik memakai paling banyak dua pengelompokan.';
    }

    return measures > MAX_CHART_MEASURES
        ? `Grafik menggambar paling banyak ${MAX_CHART_MEASURES} nilai.`
        : null;
}

/** Jenis yang paling cocok bila pengguna belum memilih, atau bila pilihannya tidak cocok lagi. */
export function suggestedVisual(
    query: AnalyticsQuery,
    times: string[],
): DataWidgetType {
    for (const type of ['kpi', 'line', 'column'] as const) {
        if (visualUnavailableReason(type, query, times) === null) {
            return type;
        }
    }

    return 'table';
}

/** Pilihan pengguna bila cocok, selain itu jenis yang disarankan. */
export function effectiveVisual(
    chosen: unknown,
    query: AnalyticsQuery,
    times: string[],
): DataWidgetType {
    return isDataWidgetType(chosen) &&
        visualUnavailableReason(chosen, query, times) === null
        ? chosen
        : suggestedVisual(query, times);
}

/**
 * Tampilan bawaan sebuah jenis untuk query ini: grafik garis dan area memakai pengelompokan tanggal sebagai sumbu
 * mendatar, pengelompokan lain menjadi seri; tabel memuat semua kolom dengan baris total. `keep` adalah tampilan
 * tersimpan dengan jenis yang sama, yang pilihan tambahannya (ambang, susunan, label nilai) dipertahankan.
 */
export function defaultVisual(
    type: DataWidgetType,
    query: AnalyticsQuery,
    times: string[],
    keep?: WidgetVisual,
): WidgetVisual {
    const dimensions = (query.dimensions ?? []).map(dimensionField);
    const measure = query.measures[0];

    switch (type) {
        case 'kpi':
            return { ...keep, measure };
        case 'donut':
            return { ...keep, category: dimensions[0], value: measure };
        case 'table':
            return {
                ...keep,
                columns: [...dimensions, ...query.measures],
                show_totals: true,
            };
        default: {
            const x =
                type === 'line' || type === 'area'
                    ? (dimensions.find((field) => times.includes(field)) ??
                      dimensions[0])
                    : dimensions[0];
            const series = dimensions.find((field) => field !== x);
            const visual = { ...keep, x, y: query.measures } as Record<
                string,
                unknown
            >;
            delete visual.series;

            return (
                series === undefined ? visual : { ...visual, series }
            ) as WidgetVisual;
        }
    }
}

/**
 * Judul yang disarankan dari isi query, misalnya "Nilai perolehan per group aset, tahun ini". Nama kolom dan nilai
 * datang dari data, tidak ditulis di layar.
 */
export function suggestedTitle(
    query: AnalyticsQuery,
    dataset: DatasetDescription | null,
): string {
    if (dataset === null || query.measures.length === 0) {
        return '';
    }

    const caption = (key: string) =>
        dataset.measures.find((measure) => measure.key === key)?.caption ??
        query.formulas?.find((formula) => formula.key === key)?.caption ??
        dataset.fields.find((field) => field.key === key)?.caption ??
        key;
    const groups = (query.dimensions ?? []).map((item) => {
        const field = caption(dimensionField(item)).toLowerCase();
        const granularity =
            typeof item === 'string'
                ? undefined
                : TIME_GRANULARITIES.find(
                      (entry) => entry.value === item.granularity,
                  )?.caption.toLowerCase();

        return granularity === undefined ? field : `${field} (${granularity})`;
    });
    const range = query.time_range?.range;
    const period =
        range !== undefined && isRelativeRange(range)
            ? RELATIVE_RANGES.find(
                  (item) => item.token === range,
              )?.caption.toLowerCase()
            : range;

    return [
        query.measures.map(caption).join(', ') +
            (groups.length > 0 ? ` per ${groups.join(' dan ')}` : ''),
        period,
    ]
        .filter((part) => part !== undefined && part !== '')
        .join(', ')
        .slice(0, 120);
}
