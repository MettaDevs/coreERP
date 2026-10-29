import type { LookupLoader } from '@apperp/ui/inline-lookup';

/** Batch yang habis disaring diteruskan agar pilihan pada cursor berikutnya tetap terjangkau. */
export async function loadMatchingBatch<T>(
    load: LookupLoader<T>,
    request: Parameters<LookupLoader<T>>[0],
    matches: (record: T) => boolean,
) {
    let cursor = request.cursor;
    const visited = new Set<string>();

    if (cursor) {
        visited.add(cursor);
    }

    for (;;) {
        request.signal.throwIfAborted();
        const result = await load({ ...request, cursor });
        request.signal.throwIfAborted();
        const items = result.items.filter(matches);

        if (items.length || !result.nextCursor) {
            return { ...result, items };
        }

        if (visited.has(result.nextCursor)) {
            throw new Error(
                'Daftar pilihan mengulang posisi yang sama. Muat ulang pilihan.',
            );
        }

        visited.add(result.nextCursor);
        cursor = result.nextCursor;
    }
}
