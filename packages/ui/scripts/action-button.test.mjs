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
    const { ActionButton } = await server.ssrLoadModule(
        '/src/components/action-button.tsx',
    );

    for (const [action, label] of Object.entries({
        create: 'Baru',
        add: 'Tambah',
        edit: 'Ubah',
        archive: 'Delete',
        delete: 'Delete',
        save: 'Simpan',
        cancel: 'Batal',
    })) {
        const html = renderToStaticMarkup(
            React.createElement(ActionButton, { action, type: 'button' }),
        );
        assert.ok(
            html.includes(label),
            `${action} must retain its shared label`,
        );
        assert.equal((html.match(/<svg\b/g) ?? []).length, 1);
        assert.match(html, /data-variant="ghost"/);
        assert.match(html, /type="button"/);
    }

    const cancel = renderToStaticMarkup(
        React.createElement(ActionButton, { action: 'cancel' }),
    );
    assert.match(cancel, /type="button"/);

    const attach = renderToStaticMarkup(
        React.createElement(ActionButton, {
            action: 'add',
            type: 'submit',
            form: 'placement-form',
        }),
    );
    assert.match(attach, /Tambah/);
    assert.match(attach, /form="placement-form"/);
    assert.match(attach, /type="submit"/);

    const submit = renderToStaticMarkup(
        React.createElement(ActionButton, {
            action: 'save',
            type: 'submit',
            form: 'record-form',
            pending: true,
        }),
    );
    assert.match(submit, /type="submit"/);
    assert.match(submit, /form="record-form"/);
    assert.match(submit, /disabled=""/);
    assert.match(submit, /aria-busy="true"/);
    assert.match(submit, /Menyimpan…/);
    assert.equal((submit.match(/<svg\b/g) ?? []).length, 1);
    console.log(
        'Record commands: canonical labels, one icon, inline appearance, native submit association, and pending state passed.',
    );
} finally {
    await server.close();
}
