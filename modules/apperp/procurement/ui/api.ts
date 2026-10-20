import { toast } from 'sonner';

export type ApiValidationErrors = Record<string, string[]>;

export class ApiError extends Error {
    constructor(
        message: string,
        public readonly validationErrors: ApiValidationErrors = {},
        public readonly status: number = 0,
        public readonly code: string | null = null,
    ) {
        super(message);
        this.name = 'ApiError';
    }
}

function normalizeValidationErrors(value: unknown): ApiValidationErrors {
    if (!value || typeof value !== 'object' || Array.isArray(value)) {
        return {};
    }

    return Object.fromEntries(
        Object.entries(value as Record<string, unknown>).flatMap(
            ([field, messages]) => {
                const normalized = Array.isArray(messages)
                    ? messages.filter(
                          (message): message is string =>
                              typeof message === 'string',
                      )
                    : typeof messages === 'string'
                      ? [messages]
                      : [];

                return normalized.length > 0 ? [[field, normalized]] : [];
            },
        ),
    );
}

export function newIdempotencyKey(): string {
    const webCrypto = globalThis.crypto;

    if (typeof webCrypto?.randomUUID === 'function') {
        try {
            return webCrypto.randomUUID();
        } catch {
            // Lanjutkan ke fallback
        }
    }

    return `req-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 9)}`;
}

/**
 * Awalan rute API procurement.
 */
const AWALAN_API = '/api/modules/procurement/v1';

const METODE_AMAN = ['GET', 'HEAD', 'OPTIONS'];

function tokenCsrf(): string {
    const awalan = 'XSRF-TOKEN=';
    const cookie = document.cookie
        .split('; ')
        .find((bagian) => bagian.startsWith(awalan));

    return cookie ? decodeURIComponent(cookie.slice(awalan.length)) : '';
}

export function api<T>(path: string, init?: RequestInit): Promise<T> {
    return request<T>(AWALAN_API, path, init);
}

async function request<T>(
    awalan: string,
    path: string,
    init?: RequestInit,
): Promise<T> {
    const metode = (init?.method ?? 'GET').toUpperCase();
    const unggahan = init?.body instanceof FormData;

    const response = await fetch(`${awalan}${path}`, {
        ...init,
        credentials: 'same-origin',
        headers: {
            ...(unggahan ? {} : { 'Content-Type': 'application/json' }),
            ...(METODE_AMAN.includes(metode)
                ? {}
                : { 'X-XSRF-TOKEN': tokenCsrf() }),
            ...init?.headers,
        },
    });

    if (!response.ok) {
        const body = (await response.json().catch(() => null)) as {
            message?: unknown;
            errors?: unknown;
            error?: {
                code?: unknown;
                message?: unknown;
                errors?: unknown;
            };
        } | null;
        const validationErrors = normalizeValidationErrors(
            body?.errors ?? body?.error?.errors,
        );
        const message =
            typeof body?.error?.message === 'string'
                ? body.error.message
                : typeof body?.message === 'string'
                  ? body.message
                  : 'Permintaan belum berhasil.';

        throw new ApiError(
            message,
            validationErrors,
            response.status,
            typeof body?.error?.code === 'string' ? body.error.code : null,
        );
    }

    return response.status === 204 ? (undefined as T) : response.json();
}

export function errorMessage(caught: unknown, fallback: string): string {
    return caught instanceof Error ? caught.message : fallback;
}

export function toastSaveError(caught: unknown, fallback: string): void {
    if (caught instanceof ApiError && caught.status >= 500) {
        toast.error(`${fallback} Terjadi kendala sistem. Silakan coba kembali nanti.`);
        return;
    }

    toast.error(errorMessage(caught, fallback));
}
