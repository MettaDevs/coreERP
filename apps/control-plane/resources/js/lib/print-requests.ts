/**
 * Permintaan cetak dari app yang dibuka di iframe. Halaman host menerimanya lewat
 * `postMessage` (`coreerp.print`), memvalidasi asalnya, lalu menaruhnya di sini; dialog
 * cetak milik Shell yang terpasang di header mengambilnya. Dengan begitu app tidak perlu
 * tahu apa pun tentang layout, antrean, atau API Core.
 */

export type PrintRequest = {
    appId: string;
    appName: string;
    /** Kode laporan pada katalog Core: `<app-id>.<kode>`. */
    reportCode: string;
    title: string;
    parameters: Record<string, unknown>;
};

type Listener = (request: PrintRequest) => void;

const listeners = new Set<Listener>();

export function requestPrint(request: PrintRequest): void {
    listeners.forEach((listener) => listener(request));
}

export function subscribePrintRequests(listener: Listener): () => void {
    listeners.add(listener);

    return () => {
        listeners.delete(listener);
    };
}

/** Validasi bentuk pesan dari iframe app. Sumber dan origin diperiksa pemanggil. */
export function isPrintMessage(data: unknown): data is {
    type: 'coreerp.print';
    appId: string;
    report: string;
    title: string;
    parameters: Record<string, unknown>;
} {
    if (typeof data !== 'object' || data === null) {
        return false;
    }

    const message = data as Record<string, unknown>;

    return (
        message.type === 'coreerp.print' &&
        typeof message.appId === 'string' &&
        typeof message.report === 'string' &&
        /^[a-z0-9-]{1,80}$/.test(message.report) &&
        typeof message.title === 'string' &&
        message.title.length > 0 &&
        message.title.length <= 200 &&
        typeof message.parameters === 'object' &&
        message.parameters !== null &&
        !Array.isArray(message.parameters)
    );
}
