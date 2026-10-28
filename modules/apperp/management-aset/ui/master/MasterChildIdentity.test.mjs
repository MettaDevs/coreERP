import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import ts from 'typescript';

function actualCallbacks(file, names, context) {
    const source = ts.createSourceFile(
        file,
        fs.readFileSync(new URL(file, import.meta.url), 'utf8'),
        ts.ScriptTarget.Latest,
        true,
        ts.ScriptKind.TSX,
    );
    const declarations = new Map();
    function visit(node) {
        if (ts.isFunctionDeclaration(node) && names.includes(node.name?.text)) {
            declarations.set(node.name.text, node.getText(source));
        }
        if (
            ts.isVariableDeclaration(node) &&
            names.includes(node.name.getText(source))
        ) {
            declarations.set(
                node.name.getText(source),
                `const ${node.getText(source)};`,
            );
        }
        ts.forEachChild(node, visit);
    }
    visit(source);
    assert.equal(declarations.size, names.length);
    const code = [
        ...declarations.values(),
        ...names.map((name) => `globalThis.${name} = ${name};`),
    ].join('\n');
    vm.runInNewContext(
        ts.transpileModule(code, {
            compilerOptions: { target: ts.ScriptTarget.ES2022 },
        }).outputText,
        context,
    );
    return context;
}

let staged;
const values = {
    values: [
        {
            id: 'persisted-value',
            line_number: 1,
            value: 'Lama',
            result_code: 'none',
        },
    ],
    setValues(next) {
        this.values = next;
    },
    onChange(next) {
        staged = next;
    },
};
values.setValues = (next) => {
    values.values = next;
};
actualCallbacks(
    './detail/MaintenanceChecklistVariableValues.tsx',
    ['changeValues', 'update'],
    values,
);
values.update(0, { value: 'Nama baru', line_number: 9 });
assert.equal(staged.values.values[0].id, 'persisted-value');
assert.equal(staged.values.values[0].line_number, 9);
values.changeValues([
    ...values.values,
    { line_number: 10, value: '', result_code: 'none' },
]);
assert.equal(staged.values.values.length, 2);
assert.equal(Object.hasOwn(staged.values.values[1], 'id'), false);
assert.equal(staged.values.values[1].value, '');
values.changeValues([values.values[1]]);
assert.equal(staged.values.values.length, 1);
assert.equal(
    staged.values.values.some((row) => row.id === 'persisted-value'),
    false,
);

const lines = {
    lines: [
        {
            id: 'persisted-line',
            line_number: 1,
            type: 'text',
            nama: 'Lama',
            instruksi: null,
            wajib: false,
            unit_id: null,
            unit: null,
            min_value: null,
            max_value: null,
            variable_id: null,
            nested_template_id: null,
        },
    ],
    setLines(next) {
        this.lines = next;
    },
    onChange(next) {
        staged = next;
    },
};
lines.setLines = (next) => {
    lines.lines = next;
};
actualCallbacks(
    './detail/MaintenanceChecklistTemplateLines.tsx',
    ['newLine', 'changeLines', 'update'],
    lines,
);
lines.update(0, { nama: 'Nama baru', line_number: 7, type: 'measurement' });
assert.equal(staged.lines.lines[0].id, 'persisted-line');
assert.equal(staged.lines.lines[0].nama, 'Nama baru');
const partial = lines.newLine(8);
assert.equal(Object.hasOwn(partial, 'id'), false);
lines.changeLines([...lines.lines, partial]);
assert.equal(staged.lines.lines.length, 2);
assert.equal(staged.lines.lines[1].nama, '');
lines.changeLines([lines.lines[1]]);
assert.equal(
    staged.lines.lines.some((row) => row.id === 'persisted-line'),
    false,
);
console.log(
    'PASS actual child-editor callbacks: persisted IDs survive renames/renumbering, new rows remain ID-less, partial rows are retained, omitted persisted rows stay omitted.',
);
