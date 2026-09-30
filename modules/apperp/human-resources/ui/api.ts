/**
 * Pemanggil rute JSON module ini dari layar.
 *
 * Bentuknya sama dengan `api.ts` module aset, tetapi ditulis sendiri: module tidak mengimpor berkas
 * module lain, supaya tetap bisa dicabut ke repo lain tanpa satu pun impor yang putus.
 */

const API_PREFIX = '/api/modules/human-resources/v1';

/** Metode yang dilewati pemeriksa CSRF Laravel, jadi tidak perlu membawa tokennya. */
const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

export class ApiError extends Error {
    constructor(
        message: string,
        public readonly fieldErrors: Record<string, string>,
        public readonly status: number,
        public readonly code: string | null,
    ) {
        super(message);
        this.name = 'ApiError';
    }
}

/**
 * Token CSRF dari cookie `XSRF-TOKEN` yang dipasang Laravel, dikirim sebagai `X-XSRF-TOKEN`. Isi cookie
 * ter-encode, jadi dikembalikan dengan `decodeURIComponent`.
 */
function csrfToken(): string {
    const prefix = 'XSRF-TOKEN=';
    const cookie = document.cookie
        .split('; ')
        .find((part) => part.startsWith(prefix));

    return cookie ? decodeURIComponent(cookie.slice(prefix.length)) : '';
}

function firstMessages(value: unknown): Record<string, string> {
    if (!value || typeof value !== 'object' || Array.isArray(value)) {
        return {};
    }

    return Object.fromEntries(
        Object.entries(value as Record<string, unknown>).flatMap(
            ([field, messages]) => {
                const first = Array.isArray(messages) ? messages[0] : messages;

                return typeof first === 'string' ? [[field, first]] : [];
            },
        ),
    );
}

export async function api<T>(path: string, init?: RequestInit): Promise<T> {
    const method = (init?.method ?? 'GET').toUpperCase();
    const response = await fetch(`${API_PREFIX}${path}`, {
        ...init,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            ...(SAFE_METHODS.includes(method)
                ? {}
                : { 'X-XSRF-TOKEN': csrfToken() }),
            ...init?.headers,
        },
    });

    if (!response.ok) {
        const body = (await response.json().catch(() => null)) as {
            message?: unknown;
            errors?: unknown;
            error?: { code?: unknown; message?: unknown };
        } | null;
        const message =
            typeof body?.error?.message === 'string'
                ? body.error.message
                : typeof body?.message === 'string'
                  ? body.message
                  : 'Permintaan belum berhasil. Coba lagi sebentar lagi.';

        throw new ApiError(
            response.status === 403
                ? 'Anda belum punya akses untuk tindakan ini.'
                : message,
            firstMessages(body?.errors),
            response.status,
            typeof body?.error?.code === 'string' ? body.error.code : null,
        );
    }

    return response.json() as Promise<T>;
}

export function errorMessage(caught: unknown, fallback: string): string {
    return caught instanceof Error ? caught.message : fallback;
}
