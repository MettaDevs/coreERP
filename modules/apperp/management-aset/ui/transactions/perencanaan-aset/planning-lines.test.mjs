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
const { createPlanningLine, clearPlanningAssets } = await import(
    `data:text/javascript;base64,${Buffer.from(outputText).toString('base64')}`
);

test('a new planning line has one asset lookup and no preselected asset', () => {
    const line = createPlanningLine('line-1');
    assert.equal(line.planned_asset_id, '');
    assert.equal(line.quantity, '1');
    assert.equal(line.estimated_unit_price, null);
    assert.equal('reference_asset_id' in line, false);
    assert.equal('jenis_aset_id' in line, false);
});

test('changing company clears only selected assets and retains the rest of the draft', () => {
    const line = {
        ...createPlanningLine('line-1'),
        planned_asset_id: 'asset-1',
        planned_asset_code: 'A1',
        nama_aset: 'Asset',
        requested_specification: 'Specification',
        quantity: '5',
    };
    const [cleared] = clearPlanningAssets([line]);
    assert.equal(cleared.planned_asset_id, '');
    assert.equal(cleared.planned_asset_code, null);
    assert.equal(cleared.nama_aset, undefined);
    assert.equal(cleared.requested_specification, 'Specification');
    assert.equal(cleared.quantity, '5');
    assert.equal(cleared.lineKey, line.lineKey);
    assert.equal(line.planned_asset_id, 'asset-1');
});
