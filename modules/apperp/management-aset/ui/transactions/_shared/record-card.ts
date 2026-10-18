import type { RefObject } from 'react';
import { usePageDialogRecord } from '@apperp/ui/page-dialog';
import type { PageDialogRecord } from '@apperp/ui/page-dialog';

/** Navigasi kartu lokal; tanpa callback, halaman tetap mendukung URL detail langsung. */
export type RecordCardControls = {
    onClose?: () => void;
    onSaved?: (id: string) => void;
    onRequestView?: (id: string) => void;
    onRequestEdit?: (id: string) => void;
    onBusyChange?: (busy: boolean) => void;
    onDirtyChange?: (dirty: boolean) => void;
    portalContainer?: RefObject<HTMLDivElement | null>;
};

export type RecordCardState =
    { mode: 'create' } | { mode: 'view' | 'edit'; id: string } | null;

/** Metadata kartu didaftarkan setelah record siap, tanpa membuat header tandingan. */
export function RecordCardHeader(record: PageDialogRecord) {
    usePageDialogRecord(record);

    return null;
}

export function recordQueryUrl(
    feature: string,
    id: string,
    kind?: string,
    date?: string,
): string {
    const query = new URLSearchParams({ record: id });

    if (kind) {
        query.set('record_kind', kind);
    }

    if (date) {
        query.set('record_date', date);
    }

    return '/management-aset/' + feature + '?' + query.toString();
}
