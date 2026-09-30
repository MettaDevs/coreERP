/**
 * Permintaan ekspor daftar dari layar module (K-27), dikirim sebagai `CustomEvent('coreerp:list-export')`
 * pada `window`, seperti permintaan cetak. Shell memeriksa bentuknya, lalu memintanya ke antrean ekspor
 * Core; hasilnya muncul di tray Ekspor seperti ekspor laporan.
 *
 * Module hanya menyebut daftar mana, kolom yang tampil beserta judulnya, urutan, dan filternya. Hak
 * melihat daftar dan kebijakan data organisasinya diperiksa server, bukan di sini.
 */

export type ListExportMessage = {
    appId: string;
    list: string;
    columns: { key: string; header: string }[];
    sort: { column: string; direction: 'asc' | 'desc' } | null;
    filters: Record<string, unknown>;
};

const CODE = /^[a-z0-9-]{1,80}$/;

/** Validasi bentuk pesan dari layar module. Id module diperiksa pemanggil terhadap module yang sedang terbuka. */
export function isListExportMessage(data: unknown): data is ListExportMessage {
    if (typeof data !== 'object' || data === null) {
        return false;
    }

    const message = data as Record<string, unknown>;
    const columns = message.columns;
    const sort = message.sort;

    return (
        typeof message.appId === 'string' &&
        typeof message.list === 'string' &&
        CODE.test(message.list) &&
        Array.isArray(columns) &&
        columns.length > 0 &&
        columns.length <= 100 &&
        columns.every(
            (column) =>
                typeof column === 'object' &&
                column !== null &&
                typeof (column as Record<string, unknown>).key === 'string' &&
                typeof (column as Record<string, unknown>).header === 'string',
        ) &&
        (sort === null ||
            (typeof sort === 'object' &&
                typeof (sort as Record<string, unknown>).column === 'string' &&
                ['asc', 'desc'].includes(
                    String((sort as Record<string, unknown>).direction),
                ))) &&
        typeof message.filters === 'object' &&
        message.filters !== null &&
        !Array.isArray(message.filters)
    );
}
