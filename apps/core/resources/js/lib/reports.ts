import { apiJson, apiRequest } from '@/lib/core-api';

/**
 * Klien laporan Shell ke API Core. Rute JSON di bawah `/api/v1` memakai sesi yang
 * sama dengan halaman Inertia; permintaan yang mengubah data membawa token CSRF dari
 * cookie `XSRF-TOKEN`, seperti yang dilakukan axios.
 */

export type ReportFormat = 'pdf' | 'docx' | 'xlsx';
export type LayoutFormat = 'docx' | 'xlsx';
export type ExportStatus = 'queued' | 'running' | 'done' | 'failed';

export type BuiltinLayout = {
    ref: string;
    name: string;
    description: string | null;
    format: LayoutFormat;
    outputs: ReportFormat[];
};

export type Report = {
    code: string;
    app_id: string;
    app_name: string;
    name: string;
    description: string | null;
    permission: string;
    parameters: string[];
    can_run: boolean;
    builtin_layouts: BuiltinLayout[];
};

export type ReportField = { key: string; label: string; table: string | null };

export type Layout = {
    ref: string;
    name: string;
    description: string | null;
    format: LayoutFormat;
    outputs: ReportFormat[];
    source: 'builtin' | 'uploaded';
    legal_entity_id: string | null;
    file_size: number | null;
    created_at: string | null;
    is_default: boolean;
};

export type ReportExport = {
    id: string;
    app_id: string;
    report_code: string;
    report_name: string;
    layout_ref: string;
    layout_name: string;
    format: ReportFormat;
    parameters: Record<string, unknown>;
    status: ExportStatus;
    progress: number;
    row_count: number | null;
    file_name: string | null;
    file_size: number | null;
    failure_message: string | null;
    started_at: string | null;
    finished_at: string | null;
    expires_at: string | null;
    created_at: string;
};

export const FORMAT_LABEL: Record<ReportFormat, string> = {
    pdf: 'PDF',
    docx: 'Word',
    xlsx: 'Excel',
};

export const STATUS_LABEL: Record<ExportStatus, string> = {
    queued: 'Menunggu',
    running: 'Diproses',
    done: 'Selesai',
    failed: 'Gagal',
};

export const isActive = (item: { status: ExportStatus }) =>
    item.status === 'queued' || item.status === 'running';

const request = apiRequest;
const json = apiJson;

export const listReports = () =>
    json<{ data: Report[] }>('/api/v1/reports').then((r) => r.data);

export const listFields = (code: string) =>
    json<{ data: ReportField[]; meta: { parameters: string[] } }>(
        `/api/v1/reports/${encodeURIComponent(code)}/fields`,
    );

export const listLayouts = (code: string) =>
    json<{ data: Layout[]; meta: { default_ref: string } }>(
        `/api/v1/reports/${encodeURIComponent(code)}/layouts`,
    );

export function uploadLayout(
    code: string,
    input: {
        file: File;
        name: string;
        description: string;
        scope: 'tenant' | 'legal_entity';
    },
) {
    const body = new FormData();
    body.append('file', input.file);
    body.append('name', input.name);

    if (input.description) {
        body.append('description', input.description);
    }

    body.append('scope', input.scope);

    return json<{
        data: { id: string; name: string };
        meta: { unknown_placeholders: string[] };
    }>(`/api/v1/reports/${encodeURIComponent(code)}/layouts`, {
        method: 'POST',
        body,
    });
}

export function replaceLayoutFile(code: string, id: string, file: File) {
    const body = new FormData();
    body.append('file', file);

    return json<{ meta: { unknown_placeholders: string[] } }>(
        `/api/v1/reports/${encodeURIComponent(code)}/layouts/${id}`,
        { method: 'POST', body },
    );
}

export const deleteLayout = (code: string, id: string) =>
    request(`/api/v1/reports/${encodeURIComponent(code)}/layouts/${id}`, {
        method: 'DELETE',
    });

export const setDefaultLayout = (
    code: string,
    layoutRef: string | null,
    scope: 'tenant' | 'legal_entity',
) =>
    json<{ data: Layout[]; meta: { default_ref: string } }>(
        `/api/v1/reports/${encodeURIComponent(code)}/layout-default`,
        {
            method: 'PUT',
            body: JSON.stringify({ layout_ref: layoutRef, scope }),
        },
    );

export const requestExport = (
    code: string,
    input: {
        format: ReportFormat;
        layout_ref: string | null;
        parameters: Record<string, unknown>;
    },
) =>
    json<{ data: ReportExport }>(
        `/api/v1/reports/${encodeURIComponent(code)}/exports`,
        {
            method: 'POST',
            body: JSON.stringify(input),
        },
    ).then((r) => r.data);

export const listExports = () =>
    json<{ data: ReportExport[] }>('/api/v1/report-exports').then(
        (r) => r.data,
    );

export const deleteExport = (id: string) =>
    request(`/api/v1/report-exports/${id}`, { method: 'DELETE' });

/**
 * Mengunduh lewat fetch supaya kegagalan (berkas kedaluwarsa, sesi habis) terbaca
 * sebagai pesan, bukan halaman error yang terbuka di tab baru.
 */
export async function downloadFile(
    path: string,
    fileName: string,
): Promise<void> {
    const response = await request(path);
    const blob = await response.blob();
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = fileName;
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    setTimeout(() => URL.revokeObjectURL(url), 10_000);
}

export const downloadExport = (item: ReportExport) =>
    downloadFile(
        `/api/v1/report-exports/${item.id}/download`,
        item.file_name ?? `${item.report_code}.${item.format}`,
    );

export const downloadLayout = (code: string, layout: Layout) =>
    downloadFile(
        `/api/v1/reports/${encodeURIComponent(code)}/layouts/${encodeURIComponent(layout.ref)}/file`,
        `${code}-${layout.name}.${layout.format}`,
    );

export function formatBytes(bytes: number | null): string {
    if (bytes === null) {
        return '—';
    }

    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(0)} KB`;
    }

    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

export function formatTime(value: string | null): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return date.toLocaleString('id-ID', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}
