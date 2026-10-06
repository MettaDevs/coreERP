import type {
    AnalyticsQuery,
    QueryDimension,
    ResultColumn,
    ResultSet,
    ResultValue,
    TimeGranularity,
} from '@/lib/analytics/types';

/**
 * Penyusun query dan pembaca hasil untuk layar analitik (`docs/todo/analitik/mesin-query.md`). Satu-satunya
 * tempat daftar token periode dan ukuran waktu ditulis di sisi layar; daftar token harus sama dengan
 * `RelativeRange::TOKENS` di server, dan satu test PHP menjaganya.
 */

type Row = Record<string, ResultValue>;

/** Ukuran ember waktu untuk pengelompokan menurut waktu. Minggu mulai Senin. */
export const TIME_GRANULARITIES = [
    { value: 'day', caption: 'Hari' },
    { value: 'week', caption: 'Minggu' },
    { value: 'month', caption: 'Bulan' },
    { value: 'quarter', caption: 'Kuartal' },
    { value: 'year', caption: 'Tahun' },
] as const satisfies ReadonlyArray<{
    value: TimeGranularity;
    caption: string;
}>;

/**
 * Periode relatif untuk `time_range.range`, menurut zona waktu pengguna. Server yang menerjemahkannya
 * menjadi tanggal, setiap kali query dijalankan; layar hanya menyimpan nama tokennya.
 */
export const RELATIVE_RANGES = [
    { token: '@today', caption: 'Hari ini' },
    { token: '@yesterday', caption: 'Kemarin' },
    { token: '@this_week', caption: 'Minggu ini' },
    { token: '@last_week', caption: 'Minggu lalu' },
    { token: '@this_month', caption: 'Bulan ini' },
    { token: '@last_month', caption: 'Bulan lalu' },
    { token: '@this_quarter', caption: 'Kuartal ini' },
    { token: '@last_quarter', caption: 'Kuartal lalu' },
    { token: '@this_year', caption: 'Tahun ini' },
    { token: '@last_year', caption: 'Tahun lalu' },
    { token: '@last_7_days', caption: '7 hari terakhir' },
    { token: '@last_30_days', caption: '30 hari terakhir' },
    { token: '@last_90_days', caption: '90 hari terakhir' },
    { token: '@last_12_months', caption: '12 bulan terakhir' },
    { token: '@year_to_date', caption: 'Awal tahun sampai hari ini' },
    { token: '@month_to_date', caption: 'Awal bulan sampai hari ini' },
] as const;

export type RelativeRangeToken = (typeof RELATIVE_RANGES)[number]['token'];

/** `time_range.range` yang berupa token periode; selain itu ia ekspresi tanggal sintaks Business Central. */
export function isRelativeRange(range: string): range is RelativeRangeToken {
    return RELATIVE_RANGES.some((item) => item.token === range);
}

/** Pengelompok kolom biasa, atau kolom waktu dengan ukuran waktunya. */
export function dimension(
    field: string,
    granularity?: TimeGranularity,
): QueryDimension {
    return granularity === undefined ? field : { field, granularity };
}

/** Kunci field sebuah pengelompok, apa pun bentuknya. */
export function dimensionField(item: QueryDimension): string {
    return typeof item === 'string' ? item : item.field;
}

/**
 * Query siap kirim dari bagian-bagian yang disusun layar: bagian kosong dibuang dan kuncinya selalu
 * berurutan sama, sehingga query yang sama menghasilkan teks JSON yang sama (kunci efek dan cache
 * di layar). Server tetap menyatukan bentuknya sendiri; ini bukan pengganti validasinya.
 */
export function buildQuery(parts: AnalyticsQuery): AnalyticsQuery {
    const filters: Record<string, string | string[]> = {};

    for (const [key, value] of Object.entries(parts.filters ?? {})) {
        const filled = Array.isArray(value)
            ? value.filter((item) => item.trim() !== '')
            : value.trim();

        if (filled.length > 0) {
            filters[key] = filled;
        }
    }

    return {
        dataset: parts.dataset,
        ...(parts.dimensions?.length ? { dimensions: parts.dimensions } : {}),
        measures: parts.measures,
        ...(Object.keys(filters).length > 0 ? { filters } : {}),
        ...(parts.time_range ? { time_range: parts.time_range } : {}),
        ...(parts.sort?.length ? { sort: parts.sort } : {}),
        ...(parts.limit === undefined ? {} : { limit: parts.limit }),
        ...(parts.totals ? { totals: true } : {}),
        ...(parts.fill_gaps === undefined
            ? {}
            : { fill_gaps: parts.fill_gaps }),
    };
}

/** Kolom pengelompok yang dipilih pengguna, tanpa mata uang dan satuan yang ditambahkan server. */
export function dimensionColumns(
    result: Pick<ResultSet, 'columns'>,
): ResultColumn[] {
    return result.columns.filter(
        (column) => column.kind === 'dimension' && !column.implicit,
    );
}

/** Mata uang dan satuan yang ikut dikelompokkan server supaya uang dan kuantitas tidak tercampur. */
export function implicitColumns(
    result: Pick<ResultSet, 'columns'>,
): ResultColumn[] {
    return result.columns.filter((column) => column.implicit === true);
}

export function measureColumns(
    result: Pick<ResultSet, 'columns'>,
): ResultColumn[] {
    return result.columns.filter((column) => column.kind === 'measure');
}

/**
 * Baris dikelompokkan menurut nilai kolom tersirat (mata uang, satuan), urutan kemunculannya
 * dipertahankan. Satu kelompok digambar atau ditulis sendiri: uang lintas mata uang tidak pernah
 * menjadi satu seri.
 */
export function groupRowsByImplicit(
    rows: Row[],
    implicit: ResultColumn[],
): Array<{ label: string; rows: Row[] }> {
    const groups = new Map<string, Row[]>();

    for (const row of rows) {
        const label = implicit
            .map((column) => String(row[column.key] ?? ''))
            .join(' · ');

        groups.set(label, [...(groups.get(label) ?? []), row]);
    }

    return [...groups.entries()].map(([label, grouped]) => ({
        label,
        rows: grouped,
    }));
}

/*
 * Area 8: query penjelajah di query string (`/analytics/explore?q=…&view=…`), supaya analisis dapat dibagikan
 * sebagai tautan. Layar membacanya dari URL setiap render dan menulisnya kembali lewat kunjungan sisi peramban;
 * query tidak pernah disalin ke state.
 */

export const EXPLORE_PATH = '/analytics/explore';

/** Query kosong untuk satu data: belum ada nilai, pengelompokan, saringan, atau periode. */
export function emptyQuery(dataset = ''): AnalyticsQuery {
    return { dataset, measures: [] };
}

/** Alamat penjelajah untuk query dan jenis tampilan ini; query tanpa data tidak ditulis. */
export function exploreUrl(
    query: AnalyticsQuery | null,
    view: string | null,
): string {
    const params = new URLSearchParams();

    if (query !== null && query.dataset !== '') {
        params.set('q', JSON.stringify(buildQuery(query)));
    }

    if (view !== null) {
        params.set('view', view);
    }

    const search = params.toString();

    return search === '' ? EXPLORE_PATH : `${EXPLORE_PATH}?${search}`;
}

export type ExploreUrlState = {
    query: AnalyticsQuery | null;
    view: string | null;
    /** `q` ada tetapi bukan query yang dapat dibaca, misalnya tautan yang terpotong. */
    unreadable: boolean;
};

/**
 * Query dan jenis tampilan dari alamat halaman (`usePage().url`, path beserta query string). Hanya bentuknya
 * yang diperiksa di sini; isinya tetap divalidasi server saat dijalankan.
 */
export function readExploreUrl(url: string): ExploreUrlState {
    const params = new URL(url, 'http://localhost').searchParams;
    const view = params.get('view');
    const raw = params.get('q');

    if (raw === null) {
        return { query: null, view, unreadable: false };
    }

    try {
        const parsed: unknown = JSON.parse(raw);

        if (isQueryShape(parsed)) {
            return { query: parsed, view, unreadable: false };
        }
    } catch {
        // Ditangani di bawah: tautan yang tidak terbaca dibuka sebagai analisis kosong.
    }

    return { query: null, view, unreadable: true };
}

function isQueryShape(value: unknown): value is AnalyticsQuery {
    if (typeof value !== 'object' || value === null || Array.isArray(value)) {
        return false;
    }

    const query = value as Record<string, unknown>;
    const list = (item: unknown) => item === undefined || Array.isArray(item);
    const record = (item: unknown) =>
        item === undefined ||
        (typeof item === 'object' && item !== null && !Array.isArray(item));

    return (
        typeof query.dataset === 'string' &&
        Array.isArray(query.measures) &&
        query.measures.every((item) => typeof item === 'string') &&
        list(query.dimensions) &&
        list(query.sort) &&
        record(query.filters) &&
        record(query.time_range)
    );
}
