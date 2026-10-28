import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import ts from 'typescript';

const source = ts.createSourceFile(
    'use-address-books.ts',
    fs.readFileSync(
        new URL(
            '../apps/core/resources/js/pages/platform/address-book/components/use-address-books.ts',
            import.meta.url,
        ),
        'utf8',
    ),
    ts.ScriptTarget.Latest,
    true,
    ts.ScriptKind.TS,
);
const merge = source.statements.find(
    (node) =>
        ts.isFunctionDeclaration(node) &&
        node.name?.text === 'mergeAddressBooks',
);
assert.ok(merge);
const context = { exports: {} };
vm.runInNewContext(
    ts.transpileModule(merge.getText(source), {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    }).outputText,
    context,
);
const { mergeAddressBooks } = context.exports;
const selected = { id: 'selected', name: 'Nama lama', version: 1 };
const renamed = { ...selected, name: 'Nama baru', version: 3 };
const archived = {
    id: 'archived',
    name: 'Tetap terbaca',
    version: 4,
    deleted_at: '2026-10-27',
};
const fresh = mergeAddressBooks([selected, archived], [renamed]);
assert.equal(fresh.find((book) => book.id === selected.id).name, renamed.name);
assert.equal(
    fresh.find((book) => book.id === archived.id).deleted_at,
    archived.deleted_at,
);
const oldCache = mergeAddressBooks(
    [archived],
    [{ ...archived, name: 'Cache lama', version: 1, deleted_at: null }],
);
assert.equal(oldCache[0].deleted_at, archived.deleted_at);
assert.equal(oldCache[0].name, archived.name);
assert.equal(mergeAddressBooks([selected], [renamed, renamed]).length, 1);
console.log(
    'PASS address book options: fresh labels replace stale selected metadata, archived references remain, older caches cannot overwrite newer records.',
);
