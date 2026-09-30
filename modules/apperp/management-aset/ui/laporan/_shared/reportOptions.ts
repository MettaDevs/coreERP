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

export type ReportPreset = {
    id: string;
    name: string;
    shared: boolean;
    mine: boolean;
    owner_name: string | null;
    /** Yang tersimpan; tanggal relatif tetap berupa token seperti `@this_month.start`. */
    parameters: Filters;
    /** Tanggal relatif sudah diterjemahkan menurut zona pengguna, siap dipasang ke filter. */
    resolved_parameters: Filters;
    version: number;
};

export type ReportOptions = {
    last_used: { parameters: Filters } | null;
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

export const rememberLastUsed = (report: string, parameters: Filters) =>
    coreApi<void>(`${path(report)}/options/last-used`, {
        method: 'PUT',
        body: JSON.stringify({ parameters }),
    });

export const createPreset = (
    report: string,
    name: string,
    parameters: Filters,
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

/** Nilai filter yang terisi: teks yang tidak kosong, atau daftar yang berisi. */
export const filled = (value: FilterValue | undefined): boolean =>
    Array.isArray(value) ? value.length > 0 : Boolean(value);

/** Hanya filter yang terisi, untuk disimpan, dicetak, atau dikirim ke pratinjau. */
export function cleanFilters(filters: Filters): Filters {
    return Object.fromEntries(
        Object.entries(filters).filter(([, value]) => filled(value)),
    );
}
