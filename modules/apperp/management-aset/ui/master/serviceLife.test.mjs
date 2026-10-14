import assert from 'node:assert/strict';
import { Buffer } from 'node:buffer';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import ts from 'typescript';

const source = await readFile(
    new URL('./serviceLife.ts', import.meta.url),
    'utf8',
);
const compiled = ts.transpileModule(source, {
    compilerOptions: {
        module: ts.ModuleKind.ESNext,
        target: ts.ScriptTarget.ES2022,
    },
}).outputText;
const {
    changeProfileField,
    monthsPerPeriod,
    serviceLifeLabel,
    serviceLifeMonths,
} = await import(
    `data:text/javascript;base64,${Buffer.from(compiled).toString('base64')}`
);

test('four years stays four years across every posting frequency', () => {
    let values = { frequency: 'monthly', useful_life_periods: 48 };

    for (const [frequency, periods] of [
        ['quarterly', 16],
        ['half_yearly', 8],
        ['yearly', 4],
        ['monthly', 48],
    ]) {
        values = changeProfileField(values, 'frequency', frequency);
        assert.equal(values.useful_life_periods, periods);
        assert.equal(serviceLifeMonths(periods, frequency), 48);
        assert.equal(serviceLifeLabel(periods, frequency), '4 tahun');
    }
});

test('a life that does not fit the selected frequency is never rounded', () => {
    const values = changeProfileField(
        { frequency: 'monthly', useful_life_periods: 49 },
        'frequency',
        'yearly',
    );
    assert.equal(serviceLifeMonths(values.useful_life_periods, 'yearly'), 49);
    assert.equal(Number.isInteger(values.useful_life_periods), false);
    assert.equal(
        serviceLifeLabel(values.useful_life_periods, 'yearly'),
        '4 tahun 1 bulan',
    );
});

test('empty inherited life stays empty when frequency changes', () => {
    const values = changeProfileField(
        { frequency: 'monthly', useful_life_periods: '' },
        'frequency',
        'yearly',
    );
    assert.equal(values.useful_life_periods, '');
    assert.equal(serviceLifeMonths(null, 'monthly'), null);
    assert.equal(serviceLifeMonths('', 'monthly'), null);
});

test('unknown frequency cannot produce an invented duration', () => {
    assert.equal(monthsPerPeriod(undefined), null);
    assert.equal(serviceLifeMonths(48, undefined), null);
    assert.equal(serviceLifeLabel(48, undefined), null);
});

test('other profile edits leave the duration alone', () => {
    const original = {
        frequency: 'monthly',
        useful_life_periods: 48,
        method: 'straight_line',
    };
    assert.deepEqual(
        changeProfileField(original, 'method', 'reducing_balance'),
        { ...original, method: 'reducing_balance' },
    );
    assert.equal(original.method, 'straight_line');
});
