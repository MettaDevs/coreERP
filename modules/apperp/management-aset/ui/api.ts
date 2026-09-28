export type ApiValidationErrors = Record<string, string[]>;

export class ApiError extends Error {
    constructor(
        message: string,
        public readonly validationErrors: ApiValidationErrors = {},
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
            const hex = Array.from(bytes, (byte) =>
                byte.toString(16).padStart(2, '0'),
            ).join('');

            return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
        } catch {
            // Fallback terakhir di bawah tetap cukup untuk kunci idempotensi.
        }
    }

    return `request-${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}-${Math.random().toString(36).slice(2)}`;
}

/**
 * Awalan rute JSON module, mutlak dari akar dokumen.
 *
 * Sebelumnya alamat dihitung relatif terhadap `document.baseURI`, karena UI ini disajikan
 * di dalam iframe di bawah satu awalan penempatan per app dan reverse proxy meneruskan
 * seluruh isi awalan itu — termasuk `api/` — ke container app. Awalan itu tidak ada lagi:
 * layar module berjalan di dokumen shell, dan penyedia layanan module mendaftarkan rutenya
 * apa adanya di bawah alamat ini.
 */
const AWALAN_API = '/api/modules/management-aset/v1';

/** Metode yang dilewati pemeriksa CSRF Laravel, jadi tidak perlu membawa tokennya. */
const METODE_AMAN = ['GET', 'HEAD', 'OPTIONS'];

/**
 * Token CSRF, dibaca dari cookie `XSRF-TOKEN` yang dipasang Laravel.
 *
 * Isi cookie ditulis peramban dalam bentuk ter-encode, jadi ia harus dikembalikan dengan
 * `decodeURIComponent` sebelum dikirim. Header yang dibaca `PreventRequestForgery` untuk
 * nilai ini adalah `X-XSRF-TOKEN`: ia mendekripsi sendiri isinya, karena cookie itu
 * terenkripsi seperti cookie lain. `X-CSRF-TOKEN` bukan padanannya — header itu menunggu
 * token sesi mentah, yang tidak pernah sampai ke sisi peramban.
 */
function tokenCsrf(): string {
    const awalan = 'XSRF-TOKEN=';
    const cookie = document.cookie
        .split('; ')
        .find((bagian) => bagian.startsWith(awalan));

    return cookie ? decodeURIComponent(cookie.slice(awalan.length)) : '';
}

export async function api<T>(path: string, init?: RequestInit): Promise<T> {
    const metode = (init?.method ?? 'GET').toUpperCase();
    // Unggahan berkas memasang Content-Type multipart beserta batasnya sendiri; menimpanya
    // dengan JSON membuat server tidak dapat membaca berkasnya.
    const unggahan = init?.body instanceof FormData;
    // Permintaan memakai sesi Core, bukan token pembawa: tidak ada lagi header
    // `Authorization`, dan cookie sesi ikut karena permintaannya same-origin.
    const response = await fetch(`${AWALAN_API}${path}`, {
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
                message?: unknown;
                errors?: unknown;
                details?: { errors?: unknown };
            };
        } | null;
        const validationErrors = normalizeValidationErrors(
            body?.errors ?? body?.error?.errors ?? body?.error?.details?.errors,
        );
        const message =
            typeof body?.error?.message === 'string'
                ? body.error.message
                : typeof body?.message === 'string'
                  ? body.message
                  : 'Permintaan belum berhasil.';

        throw new ApiError(message, validationErrors);
    }

    return response.status === 204 ? (undefined as T) : response.json();
}

export function errorMessage(caught: unknown, fallback: string): string {
    return caught instanceof Error ? caught.message : fallback;
}
