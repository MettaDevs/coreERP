/**
 * Jalur balik ke Web Shell. App tidak mencetak sendiri: ia meminta Shell membuka dialog
 * cetak milik Core (`coreerp.print`), dan Core yang memilih layout, mengantrekan ekspor,
 * serta memberi tahu hasilnya lewat tray dan lonceng di header. Yang diketahui app hanya
 * kode laporan dan parameternya.
 *
 * Shell memeriksa sumber, origin, dan `appId` sebelum menerimanya, sama seperti pada
 * `coreerp.ready`. Origin induk dicatat sekali saat konteks pertama diterima.
 */

export const APP_ID = 'management-aset';

let parentOrigin = '';

export function setParentOrigin(origin: string): void {
    parentOrigin = origin;
}

/** App dibuka di dalam Shell, jadi tombol cetak boleh ditampilkan. */
export function shellTersedia(): boolean {
    return parentOrigin !== '' && window.parent !== window;
}

export type PrintRequest = {
    /** Kode laporan pada `app.yaml`, tanpa awalan ID app. */
    report: string;
    /** Judul dialog untuk pengguna, misalnya "Cetak work order PMHA-000012". */
    title: string;
    parameters: Record<string, unknown>;
};

export function requestPrint(request: PrintRequest): void {
    if (!shellTersedia()) return;
    window.parent.postMessage(
        { type: 'coreerp.print', appId: APP_ID, ...request },
        parentOrigin,
    );
}
