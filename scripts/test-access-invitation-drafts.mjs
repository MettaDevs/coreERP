import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import ts from 'typescript';

const file = new URL(
    '../apps/core/resources/js/pages/platform/access/access.tsx',
    import.meta.url,
);
const source = ts.createSourceFile(
    file.pathname,
    readFileSync(file, 'utf8'),
    ts.ScriptTarget.Latest,
    true,
    ts.ScriptKind.TSX,
);
const required = new Set([
    'grantKey',
    'issuedDraft',
    'codeFingerprint',
    'mergeIssuedRows',
]);
const declarations = [];
for (const statement of source.statements) {
    if (
        ts.isFunctionDeclaration(statement) &&
        required.has(statement.name?.text)
    ) {
        declarations.push(statement.getText(source));
        required.delete(statement.name.text);
    }
    if (ts.isVariableStatement(statement)) {
        for (const declaration of statement.declarationList.declarations) {
            if (required.has(declaration.name.getText(source))) {
                declarations.push(`const ${declaration.getText(source)};`);
                required.delete(declaration.name.getText(source));
            }
        }
    }
}
const callbacks = new Set(['update', 'save', 'resend', 'refreshIssued']);
function visit(node) {
    if (ts.isFunctionDeclaration(node) && node.name?.text === 'InviteForm') {
        for (const statement of node.body.statements) {
            if (!ts.isVariableStatement(statement)) continue;
            for (const declaration of statement.declarationList.declarations) {
                if (callbacks.has(declaration.name.getText(source))) {
                    declarations.push(`const ${declaration.getText(source)};`);
                    callbacks.delete(declaration.name.getText(source));
                }
            }
        }
    }
    ts.forEachChild(node, visit);
}
visit(source);
assert.equal(callbacks.size, 0, 'Actual write/update callbacks must exist');
assert.equal(required.size, 0, 'Actual draft merge helpers must exist');
const context = vm.createContext({});
vm.runInContext(
    ts.transpileModule(
        declarations.join('\n') +
            '\nglobalThis.api = { issuedDraft, codeFingerprint, mergeIssuedRows, update, save, resend, refreshIssued };',
        { compilerOptions: { target: ts.ScriptTarget.ES2022 } },
    ).outputText,
    context,
);
const { issuedDraft, codeFingerprint, mergeIssuedRows } = context.api;
const invitation = (id, version = 1) => ({
    id,
    version,
    label: `Row ${id}`,
    assignments: [],
    revoked_at: null,
    redeemed_count: 0,
    sso: null,
});
const rows = [invitation('first'), invitation('off-first-batch')].map(
    issuedDraft,
);
const baseline = new Map(rows.map((row) => [row.key, codeFingerprint(row)]));
rows[0] = { ...rows[0], label: 'Unsaved draft' };
let merged = mergeIssuedRows(
    rows,
    [invitation('off-first-batch', 2), invitation('next-batch')],
    baseline,
);
assert.equal(
    merged.rows[0].label,
    'Unsaved draft',
    'Paging must preserve another row draft',
);
assert.equal(merged.rows[1].issued.version, 2);
assert.equal(merged.rows.length, 3, 'Paging appends new rows');
assert.notEqual(
    merged.fingerprints.get(rows[0].key),
    codeFingerprint(merged.rows[0]),
    'Paging must not mark an unsaved draft pristine',
);
merged = mergeIssuedRows(
    merged.rows,
    [invitation('off-first-batch', 3)],
    merged.fingerprints,
    'issued-off-first-batch',
);
assert.equal(
    merged.rows[0].label,
    'Unsaved draft',
    'Saving off-first-batch must preserve other drafts',
);
assert.equal(merged.rows[1].issued.version, 3);
merged = mergeIssuedRows(
    merged.rows,
    [invitation('off-first-batch', 1)],
    merged.fingerprints,
);
assert.equal(
    merged.rows[1].issued.version,
    3,
    'Stale props must not roll the saved version back',
);
assert.equal(
    merged.rows.length,
    3,
    'Partial responses must not truncate loaded rows',
);
merged = mergeIssuedRows(
    merged.rows,
    [{ ...invitation('first', 2), label: 'Saved label' }],
    merged.fingerprints,
    'issued-first',
);
assert.equal(merged.rows[0].label, 'Saved label');
assert.equal(
    merged.fingerprints.get('issued-first'),
    codeFingerprint(merged.rows[0]),
    'Only saved row becomes pristine',
);
console.log(
    'PASS invitation paging, off-first-batch save and dirty-row preservation',
);
let currentRows = [issuedDraft(invitation('pending'))];
let currentState = {
    rows: currentRows,
    fingerprints: new Map(
        currentRows.map((row) => [row.key, codeFingerprint(row)]),
    ),
};
let writes = 0;
let options;
let resolveRefresh;
Object.assign(context, {
    writeInFlight: { current: false },
    form: { processing: false, data: { codes: [] }, setData: () => {} },
    setSaving: () => {},
    setRefreshError: () => {},
    toast: () => {},
    reportIssuedErrors: () => {},
    invitationList: { reload: () => {} },
    withInertiaErrors: (value) => value,
    setIssuedRows: (update) => {
        currentRows = update(currentRows);
    },
    setIssuedState: (update) => {
        currentState = update(currentState);
        currentRows = currentState.rows;
    },
    accessBatch: () =>
        new Promise((resolve) => {
            resolveRefresh = resolve;
        }),
    router: {
        patch: (_url, _body, value) => {
            writes++;
            options = value;
        },
        post: (_url, _body, value) => {
            writes++;
            options = value;
        },
    },
});
const submitted = currentRows[0];
context.api.save(submitted);
context.api.save(submitted);
context.api.resend(submitted);
assert.equal(
    writes,
    1,
    'Same-event duplicate writes must be blocked before a React render',
);
context.api.update(submitted.key, { label: 'Typed during PATCH' });
context.api.update(submitted.key, {
    assignments: [{ role_id: 'changed-from-popup', policy_scopes: [] }],
});
assert.equal(
    currentRows[0].label,
    submitted.label,
    'Input callbacks must reject edits during PATCH',
);
assert.equal(
    currentRows[0].assignments.length,
    0,
    'Role/scope popup callbacks use the same busy guard',
);
const refresh = options.onSuccess();
context.api.update(submitted.key, { label: 'Typed during record refresh' });
assert.equal(
    context.writeInFlight.current,
    true,
    'Busy must continue through asynchronous record refresh',
);
assert.equal(currentRows[0].label, submitted.label);
resolveRefresh({ items: [invitation('pending', 2)], nextCursor: null });
await refresh;
options.onFinish();
context.api.update(submitted.key, { label: 'Typed after completion' });
assert.equal(currentRows[0].label, 'Typed after completion');
assert.equal(currentRows[0].issued.version, 2);
console.log(
    'PASS actual update/save/resend callbacks block edits and duplicate writes through refresh',
);
