let contextToken = '';

export type ApiValidationErrors = Record<string, string[]>;

export class ApiError extends Error {
    constructor(message: string, public readonly validationErrors: ApiValidationErrors = {}) {
        super(message);
        this.name = 'ApiError';
    }
}

function normalizeValidationErrors(value: unknown): ApiValidationErrors {
    if (!value || typeof value !== 'object' || Array.isArray(value)) return {};

    return Object.fromEntries(Object.entries(value as Record<string, unknown>).flatMap(([field, messages]) => {
        const normalized = Array.isArray(messages)
            ? messages.filter((message): message is string => typeof message === 'string')
            : typeof messages === 'string' ? [messages] : [];

        return normalized.length > 0 ? [[field, normalized]] : [];
    }));
}

/** Token konteks hanya berasal dari Web Shell; UI tidak pernah menyusun tenant sendiri. */
export function setContextToken(token: string): void {
    contextToken = token;
}

/**
 * Membuat kunci request walau UI dibuka melalui HTTP dan browser tidak
 * menyediakan `crypto.randomUUID()` pada konteks tersebut.
 */
export function newIdempotencyKey(): string {
    const webCrypto = globalThis.crypto;
    if (typeof webCrypto?.randomUUID === 'function') {
        try {
            return webCrypto.randomUUID();
        } catch {
            // Lanjutkan ke sumber acak yang lebih kompatibel.
        }
    }

    if (typeof webCrypto?.getRandomValues === 'function') {
        try {
            const bytes = new Uint8Array(16);
            webCrypto.getRandomValues(bytes);
            bytes[6] = (bytes[6] & 0x0f) | 0x40;
            bytes[8] = (bytes[8] & 0x3f) | 0x80;
            const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');

            return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
        } catch {
            // Fallback terakhir di bawah tetap cukup untuk kunci idempotensi.
        }
    }

    return `request-${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}-${Math.random().toString(36).slice(2)}`;
}

/**
 * Alamat API selalu dihitung relatif terhadap dokumen, bukan terhadap root origin.
 *
 * Di dalam Web Shell, UI ini disajikan same-origin di bawah prefix per placement,
 * sehingga `/api/v1` akan menunjuk control plane, bukan API app ini. Reverse proxy
 * meneruskan seluruh isi prefix — termasuk `api/` — ke container app.
 */
function apiUrl(path: string): string {
    return new URL(`api/v1${path}`, document.baseURI).toString();
}

export async function api<T>(path: string, init?: RequestInit): Promise<T> {
    const response = await fetch(apiUrl(path), {
        ...init,
        headers: {
            'Content-Type': 'application/json',
            Authorization: `Bearer ${contextToken}`,
            ...init?.headers,
        },
    });
    if (!response.ok) {
        const body = await response.json().catch(() => null) as {
            message?: unknown;
            errors?: unknown;
            error?: { message?: unknown; errors?: unknown; details?: { errors?: unknown } };
        } | null;
        const validationErrors = normalizeValidationErrors(body?.errors ?? body?.error?.errors ?? body?.error?.details?.errors);
        const message = typeof body?.error?.message === 'string' ? body.error.message : typeof body?.message === 'string' ? body.message : 'Permintaan belum berhasil.';
        throw new ApiError(message, validationErrors);
    }
    return response.status === 204 ? (undefined as T) : response.json();
}

export function errorMessage(caught: unknown, fallback: string): string {
    return caught instanceof Error ? caught.message : fallback;
}
