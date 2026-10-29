import assert from 'node:assert/strict';
import { Buffer } from 'node:buffer';
import { readFile } from 'node:fs/promises';
import { ModuleKind, ScriptTarget, transpileModule } from 'typescript';

const source = await readFile(
    new URL('./form-error-scenarios.ts', import.meta.url),
    'utf8',
);
const { outputText } = transpileModule(source, {
    compilerOptions: { module: ModuleKind.ESNext, target: ScriptTarget.ES2022 },
});
const {
    scenarios,
    validateDraft,
    validateLines,
    processLines,
    validateAttachment,
} = await import(
    `data:text/javascript;base64,${Buffer.from(outputText).toString('base64')}`
);

assert.equal(scenarios.length, 11);
assert.ok(scenarios.every((item) => item.value !== 'network'));
assert.equal(new Set(scenarios.map((item) => item.value)).size, 11);
assert.ok(
    scenarios.every(
        (item) =>
            item.action &&
            item.title &&
            item.message &&
            !/[.!?]$/.test(item.title),
    ),
);
assert.deepEqual(
    validateDraft({ reference: '', email: 'invalid', quantity: '0' }).map(
        (issue) => issue.field,
    ),
    ['reference', 'email', 'quantity'],
);
assert.deepEqual(
    validateDraft({
        reference: 'sample',
        email: 'sample@example.test',
        quantity: '2',
    }),
    [],
);
assert.equal(
    validateDraft({
        reference: 'sample',
        email: 'sample@example.test',
        quantity: '1.5',
    })[0].field,
    'quantity',
);
assert.equal(
    validateDraft({
        reference: 'sample',
        email: 'sample@example.test',
        quantity: 'Infinity',
    })[0].field,
    'quantity',
);

const lines = [
    { id: 'a', quantity: '2' },
    { id: 'b', quantity: '0' },
    { id: 'c', quantity: '-1' },
];
assert.deepEqual(
    validateLines(lines).map((issue) => issue.field),
    ['line:b', 'line:c'],
);
const first = processLines(lines, []);
assert.deepEqual(first.processed, ['a']);
assert.deepEqual(
    first.issues.map((issue) => issue.field),
    ['line:b', 'line:c'],
);
const retry = processLines(
    [
        { id: 'a', quantity: '0' },
        { id: 'b', quantity: '4' },
        { id: 'c', quantity: '-1' },
    ],
    first.processed,
);
assert.deepEqual(
    retry.processed,
    ['a', 'b'],
    'successful rows remain successful and are not retried',
);
assert.deepEqual(
    retry.issues.map((issue) => issue.field),
    ['line:c'],
);
const final = processLines(
    [
        { id: 'a', quantity: '0' },
        { id: 'b', quantity: '0' },
        { id: 'c', quantity: '1' },
    ],
    retry.processed,
);
assert.deepEqual(final.processed, ['a', 'b', 'c']);
assert.deepEqual(final.issues, []);
assert.deepEqual(
    processLines(lines, final.processed),
    final,
    'repeating a batch cannot duplicate completed IDs',
);

const limit = 10 * 1024 * 1024;
assert.deepEqual(
    validateAttachment({
        name: 'sample.pdf',
        size: limit,
        type: 'application/pdf',
    }),
    [],
);
assert.equal(
    validateAttachment({
        name: 'sample.pdf',
        size: limit + 1,
        type: 'application/pdf',
    }).length,
    1,
);
assert.equal(
    validateAttachment({
        name: 'sample.exe',
        size: 10,
        type: 'application/octet-stream',
    }).length,
    1,
);
assert.equal(
    validateAttachment({
        name: 'sample.exe',
        size: 10,
        type: 'application/pdf',
    }).length,
    1,
);
assert.deepEqual(
    validateAttachment({ name: 'sample.JPEG', size: 10, type: 'image/jpeg' }),
    [],
);
assert.deepEqual(
    validateAttachment({ name: 'sample.png', size: 10, type: 'image/png' }),
    [],
);
assert.equal(
    validateAttachment({
        name: 'sample.jpg',
        size: limit + 1,
        type: 'application/pdf',
    }).length,
    2,
);
