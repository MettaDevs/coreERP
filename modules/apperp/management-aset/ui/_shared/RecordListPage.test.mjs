import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { rolldown } from 'rolldown';

const repoRoot = fileURLToPath(new URL('../../../../../', import.meta.url));
const temporary = fs.mkdtempSync(
    path.join(repoRoot, 'node_modules', '.record-list-test-'),
);
const outputFile = path.join(temporary, 'render.mjs');
const packageInfo = JSON.parse(
    fs.readFileSync(path.join(repoRoot, 'packages/ui/package.json'), 'utf8'),
);
const bundle = await rolldown({
    input: 'virtual:record-list-test',
    external: [
        ...Object.keys(packageInfo.dependencies),
        'react',
        'react-dom',
        'react/jsx-runtime',
    ],
    transform: { jsx: 'react-jsx' },
    plugins: [
        {
            name: 'record-list-test',
            resolveId: (id) => (id === 'virtual:record-list-test' ? id : null),
            load: (id) =>
                id === 'virtual:record-list-test'
                    ? `export {default as RecordListPage} from ${JSON.stringify(path.join(repoRoot, 'modules/apperp/management-aset/ui/_shared/RecordListPage.tsx').replaceAll('\\', '/'))}; export {DataTable} from ${JSON.stringify(path.join(repoRoot, 'packages/ui/dist/components/data-table.js').replaceAll('\\', '/'))};`
                    : null,
        },
    ],
});

try {
    await bundle.write({
        file: outputFile,
        format: 'esm',
        codeSplitting: false,
    });
    const { RecordListPage, DataTable } = await import(
        pathToFileURL(outputFile).href
    );
    const h = React.createElement;
    const table = h(DataTable, {
        columns: [{ id: 'code', header: 'Kode', cell: (row) => row.code }],
        data: [{ id: '1', code: 'A-001' }],
        getRowKey: (row) => row.id,
    });
    const markup = renderToStaticMarkup(
        h(RecordListPage, {
            toolbar: {
                title: 'Daftar',
                search: {
                    value: 'contoh',
                    onValueChange: () => {},
                    label: 'Cari record',
                },
                children: h('button', { type: 'button' }, 'Baru'),
            },
            filters: h('input', { 'aria-label': 'Status record' }),
            filtersActive: true,
            footer: h('span', null, 'Jumlah record'),
            children: table,
        }),
    );
    assert.match(markup, /data-layout="full-height"/);
    assert.match(markup, /data-seamless-header=""/);
    assert.match(markup, /data-filter-active="true"/);
    assert.match(markup, /aria-label="Tampilkan filter"/);
    assert.doesNotMatch(markup, /aria-label="Status record"/);
    assert.match(markup, /A-001/);
    assert.ok(markup.indexOf('Cari record') < markup.indexOf('>Baru<'));
    assert.ok(
        markup.indexOf('>Baru<') < markup.indexOf('data-slot="data-table"'),
    );
    assert.ok(markup.indexOf('A-001') < markup.indexOf('Jumlah record'));

    const plain = renderToStaticMarkup(
        h(RecordListPage, {
            toolbar: { title: 'Tanpa filter' },
            children: table,
        }),
    );
    assert.doesNotMatch(plain, /aria-label="Tampilkan filter"/);
    assert.match(plain, /data-seamless-header=""/);
    console.log(
        'Record list page: SDK viewport table, toolbar order, optional filter, active indicator, and footer passed.',
    );
} finally {
    await bundle.close();

    if (fs.existsSync(outputFile)) {
        fs.unlinkSync(outputFile);
    }

    fs.rmdirSync(temporary);
}
