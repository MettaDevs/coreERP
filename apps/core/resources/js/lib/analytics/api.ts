import type {
    DashboardDetail,
    DashboardLayoutItem,
    DashboardWidget,
    ResultSet,
} from '@/lib/analytics/types';
import { apiJson, apiRequest, CoreApiError } from '@/lib/core-api';

/**
 * Panggilan layar ke API dasbor `api/v1/analytics/...` (area 6, `docs/todo/analitik/dasbor-dan-visual.md`
 * bagian *API untuk layar*). Setiap perubahan membawa versi yang dibuka pengguna lewat `If-Match`; versi
 * basi dijawab 409 `stale_version`, dan layar memakai `version` dari jawaban, tidak menghitungnya sendiri
 * (versi naik dua kali pada perubahan yang menulis kolom).
 */

const BASE = '/api/v1/analytics';

function ifMatch(version: number): Record<string, string> {
    return { 'If-Match': `"${version}"` };
}

/**
 * Hasil query satu widget, dihitung server sebagai pengguna yang melihat. `refresh` meminta hitungan baru
 * tanpa hasil simpanan.
 */
export function fetchWidgetData(
    widgetId: string,
    signal: AbortSignal,
    refresh = false,
): Promise<ResultSet> {
    const path = `${BASE}/widgets/${encodeURIComponent(widgetId)}`;

    return refresh
        ? apiJson<ResultSet>(`${path}/refresh`, { method: 'POST', signal })
        : apiJson<ResultSet>(`${path}/data`, { signal });
}

export type DashboardInput = {
    name: string;
    description: string | null;
    shared: boolean;
};

export async function createDashboard(
    input: DashboardInput,
): Promise<DashboardDetail> {
    return (
        await apiJson<{ data: DashboardDetail }>(`${BASE}/dashboards`, {
            method: 'POST',
            body: JSON.stringify(input),
        })
    ).data;
}

export async function updateDashboard(
    dashboard: Pick<DashboardDetail, 'id' | 'version'>,
    changes: Partial<DashboardInput> & { layout?: DashboardLayoutItem[] },
): Promise<DashboardDetail> {
    return (
        await apiJson<{ data: DashboardDetail }>(
            `${BASE}/dashboards/${encodeURIComponent(dashboard.id)}`,
            {
                method: 'PATCH',
                headers: ifMatch(dashboard.version),
                body: JSON.stringify(changes),
            },
        )
    ).data;
}

/** Mengarsipkan dasbor beserta isinya; tidak ada yang dihapus permanen. */
export async function archiveDashboard(
    dashboard: Pick<DashboardDetail, 'id' | 'version'>,
): Promise<void> {
    await apiRequest(`${BASE}/dashboards/${encodeURIComponent(dashboard.id)}`, {
        method: 'DELETE',
        headers: ifMatch(dashboard.version),
    });
}

export async function updateWidget(
    widget: Pick<DashboardWidget, 'id' | 'version'>,
    changes: Partial<Pick<DashboardWidget, 'title' | 'type' | 'visual'>>,
): Promise<DashboardWidget> {
    return (
        await apiJson<{ data: DashboardWidget }>(
            `${BASE}/widgets/${encodeURIComponent(widget.id)}`,
            {
                method: 'PATCH',
                headers: ifMatch(widget.version),
                body: JSON.stringify(changes),
            },
        )
    ).data;
}

export async function archiveWidget(
    widget: Pick<DashboardWidget, 'id' | 'version'>,
): Promise<void> {
    await apiRequest(`${BASE}/widgets/${encodeURIComponent(widget.id)}`, {
        method: 'DELETE',
        headers: ifMatch(widget.version),
    });
}

/**
 * Kegagalan data widget yang dapat ditindaklanjuti (`docs/todo/analitik/dasbor-dan-visual.md`, bagian
 * *Memuat data widget*): setiap jenis punya kalimat dan tindakannya sendiri. Satu widget yang gagal tidak
 * menjatuhkan dasbor.
 */
export type WidgetFailure = {
    kind:
        | 'forbidden'
        | 'personal_data'
        | 'timeout'
        | 'field_removed'
        | 'unavailable'
        | 'busy'
        | 'invalid'
        | 'other';
    message: string;
};

export function widgetFailure(caught: unknown): WidgetFailure {
    if (!(caught instanceof CoreApiError)) {
        return {
            kind: 'other',
            message:
                'Data belum dapat dimuat. Periksa sambungan, lalu muat ulang.',
        };
    }

    switch (caught.code) {
        case 'analytics.dataset_forbidden':
            return {
                kind: 'forbidden',
                message:
                    'Anda tidak punya akses ke data ini. Mintalah akses kepada admin bila Anda memerlukannya.',
            };
        case 'analytics.field_personal_data':
            return {
                kind: 'personal_data',
                message: `${caught.message} Bagian ini tidak dapat ditampilkan untuk Anda.`,
            };
        case 'analytics.query_timeout':
            return {
                kind: 'timeout',
                message:
                    'Perhitungan terlalu berat. Persempit periode atau saringannya.',
            };
        case 'analytics.field_removed':
            return { kind: 'field_removed', message: caught.message };
        case 'analytics.dataset_unknown':
            return {
                kind: 'unavailable',
                message:
                    'Data ini tidak tersedia lagi. Aplikasinya mungkin sudah tidak terpasang.',
            };
        case 'analytics.busy':
            return {
                kind: 'busy',
                message:
                    'Sedang banyak perhitungan berjalan. Coba muat ulang sebentar lagi.',
            };
    }

    if (caught.status === 404) {
        return {
            kind: 'unavailable',
            message:
                'Bagian ini tidak ditemukan lagi. Muat ulang halaman untuk melihat isi dasbor terbaru.',
        };
    }

    if (caught.status === 403) {
        return {
            kind: 'forbidden',
            message: 'Anda tidak punya akses untuk melihat bagian ini.',
        };
    }

    if (caught.status === 429) {
        return {
            kind: 'busy',
            message:
                'Terlalu banyak permintaan dalam waktu singkat. Coba muat ulang sebentar lagi.',
        };
    }

    if (caught.status === 422) {
        return { kind: 'invalid', message: caught.message };
    }

    return {
        kind: 'other',
        message: 'Data belum dapat dimuat. Coba muat ulang.',
    };
}
