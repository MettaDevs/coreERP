import { createSaveFeedback } from "@apperp/ui/form-feedback";
import type { FormFeedback } from "@apperp/ui/form-feedback";

export type ApiValidationErrors = Record<string, string[]>;

export class ApiError extends Error {
    constructor(
        message: string,
        public readonly validationErrors: ApiValidationErrors = {},
        public readonly status: number = 0,
        public readonly code: string | null = null,
    ) {
        super(message);
        this.name = "ApiError";
    }
}

function normalizeValidationErrors(value: unknown): ApiValidationErrors {
    if (!value || typeof value !== "object" || Array.isArray(value)) {
        return {};
    }

    return Object.fromEntries(
        Object.entries(value as Record<string, unknown>).flatMap(
            ([field, messages]) => {
                const normalized = Array.isArray(messages)
                    ? messages.filter(
                          (message): message is string =>
                              typeof message === "string",
                      )
                    : typeof messages === "string"
                      ? [messages]
                      : [];

                return normalized.length > 0 ? [[field, normalized]] : [];
            },
        ),
    );
}

export function newIdempotencyKey(): string {
    const webCrypto = globalThis.crypto;

    if (typeof webCrypto?.randomUUID === "function") {
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
const API_PREFIX = "/api/modules/procurement/v1";

const SAFE_METHODS = ["GET", "HEAD", "OPTIONS"];

function csrfToken(): string {
    const prefix = "XSRF-TOKEN=";
    const cookie = document.cookie
        .split("; ")
        .find((part) => part.startsWith(prefix));

    return cookie ? decodeURIComponent(cookie.slice(prefix.length)) : "";
}

export function api<T>(path: string, init?: RequestInit): Promise<T> {
    return request<T>(API_PREFIX, path, init);
}

async function request<T>(
    prefix: string,
    path: string,
    init?: RequestInit,
): Promise<T> {
    const method = (init?.method ?? "GET").toUpperCase();
    const isFormData = init?.body instanceof FormData;

    const response = await fetch(`${prefix}${path}`, {
        ...init,
        credentials: "same-origin",
        headers: {
            ...(isFormData ? {} : { "Content-Type": "application/json" }),
            ...(SAFE_METHODS.includes(method)
                ? {}
                : { "X-XSRF-TOKEN": csrfToken() }),
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
            typeof body?.error?.message === "string"
                ? body.error.message
                : typeof body?.message === "string"
                  ? body.message
                  : "Permintaan belum berhasil.";

        throw new ApiError(
            message,
            validationErrors,
            response.status,
            typeof body?.error?.code === "string" ? body.error.code : null,
        );
    }

    return response.status === 204 ? (undefined as T) : response.json();
}

export function errorMessage(caught: unknown, fallback: string): string {
    if (caught instanceof ApiError) {
        return caught.status >= 500
            ? `${fallback} Terjadi gangguan sistem. Hubungi pengelola aplikasi.`
            : caught.message || fallback;
    }

    return fallback;
}

export function saveErrorMessage(caught: unknown, fallback: string): string {
    return caught instanceof ApiError && caught.status >= 500
        ? `${fallback} Terjadi kesalahan sistem. Hubungi pengelola aplikasi agar dapat diperiksa.`
        : caught instanceof ApiError
          ? caught.message
          : fallback;
}

export function saveErrorFeedback(
    caught: unknown,
    operation: string,
): FormFeedback {
    return createSaveFeedback({
        operation,
        message: caught instanceof ApiError ? caught.message : operation,
        status: caught instanceof ApiError ? caught.status : undefined,
        code: caught instanceof ApiError ? caught.code : null,
        errors: caught instanceof ApiError ? caught.validationErrors : {},
        details: `${operation}\n${caught instanceof ApiError ? `Status: ${caught.status}\nKode: ${caught.code ?? "-"}` : "Jenis gangguan belum diketahui."}`,
    });
}
