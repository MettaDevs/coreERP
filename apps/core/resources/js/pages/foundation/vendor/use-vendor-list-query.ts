import { SEARCH_DEBOUNCE_MS } from '@apperp/ui/record-search';
import type { CancelToken } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';

export type VendorListFilters = {
    q: string;
    legal_entity: string | null;
    status: 'active' | 'inactive' | null;
};

const queryKey = (filters: VendorListFilters) =>
    JSON.stringify([filters.q.trim(), filters.legal_entity, filters.status]);

type SearchRequest = { token: CancelToken | null };
type SearchDraft = { source: string; query: string; issued: string[] };

/** Transport daftar tetap milik Inertia; ketikan hanya menjadwalkan kunjungan terbarunya. */
export function useVendorListQuery(filters: VendorListFilters, paused = false) {
    const source = queryKey(filters);
    const [draft, setDraft] = useState<SearchDraft>({
        source,
        query: filters.q,
        issued: [],
    });
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const request = useRef<SearchRequest | null>(null);

    if (draft.source !== source) {
        const ownReply = draft.issued.includes(source);
        setDraft({
            source,
            query: ownReply ? draft.query : filters.q,
            issued:
                ownReply && draft.query.trim() !== filters.q.trim()
                    ? draft.issued
                    : [],
        });
    }

    const cancelCurrent = useCallback(() => {
        if (timer.current !== null) {
            clearTimeout(timer.current);
            timer.current = null;
        }

        const current = request.current;
        request.current = null;
        current?.token?.cancel();
    }, []);

    const applyFilters = useCallback(
        (next: Partial<VendorListFilters> = {}) => {
            if (paused) {
                return;
            }

            cancelCurrent();
            router.cancelAll();
            const target: VendorListFilters = {
                q: draft.query.trim(),
                legal_entity: filters.legal_entity,
                status: filters.status,
                ...next,
            };
            const key = queryKey(target);
            setDraft((current) => ({
                ...current,
                issued: [
                    ...current.issued.filter((value) => value !== key),
                    key,
                ],
            }));
            const current: SearchRequest = { token: null };
            request.current = current;
            router.get('/settings/vendors', target, {
                preserveState: true,
                reset: ['vendors'],
                preserveScroll: true,
                replace: true,
                onCancelToken: (token) => {
                    current.token = token;

                    if (request.current !== current) {
                        token.cancel();
                    }
                },
                onFinish: () => {
                    if (request.current === current) {
                        request.current = null;
                    }
                },
            });
        },
        [
            cancelCurrent,
            setDraft,
            draft.query,
            filters.legal_entity,
            filters.status,
            paused,
        ],
    );

    const onQueryChange = useCallback(
        (value: string) => {
            cancelCurrent();

            if (!paused) {
                router.cancelAll();
            }

            setDraft((current) => ({ ...current, query: value }));
        },
        [cancelCurrent, paused, setDraft],
    );

    useEffect(() => {
        if (paused) {
            cancelCurrent();

            return;
        }

        if (
            draft.source !== source ||
            draft.query.trim() === filters.q.trim()
        ) {
            return;
        }

        timer.current = setTimeout(
            () => applyFilters({}),
            draft.query.trim() ? SEARCH_DEBOUNCE_MS : 0,
        );

        return cancelCurrent;
    }, [
        applyFilters,
        cancelCurrent,
        draft.query,
        draft.source,
        filters.q,
        paused,
        source,
    ]);

    useEffect(() => {
        const historyChanged = () => {
            cancelCurrent();
            setDraft((current) => ({ ...current, issued: [] }));
        };
        window.addEventListener('popstate', historyChanged);

        return () => {
            window.removeEventListener('popstate', historyChanged);
            cancelCurrent();
        };
    }, [cancelCurrent]);

    return { query: draft.query, onQueryChange, applyFilters };
}
