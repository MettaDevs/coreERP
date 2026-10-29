import assert from 'node:assert/strict';
import process from 'node:process';
import { fileURLToPath, pathToFileURL } from 'node:url';
import React from 'react';
import { createServer } from 'vite';

// Runtime DOM diagnostik disediakan dari luar repo; tidak mengubah dependency SDK.
const runtimePath = process.env.COREERP_DOM_TEST_RUNTIME;

if (!runtimePath) {
    throw new Error(
        'Set COREERP_DOM_TEST_RUNTIME ke berkas jsdom/lib/api.js sebelum menjalankan tes ini.',
    );
}

const { JSDOM } = await import(pathToFileURL(runtimePath).href);
const dom = new JSDOM(
    '<!doctype html><html><body><div id="toolbar"></div><div id="root"></div></body></html>',
    { url: 'http://sdk-test.invalid', pretendToBeVisual: true },
);

for (const name of [
    'window',
    'document',
    'HTMLElement',
    'HTMLButtonElement',
    'HTMLInputElement',
    'Element',
    'Node',
    'NodeFilter',
    'DocumentFragment',
    'MutationObserver',
    'Event',
    'PointerEvent',
    'MouseEvent',
    'KeyboardEvent',
    'CustomEvent',
]) {
    globalThis[name] =
        name === 'window'
            ? dom.window
            : name === 'document'
              ? dom.window.document
              : dom.window[name];
}

globalThis.getComputedStyle = dom.window.getComputedStyle.bind(dom.window);
globalThis.requestAnimationFrame = dom.window.requestAnimationFrame.bind(
    dom.window,
);
globalThis.cancelAnimationFrame = dom.window.cancelAnimationFrame.bind(
    dom.window,
);
globalThis.ResizeObserver = class {
    constructor(callback) {
        this.callback = callback;
    }
    observe() {
        this.callback([]);
    }
    unobserve() {}
    disconnect() {}
};
dom.window.HTMLElement.prototype.hasPointerCapture = () => false;
dom.window.HTMLElement.prototype.setPointerCapture = () => {};
dom.window.HTMLElement.prototype.releasePointerCapture = () => {};
Object.defineProperty(dom.window.HTMLElement.prototype, 'clientWidth', {
    get() {
        return 1200;
    },
});
globalThis.IS_REACT_ACT_ENVIRONMENT = true;
const { createRoot } = await import('react-dom/client');
const server = await createServer({
    root: fileURLToPath(new URL('../', import.meta.url)),
    configFile: false,
    server: { middlewareMode: true, hmr: false },
    optimizeDeps: { noDiscovery: true },
    appType: 'custom',
});
const { Select } = await server.ssrLoadModule('/src/components/select.tsx');
const { useInlineLookupData } = await server.ssrLoadModule(
    '/src/components/use-inline-lookup-data.ts',
);
const { Command, CommandInput, CommandList, CommandItem } =
    await server.ssrLoadModule('/src/components/command.tsx');
const root = createRoot(document.getElementById('root'));
const h = React.createElement;
const tick = () => new Promise((resolve) => setTimeout(resolve, 45));
const click = async (element) =>
    React.act(async () => {
        assert.ok(element, 'expected control exists');
        element.dispatchEvent(
            new PointerEvent('pointerdown', {
                bubbles: true,
                button: 0,
                pointerType: 'mouse',
            }),
        );
        element.dispatchEvent(
            new MouseEvent('mousedown', { bubbles: true, button: 0 }),
        );
        element.click();
        element.dispatchEvent(
            new PointerEvent('pointerup', {
                bubbles: true,
                button: 0,
                pointerType: 'mouse',
            }),
        );
        await tick();
    });
const enter = async (input, value) =>
    React.act(async () => {
        assert.ok(input, 'expected search input exists');
        Object.getOwnPropertyDescriptor(
            HTMLInputElement.prototype,
            'value',
        ).set.call(input, value);
        input.dispatchEvent(new Event('input', { bubbles: true }));
        await tick();
    });
dom.window.HTMLElement.prototype.scrollIntoView = () => {};

try {
    await React.act(async () => {
        root.render(
            h(Select, {
                items: ['Surya Jaya', 'Surya Missing'],
                ariaLabel: 'Pilihan lokal',
            }),
        );
        await tick();
    });
    await click(document.querySelector('[data-slot="select-control"] button'));
    await enter(document.querySelector('input[role="combobox"]'), 'ja sur');
    const options = [...document.querySelectorAll('[role="option"]')].map(
        (item) => item.textContent,
    );
    assert.deepEqual(
        options,
        ['Surya Jaya'],
        'Select uses unordered word-prefix matching',
    );
    await enter(document.querySelector('input[role="combobox"]'), 'urya');
    assert.equal(
        document.querySelectorAll('[role="option"]').length,
        0,
        'Select does not retain substring fallback',
    );
    await React.act(async () => {
        root.render(
            h(Select, {
                key: 'remote',
                items: ['Hasil dari NPWP'],
                ariaLabel: 'Pilihan server',
                searchValue: '12 345',
                incremental: {
                    loading: false,
                    error: '',
                    hasMore: false,
                    loadMore: () => {},
                    retry: () => {},
                },
            }),
        );
        await tick();
    });
    await click(document.querySelector('[data-slot="select-control"] button'));
    assert.deepEqual(
        [...document.querySelectorAll('[role="option"]')].map(
            (item) => item.textContent,
        ),
        ['Hasil dari NPWP'],
        'server results are not filtered again by visible label',
    );
    const localItems = [
        { id: '1', text: 'Surya Jaya' },
        { id: '2', text: 'Surya Missing' },
    ];
    function LookupProbe({ query, loadItems }) {
        const data = useInlineLookupData({
            items: localItems,
            query,
            loadItems,
            active: true,
            getItemValue: (item) => item.id,
            getSearchText: (item) => item.text,
        });

        return h('output', null, data.items.map((item) => item.id).join(','));
    }
    await React.act(async () => {
        root.render(h(LookupProbe, { query: 'ja sur' }));
        await tick();
    });
    assert.equal(
        document.querySelector('output').textContent,
        '1',
        'local lookup shares matching semantics',
    );
    let requested = '';
    const loadItems = async ({ query }) => {
        requested = query;

        return {
            items: [{ id: 'server', text: 'Label lain' }],
            nextCursor: null,
        };
    };
    await React.act(async () => {
        root.render(h(LookupProbe, { query: '12 345', loadItems }));
        await tick();
    });
    await React.act(async () => {
        await new Promise((resolve) => setTimeout(resolve, 300));
    });
    assert.equal(requested, '12 345');
    assert.equal(
        document.querySelector('output').textContent,
        'server',
        'remote lookup bypasses local text matching',
    );
    function Commands() {
        const [query, setQuery] = React.useState('');

        return h(
            Command,
            null,
            h(CommandInput, { value: query, onValueChange: setQuery }),
            h(
                CommandList,
                null,
                h(
                    CommandItem,
                    { value: 'opaque-one', keywords: ['Surya Jaya'] },
                    'Surya Jaya',
                ),
                h(
                    CommandItem,
                    { value: 'opaque-two', keywords: ['Surya Missing'] },
                    'Surya Missing',
                ),
            ),
        );
    }
    await React.act(async () => {
        root.render(h(Commands));
        await tick();
    });
    await enter(document.querySelector('[cmdk-input]'), 'ja sur');
    assert.deepEqual(
        [...document.querySelectorAll('[cmdk-item]')].map(
            (item) => item.textContent,
        ),
        ['Surya Jaya'],
        'command palette uses shared matching',
    );
    await enter(document.querySelector('[cmdk-input]'), 'urya');
    assert.equal(
        document.querySelectorAll('[cmdk-item]').length,
        0,
        'command palette does not apply fuzzy substring fallback',
    );
    await enter(document.querySelector('[cmdk-input]'), 'opaque-one');
    assert.deepEqual(
        [...document.querySelectorAll('[cmdk-item]')].map(
            (item) => item.textContent,
        ),
        ['Surya Jaya'],
        'explicit keywords do not remove the command value from search',
    );
    console.log(
        'Record search interactions: Select, remote bypass, InlineLookup data, and command palette passed.',
    );
} finally {
    await React.act(async () => root.unmount());
    await server.close();
    dom.window.close();
}
