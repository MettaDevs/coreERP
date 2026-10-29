import assert from 'node:assert/strict';
import { Buffer } from 'node:buffer';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import ts from 'typescript';

const source = await readFile(
    new URL('./planning-lines.ts', import.meta.url),
    'utf8',
);
const { outputText } = ts.transpileModule(source, {
    compilerOptions: {
        module: ts.ModuleKind.ESNext,
        target: ts.ScriptTarget.ES2022,
    },
});
const { appendPlanningTypes, createPlanningLine } = await import(
    `data:text/javascript;base64,${Buffer.from(outputText).toString('base64')}`
);
const first = { id: 'type-1', kode: 'ONE', nama: 'Jenis satu' };
const second = { id: 'type-2', kode: 'TWO', nama: 'Jenis dua' };
let nextKey = 0;
const createLine = () => createPlanningLine(`new-${++nextKey}`);

test('bulk selection fills a blank row, retains its key and leaves required choices for the user', () => {
    const original = [createPlanningLine('blank')];
    const result = appendPlanningTypes(original, [first, second], createLine);
    assert.equal(result.length, 2);
    assert.equal(result[0].lineKey, 'blank');
    assert.deepEqual(
        result.map((line) => line.jenis_aset_id),
        ['type-1', 'type-2'],
    );

    for (const line of result) {
        assert.equal(line.quantity, '1');
        assert.equal(line.satuan_id, '');
        assert.equal(line.needed_on, null);
        assert.equal(line.funding_source_id, null);
        assert.equal(line.estimated_unit_price, null);
    }

    assert.equal(original[0].jenis_aset_id, '');
});

test('bulk selection preserves partly edited and saved rows, including an explicit zero estimate', () => {
    const original = [
        {
            ...createPlanningLine('spec'),
            requested_specification: 'Keep my specification',
        },
        { ...createPlanningLine('zero'), estimated_unit_price: '0' },
        { ...createPlanningLine('saved'), id: 'saved-id' },
        { ...createPlanningLine('quantity'), quantity: '2' },
    ];
    const result = appendPlanningTypes(original, [first], createLine);
    assert.equal(result.length, 5);
    original.forEach((line, index) => assert.equal(result[index], line));
    assert.equal(result[4].jenis_aset_id, first.id);
});

test('the chooser deduplicates this batch but permits the same type for another requirement', () => {
    const original = [
        {
            ...createPlanningLine('existing'),
            jenis_aset_id: first.id,
            requested_specification: 'Different requirement',
        },
    ];
    const result = appendPlanningTypes(
        original,
        [first, first, second],
        createLine,
    );
    assert.equal(result.length, 3);
    assert.equal(result[0], original[0]);
    assert.deepEqual(
        result.map((line) => line.jenis_aset_id),
        [first.id, first.id, second.id],
    );
    assert.equal(appendPlanningTypes(original, [], createLine), original);
});
