/**
 * Bentuk query dan hasil engine analitik, sama dengan `POST /api/v1/analytics/query`
 * (`docs/todo/analitik/mesin-query.md`, bagian *Bentuk query* dan *Bentuk hasil*).
 *
 * Bentuk ini dibekukan area 0 dan dipakai bersama layar area 2, 6, 7, 8, 12, dan 13. Tipe baru
 * ditambahkan di akhir berkas; tipe yang ada hanya diperluas, tidak diubah.
 */

export type TimeGranularity = 'day' | 'week' | 'month' | 'quarter' | 'year';
export type MeasureFormat =
    'number' | 'money' | 'percent' | 'quantity' | 'hours';

export type AnalyticsQuery = {
    dataset: string;
    dimensions?: Array<
        string | { field: string; granularity?: TimeGranularity }
    >;
    measures: string[];
    filters?: Record<string, string | string[]>;
    time_range?: { field?: string; range: string };
    sort?: Array<{ key: string; direction: 'asc' | 'desc' }>;
    limit?: number;
    totals?: boolean;
    fill_gaps?: boolean;
};

export type ResultColumn = {
    key: string;
    kind: 'dimension' | 'measure';
    caption: string;
    type:
        | 'text'
        | 'number'
        | 'date'
        | 'datetime'
        | 'boolean'
        | 'option'
        | 'reference'
        | 'period';
    format?: MeasureFormat;
    granularity?: TimeGranularity;
    label_key?: string;
    currency_key?: string;
    unit_key?: string;
    implicit?: boolean;
};

export type ResultValue = string | number | boolean | null;

export type ResultSet = {
    columns: ResultColumn[];
    rows: Array<Record<string, ResultValue>>;
    totals: Array<Record<string, ResultValue>>;
    meta: {
        dataset: string;
        dataset_version: number;
        generated_at: string;
        timezone: string;
        truncated: boolean;
        row_limit: number;
        cached: boolean;
        duration_ms: number;
        query_hash: string;
    };
};

/** Satu pengelompok query: kunci field, atau field waktu beserta ukuran waktunya. */
export type QueryDimension = NonNullable<AnalyticsQuery['dimensions']>[number];

export type QuerySort = NonNullable<AnalyticsQuery['sort']>[number];

export type QueryTimeRange = NonNullable<AnalyticsQuery['time_range']>;
