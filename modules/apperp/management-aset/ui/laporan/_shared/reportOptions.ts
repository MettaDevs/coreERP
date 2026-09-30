import { coreApi } from '../../api';
import { APP_ID } from '../../print';

/**
 * Opsi terakhir dan preset laporan milik pengguna (K-24, K-25), disimpan Core bersama mesin laporannya.
 *
 * Kode laporan di sini tanpa awalan module; katalog Core menyimpannya sebagai `management-aset.<kode>`.
 */

/** Nilai filter: satu nilai, atau daftar untuk filter pilihan banyak. */
export type FilterValue = string | string[];

export type Filters = Record<string, FilterValue>;

/**
 * Filter tambahan pengguna (K-30) di dalam `Filters` berkunci datar `filters.<data item>.<kolom>`, supaya
 * setiap filter tetap satu nilai di state halaman. Ke luar halaman — pratinjau, cetak, preset, dan opsi
 * terakhir — bentuknya bersarang `filters[<data item>][<kolom>]`, lewat `toParameters()`.
 */
const ADDITIONAL = 'filters';

export const additionalKey = (item: string, column: string) =>
    `${ADDITIONAL}.${item}.${column}`;

/** Parameter laporan seperti dikirim ke server: filter biasa, ditambah filter tambahan bersarang. */
export type ReportParameters = Record<
    string,
    FilterValue | Record<string, Record<string, FilterValue>>
>;

/** Kolom tabel data item yang boleh difilter, dari katalog field tabel di module. */
export type DataItemField = {
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
    options?: { value: string; label: string }[];
    /** Resource pemilih untuk kolom rujukan, sama dengan filter master. */
    lookup?: string;
};

/** Data item laporan, padanan `dataitem` BC: bagian filter tambahan dengan kolom bawaannya. */
export type ReportDataItem = {
    key: string;
    caption: string;
    default_fields: string[];
    fields: DataItemField[];
};

export type ReportPreset = {
    id: string;
    name: string;
    shared: boolean;
    mine: boolean;
    owner_name: string | null;
    /** Yang tersimpan; tanggal relatif tetap berupa token seperti `@this_month.start`. */
    parameters: ReportParameters;
    /** Tanggal relatif sudah diterjemahkan menurut zona pengguna, siap dipasang ke filter. */
    resolved_parameters: ReportParameters;
    version: number;
};

export type ReportOptions = {
    last_used: { parameters: ReportParameters } | null;
    presets: ReportPreset[];
    /** Boleh membuat, mengubah, dan mengarsipkan preset bersama. */
    can_share: boolean;
};

const path = (report: string) =>
    `/reports/${encodeURIComponent(`${APP_ID}.${report}`)}`;

export const getReportOptions = (report: string) =>
    coreApi<{ data: ReportOptions }>(`${path(report)}/options`).then(
        (response) => response.data,
    );

export const rememberLastUsed = (
    report: string,
    parameters: ReportParameters,
) =>
    coreApi<void>(`${path(report)}/options/last-used`, {
        method: 'PUT',
        body: JSON.stringify({ parameters }),
    });

export const createPreset = (
    report: string,
    name: string,
    parameters: ReportParameters,
    shared = false,
) =>
    coreApi<{ data: ReportPreset }>(`${path(report)}/presets`, {
        method: 'POST',
        body: JSON.stringify({ name, parameters, shared }),
    }).then((response) => response.data);

export const archivePreset = (report: string, preset: ReportPreset) =>
    coreApi<void>(`${path(report)}/presets/${preset.id}`, {
        method: 'DELETE',
        headers: { 'If-Match': `W/"${preset.version}"` },
    });

/** Data item laporan dan kolom yang boleh difilter (K-30); kosong bila laporannya belum menawarkannya. */
export const getReportDataItems = (report: string) =>
    coreApi<{ meta: { data_items?: ReportDataItem[] } }>(
        `${path(report)}/fields`,
    ).then((response) => response.meta.data_items ?? []);

/** Nilai filter yang terisi: teks yang tidak kosong, atau daftar yang berisi. */
export const filled = (value: FilterValue | undefined): boolean =>
    Array.isArray(value) ? value.length > 0 : Boolean(value);

/** Hanya filter yang terisi, untuk disimpan, dicetak, atau dikirim ke pratinjau. */
export function cleanFilters(filters: Filters): Filters {
    return Object.fromEntries(
        Object.entries(filters).filter(([, value]) => filled(value)),
    );
}

/** Filter halaman menjadi parameter laporan: yang terisi saja, dengan filter tambahan bersarang. */
export function toParameters(filters: Filters): ReportParameters {
    const parameters: ReportParameters = {};
    const additional: Record<string, Record<string, FilterValue>> = {};

    for (const [key, value] of Object.entries(cleanFilters(filters))) {
        if (key.startsWith(`${ADDITIONAL}.`)) {
            const [item, column] = key.slice(ADDITIONAL.length + 1).split('.');

            additional[item] = { ...additional[item], [column]: value };
        } else {
            parameters[key] = value;
        }
    }

    if (Object.keys(additional).length > 0) {
        parameters[ADDITIONAL] = additional;
    }

    return parameters;
}

/** Kebalikan `toParameters()`: parameter tersimpan (opsi terakhir, preset) menjadi filter halaman. */
export function fromParameters(parameters: ReportParameters): Filters {
    const filters: Filters = {};

    for (const [key, value] of Object.entries(parameters)) {
        if (
            key === ADDITIONAL &&
            value &&
            !Array.isArray(value) &&
            typeof value === 'object'
        ) {
            for (const [item, columns] of Object.entries(value)) {
                for (const [column, entry] of Object.entries(columns)) {
                    filters[additionalKey(item, column)] = entry;
                }
            }
        } else if (typeof value === 'string' || Array.isArray(value)) {
            filters[key] = value;
        }
    }

    return filters;
}
