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
    compare?: QueryCompare;
    formulas?: QueryFormula[];
    percent_of_total?: string[];
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
    /** Kolom turunan (area 13): kunci measure atau rumus asalnya. */
    derived_from?: string;
    derivation?: ResultDerivation;
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

/*
 * Area 6: katalog dataset, dasbor, widget, dan query tersimpan untuk layar, sama dengan jawaban
 * `api/v1/analytics/datasets`, `.../dashboards`, `.../widgets`, `.../saved-queries` dan prop halaman
 * `/analytics` (`docs/todo/analitik/dasbor-dan-visual.md`, bagian *API untuk layar*).
 */

/** Satu dataset yang boleh dibaca pengguna, untuk pemilih data. */
export type DatasetSummary = {
    code: string;
    caption: string;
    description: string | null;
    module_id: string;
    version: number;
};

export type DatasetField = {
    key: string;
    caption: string;
    type:
        | 'text'
        | 'number'
        | 'date'
        | 'datetime'
        | 'boolean'
        | 'option'
        | 'reference';
    /** Field tanggal yang dapat dikelompokkan per hari, minggu, bulan, kuartal, atau tahun. */
    time: boolean;
    options?: Array<{ value: string; label: string }>;
    /** Kode dimensi bersama, misalnya `core.operating-unit`. */
    shared_dimension?: string;
    lookup?: string;
};

export type DatasetMeasure = {
    key: string;
    caption: string;
    aggregate: 'count' | 'count_distinct' | 'sum' | 'avg' | 'min' | 'max';
    format: MeasureFormat;
    currency_key?: string;
    unit_key?: string;
};

/** Isi dataset yang boleh dipakai pengguna ini; field dan nilai data pribadi sudah disaring server. */
export type DatasetDescription = DatasetSummary & {
    fields: DatasetField[];
    measures: DatasetMeasure[];
    times: string[];
    default_time: string | null;
};

export type WidgetType =
    'kpi' | 'bar' | 'column' | 'line' | 'area' | 'donut' | 'table' | 'text';

/** Gaya rentang ambang tile, mengikuti Cue Setup Business Central. */
export type ThresholdStyle =
    'favorable' | 'unfavorable' | 'ambiguous' | 'subordinate' | 'none';

export type KpiVisual = {
    measure: string;
    thresholds?: {
        threshold1: number;
        threshold2: number;
        low: ThresholdStyle;
        middle: ThresholdStyle;
        high: ThresholdStyle;
    };
    compact?: boolean;
};

/** Grafik batang, kolom, garis, dan area. */
export type CartesianVisual = {
    x: string;
    series?: string;
    y: string[];
    stacked?: 'none' | 'stacked' | 'percent';
    show_values?: boolean;
};

export type DonutVisual = {
    category: string;
    value: string;
    max_slices?: number;
};

export type TableVisual = { columns: string[]; show_totals?: boolean };

/** Teks biasa dengan baris baru; bukan Markdown atau HTML. */
export type TextVisual = { text: string };

export type WidgetVisual =
    KpiVisual | CartesianVisual | DonutVisual | TableVisual | TextVisual;

/**
 * Keadaan definisi widget tanpa menghitungnya: `field_removed` bila dataset tidak lagi punya kolom yang
 * disebut query (namanya di `missing_fields`), `dataset_unavailable` bila datasetnya tidak terdaftar atau
 * module-nya tidak terpasang.
 */
export type WidgetStatus = 'ok' | 'field_removed' | 'dataset_unavailable';

export type DashboardWidget = {
    id: string;
    dashboard_id: string;
    title: string;
    type: WidgetType;
    dataset_code: string | null;
    /** Kosong untuk widget teks. Kunci yang diganti nama dataset sudah dipetakan server. */
    query: AnalyticsQuery | null;
    visual: WidgetVisual;
    cache_ttl_seconds: number | null;
    status: WidgetStatus;
    missing_fields: string[];
    version: number;
};

/** Letak satu widget di grid 12 kolom: lebar 3, 4, 6, 8, atau 12; tinggi 1–3 baris. */
export type DashboardLayoutItem = {
    widget_id: string;
    x: number;
    y: number;
    w: number;
    h: number;
};

export type DashboardSummary = {
    id: string;
    name: string;
    description: string | null;
    shared: boolean;
    mine: boolean;
    owner_name: string | null;
    can_edit: boolean;
    widget_count: number;
    updated_at: string | null;
    version: number;
};

/** Dasbor beserta letak efektif setiap widget-nya, tanpa data widget. */
export type DashboardDetail = DashboardSummary & {
    layout: DashboardLayoutItem[];
    widgets: DashboardWidget[];
};

export type SavedQuery = {
    id: string;
    code: string;
    name: string;
    description: string | null;
    shared: boolean;
    mine: boolean;
    owner_name: string | null;
    can_edit: boolean;
    dataset_code: string;
    query: AnalyticsQuery;
    status: WidgetStatus;
    missing_fields: string[];
    updated_at: string | null;
    version: number;
};

/** Hak membuat dasbor pribadi dan mengelola dasbor bersama, untuk tombol di layar. */
export type DashboardAbilities = { create: boolean; share: boolean };

/*
 * Area 13: rumus, perbandingan periode, dan persen terhadap total (`docs/todo/analitik/mesin-query.md`, bagian
 * *Bahasa rumus* dan *Perbandingan periode*).
 */

/** Periode pembanding: rentang yang sama digeser sepanjang dirinya, atau satu tahun. */
export type QueryCompare = 'previous_period' | 'previous_year';

/** Satu rumus; kuncinya dipilih lewat `measures`. Teksnya disimpan apa adanya. */
export type QueryFormula = {
    key: string;
    expression: string;
    caption?: string;
    format?: MeasureFormat;
};

/**
 * Jenis kolom turunan: nilai periode pembanding (`<kunci>__previous`), selisih (`__change`), persen perubahan
 * (`__change_pct`, kosong bila pembandingnya nol), dan persen terhadap total (`__percent_of_total`).
 */
export type ResultDerivation =
    'previous' | 'change' | 'change_pct' | 'percent_of_total';
