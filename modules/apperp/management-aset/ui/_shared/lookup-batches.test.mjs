import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';

const server = await createServer({
    root: fileURLToPath(new URL('../../../../../packages/ui', import.meta.url)),
    configFile: false,
    server: { middlewareMode: true },
});

try {
    const { loadMatchingBatch } = await server.ssrLoadModule(
        fileURLToPath(new URL('./lookup-batches.ts', import.meta.url)),
    );
    const { assertListBatch } = await server.ssrLoadModule(
        '/src/components/use-incremental-list.ts',
    );
    const calls = [];
    const batches = new Map([
        [undefined, { items: [{ id: 'selected' }], nextCursor: 'two' }],
        ['two', { items: [{ id: 'eligible' }], nextCursor: 'three' }],
    ]);
    const request = {
        query: 'asset',
        cursor: undefined,
        signal: new AbortController().signal,
    };
    const result = await loadMatchingBatch(
        async (next) => {
            calls.push([next.query, next.cursor]);
            const batch = batches.get(next.cursor);
            assertListBatch(batch);

            return batch;
        },
        request,
        (row) => row.id !== 'selected',
    );
    assert.deepEqual(result, {
        items: [{ id: 'eligible' }],
        nextCursor: 'three',
    });
    assert.deepEqual(calls, [
        ['asset', undefined],
        ['asset', 'two'],
    ]);
    assertListBatch(result);
    const empty = await loadMatchingBatch(
        async () => ({ items: [{ id: 'hidden' }], nextCursor: null }),
        request,
        () => false,
    );
    assertListBatch(empty);
    assert.deepEqual(empty.items, []);
    await assert.rejects(
        () =>
            loadMatchingBatch(
                async () => ({
                    items: [{ id: 'hidden' }],
                    nextCursor: 'repeat',
                }),
                request,
                () => false,
            ),
        /mengulang posisi/,
    );
    const cancelled = new AbortController();
    cancelled.abort();
    await assert.rejects(
        () =>
            loadMatchingBatch(
                async () => {
                    throw new Error('Must not load');
                },
                { ...request, signal: cancelled.signal },
                () => true,
            ),
        { name: 'AbortError' },
    );
    console.log(
        'Lookup batches: hidden first batch, later eligible record, complete exclusion, cursor loop, cancellation passed.',
    );
} finally {
    await server.close();
}
