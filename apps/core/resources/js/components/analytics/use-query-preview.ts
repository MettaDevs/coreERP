import { useEffect, useRef, useState } from 'react';
import { runQuery, widgetFailure } from '@/lib/analytics/api';
import type { AnalyticsQuery, ResultSet } from '@/lib/analytics/types';
import { CoreApiError } from '@/lib/core-api';

/** Jeda sejak isian terakhir berubah sebelum query dijalankan. */
export const PREVIEW_DELAY_MS = 500;

type Loaded = {
    key: string;
    result: ResultSet | null;
    error: unknown;
};

/**
 * Hasil `POST api/v1/analytics/query` untuk query yang sedang disusun (area 8.4). Query dijalankan setelah isian
 * berhenti berubah selama `PREVIEW_DELAY_MS`, dan permintaan untuk query sebelumnya dibatalkan, jadi mengetik
 * cepat tidak menembakkan satu perhitungan per perubahan dan jawaban lama tidak pernah menimpa yang baru.
 *
 * Hasil disimpan bersama kuncinya (teks query dan urutan muat ulang). `previous` adalah hasil terakhir yang
 * berhasil, untuk ditampilkan redup selama perhitungan berikutnya berjalan. Membuka layar dan Muat ulang tidak
 * menunggu jeda. `null` berarti query belum lengkap; tidak ada yang dikirim.
 */
export function useQueryPreview(query: AnalyticsQuery | null) {
    const body = query === null ? null : JSON.stringify(query);
    const [attempt, setAttempt] = useState(0);
    const [loaded, setLoaded] = useState<Loaded | null>(null);
    const [previous, setPrevious] = useState<ResultSet | null>(null);
    const lastBody = useRef<string | null | undefined>(undefined);
    const key = body === null ? null : `${attempt}:${body}`;

    useEffect(() => {
        if (body === null || key === null) {
            return;
        }

        // Teks query yang sama (membuka layar, Muat ulang) dijalankan langsung; yang berubah menunggu jeda.
        const wait =
            lastBody.current === undefined || lastBody.current === body
                ? 0
                : PREVIEW_DELAY_MS;
        lastBody.current = body;
        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            runQuery(body, controller.signal)
                .then((result) => {
                    setLoaded({ key, result, error: null });
                    setPrevious(result);
                })
                .catch((caught: unknown) => {
                    if (!controller.signal.aborted) {
                        setLoaded({ key, result: null, error: caught });
                    }
                });
        }, wait);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [body, key]);

    const current = key !== null && loaded?.key === key ? loaded : null;

    return {
        loading: key !== null && current === null,
        result: current?.result ?? null,
        error: current?.error ?? null,
        previous: key === null ? null : previous,
        reload: () => setAttempt((value) => value + 1),
    };
}

/** Galat satu rumus: rumus ke berapa, pesannya, dan karakter tempatnya bila server menyebutnya. */
export type FormulaError = {
    index: number;
    message: string;
    position: number | null;
};

/**
 * Letak galat query di layar: galat saringan (`filters.<kolom>`), periode (`time_range.range`), dan rumus
 * (`formulas.<n>.…`, area 13) ditampilkan di isiannya, sisanya di area hasil. Pesannya dari server, kecuali
 * kegagalan yang punya kalimat sendiri.
 */
export function queryErrorPlacement(error: unknown): {
    filters: Record<string, string>;
    timeRange: string | null;
    formula: FormulaError | null;
    formulaLimit: string | null;
    message: string | null;
} {
    if (error === null || error === undefined) {
        return {
            filters: {},
            timeRange: null,
            formula: null,
            formulaLimit: null,
            message: null,
        };
    }

    const message = widgetFailure(error).message;
    const field = error instanceof CoreApiError ? (error.field ?? '') : '';

    if (field.startsWith('filters.')) {
        return {
            filters: { [field.slice('filters.'.length)]: message },
            timeRange: null,
            formula: null,
            formulaLimit: null,
            message: 'Periksa saringan yang ditandai.',
        };
    }

    if (field === 'time_range.range') {
        return {
            filters: {},
            timeRange: message,
            formula: null,
            formulaLimit: null,
            message: 'Periksa periode yang ditandai.',
        };
    }

    if (
        field === 'formulas' &&
        error instanceof CoreApiError &&
        error.code === 'analytics.limit_exceeded'
    ) {
        return {
            filters: {},
            timeRange: null,
            formula: null,
            formulaLimit: message,
            message: null,
        };
    }

    const formula = /^formulas\.(\d+)(\.|$)/.exec(field);

    if (formula !== null && error instanceof CoreApiError) {
        return {
            filters: {},
            timeRange: null,
            formula: {
                index: Number(formula[1]),
                message,
                position: error.position,
            },
            formulaLimit: null,
            message: 'Periksa rumus yang ditandai.',
        };
    }

    return {
        filters: {},
        timeRange: null,
        formula: null,
        formulaLimit: null,
        message,
    };
}
