/**
 * Klien JSON Shell ke API Core di bawah `/api/v1`, memakai sesi yang sama dengan halaman
 * Inertia. Permintaan yang mengubah data membawa token CSRF dari cookie `XSRF-TOKEN`,
 * seperti yang dilakukan axios. Kegagalan menjadi `CoreApiError` dengan pesan pertama
 * dari validasi Laravel supaya toast dapat menampilkannya apa adanya.
 */

export class CoreApiError extends Error {
    constructor(
        message: string,
        public readonly status: number,
        public readonly errors: Record<string, string[]> = {},
    ) {
        super(message);
        this.name = 'CoreApiError';
    }
}

function csrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
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
        const body = (await response.json().catch(() => null)) as {
            message?: unknown;
            errors?: Record<string, string[]>;
        } | null;
        const firstError = body?.errors
            ? Object.values(body.errors)[0]?.[0]
            : undefined;

        throw new CoreApiError(
            typeof firstError === 'string'
                ? firstError
                : typeof body?.message === 'string' && body.message
                  ? body.message
                  : 'Permintaan belum berhasil.',
            response.status,
            body?.errors ?? {},
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
