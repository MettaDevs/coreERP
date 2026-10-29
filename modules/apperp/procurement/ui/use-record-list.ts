import { useCallback } from 'react';
import {
    assertListBatch,
    useIncrementalList,
} from '@apperp/ui/use-incremental-list';
import type { ListRequest } from '@apperp/ui/use-incremental-list';
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

/**
 * Daftar module Procurement dengan pemuatan bertahap (cursor/incremental)
 * dan pembatalan request lama melalui AbortSignal.
 */
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
            const filterParams = new URLSearchParams(existing);
            new URLSearchParams(filterKey).forEach((value, key) =>
                filterParams.set(key, value),
            );
            const params = recordListParams(request, filterParams);
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
