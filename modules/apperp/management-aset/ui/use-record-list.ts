import {
    assertListBatch,
    useIncrementalList,
} from '@apperp/ui/use-incremental-list';
import type { ListRequest } from '@apperp/ui/use-incremental-list';
import { useCallback, useEffect, useState } from 'react';
import { api } from './api';

export function recordListParams(
    { query, cursor, sort }: ListRequest,
    filters: URLSearchParams = new URLSearchParams(),
) {
    const params = new URLSearchParams(filters);
    params.set('q', query);
    params.delete('cursor');

    if (cursor) {
        params.set('cursor', cursor);
    }

    if (sort) {
        params.set('sort', sort.columnId);
        params.set('direction', sort.direction);
    }

    return params;
}

/** Semua daftar module memakai batch server yang sama, termasuk filter dan sorting. */
export function useRecordList<T extends { id: string }>(
    path: string,
    query = '',
    filters: Record<string, string> = {},
    active = true,
    onItems?: (items: T[]) => void,
    getKey: (item: T) => string = (item) => item.id,
) {
    const filterKey = new URLSearchParams(filters).toString();
    const loadItems = useCallback(
        async (request: ListRequest) => {
            const { signal } = request;
            const [resource, existing = ''] = path.split('?');
            const filters = new URLSearchParams(existing);
            new URLSearchParams(filterKey).forEach((value, key) =>
                filters.set(key, value),
            );
            const params = recordListParams(request, filters);
            const result = await api<{ data: T[]; next_cursor: string | null }>(
                `${resource}?${params}`,
                { signal },
            );
            const batch = {
                items: result.data,
                nextCursor: result.next_cursor,
            };
            assertListBatch<T>(batch);

            if (!signal.aborted) {
                onItems?.(result.data);
            }

            return batch;
        },
        [path, filterKey, onItems],
    );

    return useIncrementalList({
        loadItems,
        query,
        active,
        getKey,
    });
}

/** Label pilihan tersimpan dipertahankan, sedangkan isi popup selalu hasil pencarian server. */
export function useRecordOptions<T extends { id: string }>(
    path: string | null,
    label: (item: T) => string,
    selectedId: string | string[] = '',
) {
    const source = path ?? '';
    const [searchState, setSearch] = useState({ source, query: '' });
    const search = searchState.source === source ? searchState.query : '';
    const [known, setKnown] = useState<{ source: string; options: T[] }>({
        source,
        options: [],
    });
    const acceptItems = useCallback(
        (items: T[]) => {
            setKnown((current) => {
                const rows = new Map(
                    (current.source === source ? current.options : []).map(
                        (item) => [item.id, item],
                    ),
                );
                items.forEach((item) => rows.set(item.id, item));

                return { source, options: [...rows.values()] };
            });
        },
        [source],
    );
    const list = useRecordList<T>(
        source,
        search,
        {},
        Boolean(path),
        acceptItems,
    );
    const selectedKey = JSON.stringify(
        typeof selectedId === 'string'
            ? selectedId
                ? [selectedId]
                : []
            : selectedId,
    );
    useEffect(() => {
        const ids = JSON.parse(selectedKey) as string[];

        if (!path || !ids.length) {
            return;
        }

        const [resource, query = ''] = path.split('?');
        const request = new AbortController();
        const resolve = async () => {
            for (let offset = 0; offset < ids.length; offset += 100) {
                const params = new URLSearchParams(query);
                params.set('per_page', '100');
                params.delete('q');
                params.set('aktif', '');
                ids.slice(offset, offset + 100).forEach((id) =>
                    params.append('record_ids[]', id),
                );
                const result = await api<{ data: T[] }>(
                    `${resource}?${params}`,
                    { signal: request.signal },
                );

                if (request.signal.aborted) {
                    return;
                }

                setKnown((current) => {
                    const rows = new Map(
                        (current.source === source ? current.options : []).map(
                            (item) => [item.id, item],
                        ),
                    );
                    result.data.forEach((item) => rows.set(item.id, item));

                    return { source, options: [...rows.values()] };
                });
            }
        };
        void resolve().catch(() => undefined);

        return () => request.abort();
    }, [path, selectedKey, source]);
    const options = known.source === source ? known.options : [];

    return {
        options,
        error: list.error,
        loading: list.loading,
        reload: list.reload,
        selectProps: {
            incremental: list,
            searchValue: search,
            onSearchChange: (query: string) => setSearch({ source, query }),
            visibleValues: list.items.flatMap((item) => [item.id, label(item)]),
            knownValues: options.flatMap((item) => [item.id, label(item)]),
        },
    };
}
