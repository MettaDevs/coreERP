import * as React from 'react';
import type { DataTableColumn, DataTableSort } from './data-table';

export function lookupComparator<T>(
    columns: DataTableColumn<T>[],
    sort: DataTableSort | null,
) {
    const column = columns.find((item) => item.id === sort?.columnId);

    if (!sort || !column?.sortValue) {
        return undefined;
    }

    return (left: T, right: T) => {
        const a = column.sortValue!(left);
        const b = column.sortValue!(right);
        const result =
            typeof a === 'number' && typeof b === 'number'
                ? a - b
                : String(a).localeCompare(String(b), 'id-ID');

        return result * (sort.direction === 'asc' ? 1 : -1);
    };
}

export type LookupLoader<T> = (request: {
    query: string;
    cursor: string | null;
    signal: AbortSignal;
}) => Promise<{ items: T[]; nextCursor: string | null }>;

/** Popup dan daftar lengkap berbagi batas pemuatan, pembatalan, dan deduplikasi. */
export function useInlineLookupData<T>({
    items = [],
    loadItems,
    query,
    active,
    getItemValue,
    getSearchText,
    compareItems,
}: {
    items?: T[];
    loadItems?: LookupLoader<T>;
    query: string;
    active: boolean;
    getItemValue: (item: T) => string;
    getSearchText: (item: T) => string;
    compareItems?: (left: T, right: T) => number;
}) {
    const [visibleCount, setVisibleCount] = React.useState(40);
    const [state, setState] = React.useState<{
        source?: LookupLoader<T>;
        query: string;
        items: T[];
        nextCursor: string | null;
        loading: boolean;
        error: string;
    }>({ query: '', items: [], nextCursor: null, loading: false, error: '' });
    const cache = React.useRef<(typeof state & { loadedAt: number }) | null>(
        null,
    );
    const controller = React.useRef<AbortController | null>(null);
    const busy = React.useRef(false);
    const keyOf = React.useRef(getItemValue);
    keyOf.current = getItemValue;

    const fetchBatch = React.useCallback(
        async (cursor: string | null) => {
            if (!loadItems || busy.current) {
                return;
            }

            busy.current = true;
            const request = new AbortController();
            controller.current = request;
            setState((current) => ({
                ...(cursor === null
                    ? { source: loadItems, query, items: [], nextCursor: null }
                    : current),
                loading: true,
                error: '',
            }));

            try {
                const result = await loadItems({
                    query,
                    cursor,
                    signal: request.signal,
                });

                if (request.signal.aborted) {
                    return;
                }

                const previous =
                    cursor !== null &&
                    cache.current?.source === loadItems &&
                    cache.current.query === query
                        ? cache.current.items
                        : [];
                const unique = new Map(
                    previous.map((item) => [keyOf.current(item), item]),
                );
                result.items.forEach((item) =>
                    unique.set(keyOf.current(item), item),
                );
                const next = {
                    source: loadItems,
                    query,
                    items: [...unique.values()],
                    nextCursor:
                        result.nextCursor === cursor ? null : result.nextCursor,
                    loading: false,
                    error: '',
                };
                cache.current = { ...next, loadedAt: Date.now() };
                setState(next);
            } catch (caught) {
                if (!request.signal.aborted) {
                    setState((current) => ({
                        ...current,
                        loading: false,
                        error:
                            caught instanceof Error
                                ? caught.message
                                : 'Pilihan belum dapat dimuat.',
                    }));
                }
            } finally {
                if (controller.current === request) {
                    busy.current = false;
                }
            }
        },
        [loadItems, query],
    );

    React.useEffect(() => {
        setVisibleCount(40);

        if (!active || !loadItems) {
            return;
        }

        // Cache hanya milik field ini; perubahan scope/source dan aksi tulis membatalkannya.
        if (
            cache.current?.source === loadItems &&
            cache.current.query === query &&
            Date.now() - cache.current.loadedAt < 30_000
        ) {
            setState(cache.current);

            return () => {
                controller.current?.abort();
                busy.current = false;
            };
        }

        // Membuka daftar kosong langsung fetch; hanya pencarian ketikan yang menunggu debounce.
        const timer =
            query.trim() === ''
                ? null
                : setTimeout(() => void fetchBatch(null), 250);

        if (timer === null) {
            void fetchBatch(null);
        }

        return () => {
            if (timer !== null) {
                clearTimeout(timer);
            }

            controller.current?.abort();
            busy.current = false;
        };
    }, [active, loadItems, fetchBatch]);

    const normalized = query.trim().toLocaleLowerCase('id-ID');
    const filtered = loadItems
        ? []
        : items.filter((item) =>
              getSearchText(item)
                  .toLocaleLowerCase('id-ID')
                  .includes(normalized),
          );

    if (compareItems && !loadItems) {
        filtered.sort(compareItems);
    }

    const current = state.source === loadItems && state.query === query;
    const displayed = loadItems
        ? current
            ? state.items
            : []
        : filtered.slice(0, visibleCount);
    const hasMore = loadItems
        ? current && state.nextCursor !== null
        : filtered.length > visibleCount;
    const loading = Boolean(loadItems) && active && (!current || state.loading);
    const error = current ? state.error : '';
    const loadMore = () => {
        if (loading || error || !hasMore) {
            return;
        }

        if (loadItems) {
            void fetchBatch(state.nextCursor);
        } else {
            setVisibleCount((count) => count + 40);
        }
    };
    const onScrollCapture: React.UIEventHandler<HTMLElement> = (event) => {
        const target = event.target;

        if (
            target instanceof HTMLElement &&
            target.scrollHeight - target.scrollTop - target.clientHeight < 160
        ) {
            loadMore();
        }
    };

    return {
        invalidate: () => {
            cache.current = null;
        },
        items: displayed,
        loading,
        error,
        hasMore,
        onScrollCapture,
        retry: () => void fetchBatch(state.nextCursor),
    };
}
