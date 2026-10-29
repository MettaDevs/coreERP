import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { createServer } from 'vite';

const server = await createServer({
    root: fileURLToPath(new URL('../', import.meta.url)),
    configFile: false,
    server: { middlewareMode: true, hmr: false },
    optimizeDeps: { noDiscovery: true },
    appType: 'custom',
});

try {
    const { FormControl, FormGrid } = await server.ssrLoadModule(
        '/src/components/form-control.tsx',
    );
    const render = (props) =>
        renderToStaticMarkup(
            React.createElement(
                FormControl,
                props,
                React.createElement('input', { name: 'value' }),
            ),
        );
    const plain = render({});
    assert.match(plain, /data-slot="form-control-help" aria-hidden="true"/);
    assert.doesNotMatch(plain, /<button/);
    const helped = render({
        hint: 'Bantuan opsional',
        error: 'Nilai belum valid',
    });
    assert.match(helped, /data-slot="form-control-help"/);
    assert.match(helped, /type="button"/);
    assert.match(helped, /role="alert"/);
    assert.match(helped, /Nilai belum valid/);
    assert.doesNotMatch(helped, /Bantuan opsional/);
    assert.equal((helped.match(/name="value"/g) ?? []).length, 1);
    const grid = renderToStaticMarkup(
        React.createElement(FormGrid, {}, [
            React.createElement(
                FormControl,
                { key: 'plain', 'data-field-name': 'plain' },
                React.createElement('input'),
            ),
            React.createElement(
                FormControl,
                { key: 'helped', hint: 'Bantuan' },
                React.createElement('input'),
            ),
        ]),
    );
    assert.match(grid, /data-slot="form-grid"/);
    assert.match(grid, /data-field-name="plain"/);
    assert.equal(
        (grid.match(/data-slot="form-control-help"/g) ?? []).length,
        2,
    );
    const { InlineLookup } = await server.ssrLoadModule(
        '/src/components/inline-lookup.tsx',
    );
    const lookup = renderToStaticMarkup(
        React.createElement(InlineLookup, {
            label: 'Vendor',
            value: { id: 'one', name: 'Vendor pilihan' },
            columns: [
                { id: 'name', header: 'Nama', cell: (item) => item.name },
            ],
            getItemValue: (item) => item.id,
            getItemLabel: (item) => item.name,
            loadItems: async () => ({ items: [], nextCursor: null }),
            onValueChange: () => {},
        }),
    );
    assert.match(
        lookup,
        /<input[^>]*aria-hidden="true"/,
        'Base UI appends its hidden validation input beside the visible control',
    );
    assert.doesNotMatch(
        lookup,
        /space-y-2/,
        'Blanket sibling margins must not let hidden combobox inputs add visual height',
    );
    console.log(
        'FormControl: stable help slot, optional help hidden, errors visible, and non-submit help action passed.',
    );
} finally {
    await server.close();
}
