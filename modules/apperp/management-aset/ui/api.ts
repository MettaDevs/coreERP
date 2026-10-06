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

export function api<T>(path: string, init?: RequestInit): Promise<T> {
    return request<T>(AWALAN_API, path, init);
}

/**
 * Layanan milik Core untuk pengguna yang sedang masuk, dengan sesi yang sama: opsi terakhir dan preset
 * laporan (K-24, K-25). Laporan dijalankan mesin Core, jadi pilihan yang disimpan untuknya juga milik Core;
 * module tidak menyimpan salinannya sendiri. Hanya untuk rute `/api/v1/reports/...` yang disebut di
 * `laporan/_shared/reportOptions.ts`.
 */
export function coreApi<T>(path: string, init?: RequestInit): Promise<T> {
    return request<T>('/api/v1', path, init);
}

async function request<T>(
    awalan: string,
    path: string,
    init?: RequestInit,
): Promise<T> {
    const metode = (init?.method ?? 'GET').toUpperCase();
    // Unggahan berkas memasang Content-Type multipart beserta batasnya sendiri; menimpanya
    // dengan JSON membuat server tidak dapat membaca berkasnya.
    const unggahan = init?.body instanceof FormData;
    // Permintaan memakai sesi Core, bukan token pembawa: tidak ada lagi header
    // `Authorization`, dan cookie sesi ikut karena permintaannya same-origin.
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

export function saveErrorMessage(caught: unknown, fallback: string): string {
    return caught instanceof ApiError && caught.status >= 500
        ? `${fallback} Terjadi kesalahan sistem. Hubungi pengelola aplikasi agar dapat diperiksa.`
        : errorMessage(caught, fallback);
}

/**
 * Toast untuk kegagalan menyimpan. Versi basi mendapat tombol muat ulang penuh: isian form
 * harus kembali ke data terbaru supaya pengguna melihat perubahan orang lain lebih dulu.
 */
export function toastSaveError(caught: unknown, fallback: string): void {
    if (caught instanceof ApiError && caught.status >= 500) {
        toast.error(saveErrorMessage(caught, fallback), {
            duration: Infinity,
            closeButton: true,
        });

        return;
    }

    if (caught instanceof ApiError && caught.code === 'stale_version') {
        toast.error(caught.message, {
            duration: Infinity,
            action: {
                label: 'Muat ulang',
                onClick: () => window.location.reload(),
            },
        });

        return;
    }

    toast.error(errorMessage(caught, fallback));
}
