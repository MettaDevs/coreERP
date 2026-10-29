import assert from 'node:assert/strict';
import process from 'node:process';
import { mock } from 'node:test';
import { fileURLToPath, pathToFileURL } from 'node:url';
import React from 'react';
import { createServer } from 'vite';

if (!process.env.COREERP_DOM_TEST_RUNTIME) {
    throw new Error('Set COREERP_DOM_TEST_RUNTIME to jsdom/lib/api.js.');
}

const { JSDOM } = await import(
    pathToFileURL(process.env.COREERP_DOM_TEST_RUNTIME).href
);
const dom = new JSDOM('<html><body><div id="root"></div></body></html>', {
    url: 'http://vendor-test.invalid',
});

for (const name of [
    'window',
    'document',
    'HTMLElement',
    'HTMLInputElement',
    'Node',
    'Event',
    'CustomEvent',
    'MouseEvent',
]) {
    globalThis[name] = dom.window[name];
}

window.performance.getEntriesByType = () => [];
globalThis.IS_REACT_ACT_ENVIRONMENT = true;
const { createRoot } = await import('react-dom/client');
const { router } = await import('@inertiajs/react');
const originalGet = router.get;
const originalCancelAll = router.cancelAll;
const requests = [];
let allCancelled = 0;
router.cancelAll = () => {
    allCancelled++;

    for (const request of requests) {
        request.token.cancel();
    }
};
router.get = (url, data, options) => {
    const request = { url, data, options, cancelled: false };
    request.token = {
        cancel() {
            if (!request.cancelled) {
                request.cancelled = true;
                options.onFinish?.();
            }
        },
    };
    requests.push(request);
    options.onCancelToken(request.token);
};
const server = await createServer({
    root: fileURLToPath(new URL('../../../../..', import.meta.url)),
    configFile: false,
    server: { middlewareMode: true, hmr: false },
    optimizeDeps: { noDiscovery: true },
    appType: 'custom',
});
const { useVendorListQuery } = await server.ssrLoadModule(
    '/resources/js/pages/foundation/vendor/use-vendor-list-query.ts',
);
let current;
function Probe({ filters, paused }) {
    current = useVendorListQuery(filters, paused);

    return React.createElement('output', null, current.query);
}
mock.timers.enable({ apis: ['setTimeout'] });
const root = createRoot(document.getElementById('root'));
const filters = { q: '', legal_entity: 'company-allowed', status: null };
const render = async (value = filters, paused = false) =>
    React.act(async () =>
        root.render(React.createElement(Probe, { filters: value, paused })),
    );
const change = async (value) =>
    React.act(async () => current.onQueryChange(value));
const wait = async (ms) => React.act(async () => mock.timers.tick(ms));

try {
    await render();
    await wait(280);
    assert.equal(
        requests.length,
        0,
        'initial query already supplied by server',
    );
    await change('ven');
    await wait(80);
    await change('vendor');
    await wait(80);
    await change('vendor newest');
    await wait(280);
    assert.equal(requests.length, 1, 'rapid typing sends only settled query');
    assert.equal(requests[0].data.q, 'vendor newest');
    assert.equal(requests[0].data.legal_entity, filters.legal_entity);
    assert.deepEqual(requests[0].options.reset, ['vendors']);
    assert.equal(requests[0].options.preserveState, true);
    assert.equal(requests[0].options.replace, true);
    await change('vendor newest extra');
    assert.equal(
        requests[0].cancelled,
        true,
        'typing cancels current native visit immediately',
    );
    await render({ ...filters, q: 'vendor newest' });
    assert.equal(
        current.query,
        'vendor newest extra',
        'older own query echo cannot overwrite newer typing',
    );
    await wait(280);
    assert.equal(requests.at(-1).data.q, 'vendor newest extra');
    requests.at(-1).options.onFinish();
    await render({ ...filters, q: 'vendor newest extra' });
    await change('pending');
    await wait(40);
    const beforeFilter = requests.length;
    await React.act(async () => current.applyFilters({ status: 'inactive' }));
    assert.equal(
        requests.length,
        beforeFilter + 1,
        'company/status filter applies immediately',
    );
    assert.equal(requests.at(-1).data.q, 'pending');
    assert.equal(requests.at(-1).data.status, 'inactive');
    await wait(280);
    assert.equal(
        requests.length,
        beforeFilter + 1,
        'filter flushes pending timer without duplicate',
    );
    const applied = { ...filters, q: 'pending', status: 'inactive' };
    requests.at(-1).options.onFinish();
    await render(applied);
    await change('');
    await wait(35);
    assert.equal(requests.at(-1).data.q, '', 'clearing search is immediate');
    assert.equal(requests.at(-1).data.status, 'inactive');
    const cleared = { ...applied, q: '' };
    requests.at(-1).options.onFinish();
    await render(cleared);
    await React.act(async () => current.applyFilters({ status: 'active' }));
    const manualWhileOpeningCard = requests.at(-1);
    await render(cleared, true);
    assert.equal(
        manualWhileOpeningCard.cancelled,
        true,
        'opening card also cancels an immediate filter visit',
    );
    await render(cleared, false);
    await change('paused draft');
    const beforePause = requests.length;
    await render(cleared, true);
    await wait(280);
    assert.equal(
        requests.length,
        beforePause,
        'card pause cancels pending search',
    );
    await render(cleared, false);
    await wait(280);
    assert.equal(
        requests.at(-1).data.q,
        'paused draft',
        'closing card resumes pending search',
    );
    await React.act(async () => window.dispatchEvent(new Event('popstate')));
    await render({ ...filters, q: 'history query' });
    assert.equal(
        current.query,
        'history query',
        'history restores query supplied by page',
    );
    await change('discard on unmount');
    const beforeUnmount = requests.length;
    await React.act(async () => root.unmount());
    await wait(280);
    assert.equal(requests.length, beforeUnmount, 'unmount cancels debounce');
    assert.ok(
        allCancelled > 0,
        'native cancellation includes in-flight pagination visits',
    );
    console.log(
        'Vendor live search: debounce, immediate clear/filter, native cancellation, stale echo, pause, history, and cleanup passed.',
    );
} finally {
    router.get = originalGet;
    router.cancelAll = originalCancelAll;
    await React.act(async () => root.unmount());
    mock.timers.reset();
    await server.close();
    dom.window.close();
}
