import { useIncrementalList } from '@apperp/ui/use-incremental-list';
import type { ListLoader } from '@apperp/ui/use-incremental-list';
import { useCallback, useState } from 'react';
import { apiJson } from '@/lib/core-api';
import type { AddressBook } from '../types';

export function mergeAddressBooks(
    current: AddressBook[],
    incoming: AddressBook[],
): AddressBook[] {
    const records = new Map(current.map((book) => [book.id, book]));

    for (const book of incoming) {
        const previous = records.get(book.id);

        if (!previous || book.version >= previous.version) {
            records.set(book.id, book);
        }
    }

    return [...records.values()];
}

export function useAddressBooks() {
    const [query, setQuery] = useState('');
    const [known, setKnown] = useState<AddressBook[]>([]);
    const loader = useCallback<ListLoader<AddressBook>>(
        async ({ query, cursor, sort, signal }) => {
            const params = new URLSearchParams({ q: query });

            if (cursor) {
                params.set('cursor', cursor);
            }

            if (sort) {
                params.set('sort', sort.columnId);
                params.set('direction', sort.direction);
            }

            const result = await apiJson<{
                data: AddressBook[];
                next_cursor: string | null;
            }>(`/api/v1/address-books?${params}`, { signal });

            if (!signal.aborted) {
                setKnown((current) => mergeAddressBooks(current, result.data));
            }

            return { items: result.data, nextCursor: result.next_cursor };
        },
        [],
    );
    const list = useIncrementalList({
        loadItems: loader,
        query,
        getKey: (book) => book.id,
    });

    return { list, known, query, setQuery };
}
