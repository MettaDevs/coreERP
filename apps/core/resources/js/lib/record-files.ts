import { CoreApiError, errorText } from '@/lib/core-api';

export type Attachment = {
    id: string;
    version: number;
    file_name: string;
    mime_type: string;
    size_bytes: number;
    created_by_name: string | null;
    created_at: string | null;
};

export function fileExtension(name: string): string {
    return name.includes('.')
        ? (name.split('.').pop()?.toLowerCase() ?? '')
        : '';
}

export function fileSize(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    return bytes < 1024 * 1024
        ? `${Math.round(bytes / 1024)} KB`
        : `${(bytes / (1024 * 1024)).toLocaleString('id-ID', { maximumFractionDigits: 1 })} MB`;
}

export function attachmentError(caught: unknown, fallback: string): string {
    if (caught instanceof CoreApiError && caught.status >= 500) {
        return `${fallback} Terjadi kesalahan sistem. Coba lagi atau hubungi pengelola aplikasi.`;
    }

    if (caught instanceof CoreApiError && [401, 419].includes(caught.status)) {
        return 'Sesi kamu sudah berakhir. Muat ulang halaman lalu masuk kembali.';
    }

    return caught instanceof CoreApiError
        ? errorText(caught, fallback)
        : fallback;
}
