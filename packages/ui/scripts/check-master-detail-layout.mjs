import assert from 'node:assert/strict';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { createServer } from 'vite';
import { fileURLToPath } from 'node:url';

// Memakai compiler aplikasi tanpa membuka browser atau port HTTP.
const server = await createServer({
    root: fileURLToPath(new URL('../', import.meta.url)),
    configFile: false,
    server: { middlewareMode: true, hmr: false },
    optimizeDeps: { noDiscovery: true },
    appType: 'custom',
});
try {
    const { MasterDetailLayout } = await server.ssrLoadModule(
        '/src/components/master-detail-layout.tsx',
    );
    const layout = (detailOpen) =>
        React.createElement(
            MasterDetailLayout,
            {
                detailOpen,
                onDetailOpenChange: () => {},
                list: React.createElement('input', {
                    'data-probe': 'list-search',
                    defaultValue: 'search retained',
                }),
            },
            React.createElement('textarea', {
                'data-probe': 'detail-draft',
                defaultValue: 'draft retained',
            }),
        );

    for (const open of [false, true]) {
        const html = renderToStaticMarkup(layout(open));
        assert.match(html, /data-probe="list-search"/);
        assert.match(html, /data-probe="detail-draft"/);
        assert.match(html, /draft retained/);
        assert.match(html, /data-slot="resizable-handle"/);
        assert.doesNotMatch(html, /lucide-grip-vertical/);
        assert.equal(html.includes('>Daftar</button>'), open);
    }
    const together = renderToStaticMarkup(
        React.createElement(React.Fragment, null, layout(false), layout(true)),
    );
    const ids = [...together.matchAll(/ id="([^"]+)"/g)].map(
        (match) => match[1],
    );
    assert.equal(
        new Set(ids).size,
        ids.length,
        'Two master-detail instances must not share panel IDs.',
    );
    console.log(
        'Master-detail: both panes retained, plain divider, server rendering, independent panel IDs, and back action passed.',
    );
} finally {
    await server.close();
}
