import assert from 'node:assert/strict';
import { Buffer } from 'node:buffer';
import { readFile } from 'node:fs/promises';
import ts from 'typescript';

const source = await readFile(
    new URL('../apps/core/resources/js/lib/export-watch.ts', import.meta.url),
    'utf8',
);
const ast = ts.createSourceFile(
    'export-watch.ts',
    source,
    ts.ScriptTarget.Latest,
);
const body = ast.statements
    .filter((node) => !ts.isImportDeclaration(node))
    .map((node) => node.getFullText(ast))
    .join('\n');
let response = [];
globalThis.exportWatchTest = {
    toast: { success() {}, error() {} },
    addNotification() {},
    downloadExport: async () => {},
    isActive: (item) => item.status === 'queued',
    listExports: async () => response,
    localStorage: { getItem: () => null, setItem() {} },
    window: { setInterval: () => 1, clearInterval() {} },
};
const { outputText } = ts.transpileModule(
    `const {toast, addNotification, downloadExport, isActive, listExports, localStorage, window} = globalThis.exportWatchTest;\n${body}`,
    {
        compilerOptions: {
            module: ts.ModuleKind.ESNext,
            target: ts.ScriptTarget.ES2022,
        },
    },
);
const watch = await import(
    `data:text/javascript;base64,${Buffer.from(outputText).toString('base64')}`
);
const page = await readFile(
    new URL(
        '../apps/core/resources/js/pages/platform/reporting/exports.tsx',
        import.meta.url,
    ),
    'utf8',
);
const pageAst = ts.createSourceFile(
    'exports.tsx',
    page,
    ts.ScriptTarget.Latest,
    true,
    ts.ScriptKind.TSX,
);
let selector;
function visit(node) {
    if (
        ts.isVariableDeclaration(node) &&
        node.name.getText(pageAst) === 'exports'
    ) {
        selector = node.initializer.getText(pageAst);
    }

    ts.forEachChild(node, visit);
}
visit(pageAst);
assert.ok(selector, 'use the actual page selection expression');
const select = new Function('live', 'initial', `return (${selector});`);
const initial = [
    { id: 'old-export', status: 'done', report_name: 'Fixture', format: 'pdf' },
];
assert.deepEqual(
    select([], initial),
    [],
    'a confirmed empty refresh must not resurrect server props',
);
assert.equal(
    watch.getExports(),
    null,
    'not loaded is distinct from a successful empty result',
);
assert.deepEqual(select(watch.getExports(), initial), initial);
response = initial;
await watch.refreshExports();
assert.deepEqual(select(watch.getExports(), initial), initial);
response = [];
await watch.refreshExports();
assert.deepEqual(
    select(watch.getExports(), initial),
    [],
    'deleting the last export leaves the history empty',
);
watch.trackExport({ id: 'new-export', status: 'queued' });
assert.equal(watch.getExports()[0].id, 'new-export');
delete globalThis.exportWatchTest;
console.log(
    'PASS actual export store + page: uninitialized, populated, emptied, then new export',
);
