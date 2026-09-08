import { toast } from 'sonner';

import { addNotification } from '@/lib/notifications';
import { downloadExport, isActive, listExports } from '@/lib/reports';
import type { ReportExport } from '@/lib/reports';

/**
 * Pemantau ekspor milik pengguna di Shell.
 *
 * Polling ke Core hanya berjalan selama ada ekspor yang belum selesai, lalu berhenti
 * sendiri. Ekspor yang baru selesai atau gagal diumumkan lewat toast dan lonceng.
 * Id yang sudah diumumkan disimpan di localStorage supaya ekspor yang selesai ketika
 * browser tertutup tetap masuk ke lonceng saat Shell dibuka lagi, tanpa diumumkan dua
 * kali.
 */

const POLL_MS = 2500;
const ANNOUNCED_KEY = 'coreerp.report-exports.announced';
const MAX_ANNOUNCED = 200;

type Listener = () => void;

let exports: ReportExport[] = [];
let timer: number | null = null;
let announced: Set<string> | null = null;
const listeners = new Set<Listener>();

export function getExports(): ReportExport[] {
    return exports;
}

export function subscribeExports(listener: Listener): () => void {
    listeners.add(listener);

    return () => {
        listeners.delete(listener);
    };
}

function emit(): void {
    listeners.forEach((listener) => listener());
}

function readAnnounced(): Set<string> {
    if (announced) {
        return announced;
    }

    let ids: string[] = [];

    try {
        const raw = localStorage.getItem(ANNOUNCED_KEY);
        const parsed = raw ? (JSON.parse(raw) as unknown) : [];

        if (Array.isArray(parsed)) {
            ids = parsed.filter((id): id is string => typeof id === 'string');
        }
    } catch {
        // Tanpa penyimpanan, pengumuman tetap berjalan selama halaman hidup.
    }

    announced = new Set(ids);

    return announced;
}

function saveAnnounced(ids: Set<string>): void {
    try {
        localStorage.setItem(
            ANNOUNCED_KEY,
            JSON.stringify([...ids].slice(-MAX_ANNOUNCED)),
        );
    } catch {
        // Penyimpanan penuh: paling buruk diumumkan lagi saat dibuka ulang.
    }
}

function announce(item: ReportExport): void {
    if (item.status === 'done') {
        toast.success(`${item.report_name} siap diunduh.`, {
            action: {
                label: 'Unduh',
                onClick: () =>
                    void downloadExport(item).catch((caught: Error) =>
                        toast.error(
                            caught.message || 'Berkas belum dapat diunduh.',
                        ),
                    ),
            },
        });
        addNotification({
            id: `report-export:${item.id}`,
            appId: item.app_id,
            appName: item.report_name,
            level: 'success',
            title: `${item.report_name} siap diunduh`,
            body: `${item.file_name ?? item.format.toUpperCase()} · layout ${item.layout_name}`,
            href: '/reports/exports',
        });
    } else if (item.status === 'failed') {
        const message =
            item.failure_message ?? `${item.report_name} gagal diekspor.`;
        toast.error(message);
        addNotification({
            id: `report-export:${item.id}`,
            appId: item.app_id,
            appName: item.report_name,
            level: 'error',
            title: `${item.report_name} gagal diekspor`,
            body: message,
            href: '/reports/exports',
        });
    }
}

export async function refreshExports(): Promise<void> {
    try {
        exports = await listExports();
    } catch {
        // Sesi habis atau Core tidak menjawab: pemantauan berhenti, tray diam.
        stopPolling();
        emit();

        return;
    }

    const seen = readAnnounced();
    let changed = false;

    for (const item of exports) {
        if (isActive(item) || seen.has(item.id)) {
            continue;
        }

        seen.add(item.id);
        changed = true;
        announce(item);
    }

    if (changed) {
        saveAnnounced(seen);
    }

    emit();

    if (exports.some(isActive)) {
        startPolling();
    } else {
        stopPolling();
    }
}

function startPolling(): void {
    if (timer !== null) {
        return;
    }

    timer = window.setInterval(() => void refreshExports(), POLL_MS);
}

function stopPolling(): void {
    if (timer !== null) {
        window.clearInterval(timer);
        timer = null;
    }
}

/** Dipanggil dialog cetak setelah permintaan diterima Core. */
export function trackExport(item: ReportExport): void {
    exports = [item, ...exports.filter((other) => other.id !== item.id)];
    emit();
    startPolling();
}
