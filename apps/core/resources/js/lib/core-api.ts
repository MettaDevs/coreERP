import { toast } from 'sonner';

/**
 * Klien JSON Shell ke API Core di bawah `/api/v1`, memakai sesi yang sama dengan halaman
 * Inertia. Permintaan yang mengubah data membawa token CSRF dari cookie `XSRF-TOKEN`,
 * seperti yang dilakukan axios. Kegagalan menjadi `CoreApiError` dengan pesan pertama
 * dari validasi Laravel, atau pesan `{ error: { code, message } }`, supaya toast dapat
 * menampilkannya apa adanya.
 *
 * Endpoint yang mengubah record meminta versi yang dibuka pengguna (field `version` atau
 * header `If-Match`). Versi basi dijawab 409 berkode `stale_version`.
 */

export class CoreApiError extends Error {
    constructor(
        message: string,
        public readonly status: number,
        public readonly errors: Record<string, string[]> = {},
        public readonly code: string | null = null,
        /** Bagian permintaan yang salah menurut `{ error: { field } }`, misalnya `filters.nama`. */
        public readonly field: string | null = null,
    ) {
        super(message);
        this.name = 'CoreApiError';
    }
}

function csrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

type ErrorBody = {
    message?: unknown;
    errors?: Record<string, string[]>;
    error?: { code?: unknown; message?: unknown; field?: unknown };
};

function responseError(status: number, body: ErrorBody | null): CoreApiError {
    const firstError = body?.errors
        ? Object.values(body.errors)[0]?.[0]
        : undefined;

    return new CoreApiError(
        typeof firstError === 'string'
            ? firstError
            : typeof body?.error?.message === 'string'
              ? body.error.message
              : typeof body?.message === 'string' && body.message
                ? body.message
                : 'Permintaan belum berhasil.',
        status,
        body?.errors ?? {},
        typeof body?.error?.code === 'string' ? body.error.code : null,
        typeof body?.error?.field === 'string' ? body.error.field : null,
    );
}

/** XHR menyediakan progres transfer berkas; sesi, CSRF, dan pesan error sama dengan apiRequest. */
export function apiUpload<T>(
    path: string,
    file: File,
    onProgress: (fraction: number) => void,
    signal: AbortSignal,
): Promise<T> {
    return new Promise((resolve, reject) => {
        const request = new XMLHttpRequest();
        const abort = () => request.abort();
        request.open('POST', path);
        request.setRequestHeader('Accept', 'application/json');
        request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        request.setRequestHeader('X-XSRF-TOKEN', csrfToken());
        request.upload.onprogress = (event) => {
            if (event.lengthComputable) {
                onProgress(event.loaded / event.total);
            }
        };
        request.onload = () => {
            let body;

            try {
                body = JSON.parse(request.responseText);
            } catch {
                body = null;
            }

            if (request.status < 200 || request.status >= 300) {
                reject(responseError(request.status, body));
            } else if (body === null) {
                reject(
                    new CoreApiError(
                        'Jawaban upload belum dapat dibaca. Muat ulang daftar berkas.',
                        502,
                    ),
                );
            } else {
                resolve(body as T);
            }
        };
        request.onerror = () =>
            reject(
                new Error(
                    'Koneksi terputus. Muat ulang daftar berkas sebelum mencoba lagi.',
                ),
            );
        request.onabort = () =>
            reject(new DOMException('Upload dihentikan.', 'AbortError'));
        request.onloadend = () => signal.removeEventListener('abort', abort);

        if (signal.aborted) {
            reject(new DOMException('Upload dihentikan.', 'AbortError'));

            return;
        }

        signal.addEventListener('abort', abort, { once: true });
        const body = new FormData();
        body.append('file', file);
        request.send(body);
    });
}

export async function apiRequest(
    path: string,
    init: RequestInit = {},
): Promise<Response> {
    const method = (init.method ?? 'GET').toUpperCase();
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(init.headers as Record<string, string> | undefined),
    };

    if (method !== 'GET') {
        headers['X-XSRF-TOKEN'] = csrfToken();
    }

    if (init.body && !(init.body instanceof FormData)) {
        headers['Content-Type'] = 'application/json';
    }

    const response = await fetch(path, {
        ...init,
        method,
        headers,
        credentials: 'same-origin',
    });

    if (!response.ok) {
        throw responseError(
            response.status,
            (await response.json().catch(() => null)) as ErrorBody | null,
        );
    }

    return response;
}

export const apiJson = async <T>(
    path: string,
    init?: RequestInit,
): Promise<T> => (await apiRequest(path, init)).json() as Promise<T>;

export function errorText(caught: unknown, fallback: string): string {
    return caught instanceof Error && caught.message
        ? caught.message
        : fallback;
}

/**
 * Toast untuk kegagalan menyimpan. Versi basi mendapat tombol muat ulang penuh: isian form
 * harus kembali ke data terbaru supaya pengguna melihat perubahan orang lain lebih dulu.
 */
export function toastSaveError(caught: unknown, fallback: string): void {
    if (caught instanceof CoreApiError && caught.code === 'stale_version') {
        toast.error(caught.message, {
            duration: Infinity,
            action: {
                label: 'Muat ulang',
                onClick: () => window.location.reload(),
            },
        });

        return;
    }

    toast.error(errorText(caught, fallback));
}
