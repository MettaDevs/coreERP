import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';

const server = await createServer({
    root: fileURLToPath(new URL('../', import.meta.url)),
    configFile: false,
    server: { middlewareMode: true, hmr: false },
    optimizeDeps: { noDiscovery: true },
    appType: 'custom',
});

try {
    const {
        matchesRecordSearch,
        recordSearchTerms,
        filterRecordSearchItem,
        SEARCH_DEBOUNCE_MS,
        SEARCH_MAX_LENGTH,
    } = await server.ssrLoadModule('/src/components/record-search.ts');
    const fixtures = JSON.parse(
        readFileSync(
            new URL('../tests/fixtures/record-search.json', import.meta.url),
            'utf8',
        ),
    );

    for (const fixture of fixtures) {
        assert.equal(
            matchesRecordSearch(fixture.query, fixture.fields),
            fixture.matches,
            fixture.name,
        );
    }

    assert.equal(SEARCH_DEBOUNCE_MS, 250);
    assert.equal(SEARCH_MAX_LENGTH, 200);
    assert.deepEqual(recordSearchTerms('sur "jaya abadi" "open tail'), [
        { phrase: false, text: 'sur' },
        { phrase: true, text: 'jaya abadi' },
        { phrase: false, text: 'open' },
        { phrase: false, text: 'tail' },
    ]);
    assert.equal(
        filterRecordSearchItem(
            { label: 'Surya Jaya', value: 'opaque' },
            'ja sur',
        ),
        true,
    );
    assert.equal(
        filterRecordSearchItem(
            { caption: 'Surya Jaya' },
            'ja sur',
            (item) => item.caption,
        ),
        true,
    );
    assert.equal(filterRecordSearchItem('Surya Jaya', 'urya'), false);
    console.log(
        `Record search: ${fixtures.length} shared matching fixtures, tolerant query parsing, and label adapters passed.`,
    );
} finally {
    await server.close();
}
