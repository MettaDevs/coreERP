import { APP_ID } from './print';

/**
 * Permintaan ekspor daftar di layar ke shell (K-27), bentuk yang sama dengan permintaan cetak di
 * `print.ts`: sebuah `CustomEvent` pada `window`. Shell yang mengantrekannya ke Core dan menampilkan
 * hasilnya di tray Ekspor; Core meminta barisnya kembali ke module ini lewat daftar yang didaftarkan di
 * `src/Reporting/Lists`, dengan hak dan cakupan organisasi yang sama dengan layarnya.
 *
 * Yang dikirim hanya apa yang tampil: kolom beserta judulnya, urutan, dan filter. Semua baris yang cocok
 * ikut diekspor, bukan hanya yang sudah dimuat layar.
 */

export const EVENT_LIST_EXPORT = 'coreerp:list-export';

export type ListExportRequest = {
    /** Kode daftar pada `src/Reporting/Lists`, misalnya `aset`. */
    list: string;
    columns: { key: string; header: string }[];
    sort: { column: string; direction: 'asc' | 'desc' } | null;
    filters: Record<string, unknown>;
};

export function requestListExport(request: ListExportRequest): void {
    window.dispatchEvent(
        new CustomEvent(EVENT_LIST_EXPORT, {
            detail: { appId: APP_ID, ...request },
        }),
    );
}
