import type { LookupLoader } from '@apperp/ui/inline-lookup';
import { apiJson } from './core-api';

/** Cursor API disimpan internal; layar lookup hanya menampilkan scroll bertahap. */
export async function loadLookupItems<T>(
    path: string,
    request: Parameters<LookupLoader<T>>[0],
) {
    const url = new URL(path, window.location.origin);
    url.searchParams.set('q', request.query);

    if (request.cursor) {
        url.searchParams.set('cursor', request.cursor);
    }

    const result = await apiJson<{ data: T[]; next_cursor: string | null }>(
        `${url.pathname}${url.search}`,
        { signal: request.signal },
    );

    return { items: result.data, nextCursor: result.next_cursor };
}
