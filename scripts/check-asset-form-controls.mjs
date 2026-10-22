import fs from 'node:fs';
import path from 'node:path';
import ts from 'typescript';

const root = process.argv[2] ?? 'modules/apperp/management-aset/ui';
const files = [];
function discover(directory) {
    for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
        const target = path.join(directory, entry.name);

        if (entry.isDirectory()) {
            discover(target);
        } else if (entry.name.endsWith('.tsx')) {
            files.push(target);
        }
    }
}
discover(root);
const issues = [];
const counts = {};

for (const file of files) {
    const source = ts.createSourceFile(
        file,
        fs.readFileSync(file, 'utf8'),
        ts.ScriptTarget.Latest,
        true,
        ts.ScriptKind.TSX,
    );
    function report(node, message) {
        issues.push({
            file,
            line:
                source.getLineAndCharacterOfPosition(node.getStart()).line + 1,
            message,
        });
    }
    function visit(node) {
        if (ts.isJsxOpeningElement(node) || ts.isJsxSelfClosingElement(node)) {
            const name = node.tagName.getText(source);
            counts[name] = (counts[name] ?? 0) + 1;

            if (['select', 'NativeSelect', 'FieldGroup'].includes(name)) {
                report(
                    node,
                    'Gunakan kontrol SDK dan FormGrid, bukan susunan form lama.',
                );
            }

            for (const attribute of node.attributes.properties) {
                if (
                    ts.isJsxAttribute(attribute) &&
                    attribute.name.getText(source) === 'type' &&
                    ts.isStringLiteral(attribute.initializer) &&
                    ['date', 'datetime-local'].includes(
                        attribute.initializer.text,
                    )
                ) {
                    report(node, 'Gunakan DatePicker atau DateTimePicker SDK.');
                }

                if (
                    name === 'Select' &&
                    ts.isJsxSpreadAttribute(attribute) &&
                    attribute.expression.getText(source).includes('selectProps')
                ) {
                    report(
                        node,
                        'Referensi record memakai RecordLookup, bukan Select.',
                    );
                }

                if (
                    name === 'Select' &&
                    ts.isJsxAttribute(attribute) &&
                    attribute.name.getText(source) === 'items' &&
                    attribute.getText(source).includes('.options')
                ) {
                    report(
                        node,
                        'Referensi record memakai RecordLookup; Select hanya pilihan tetap.',
                    );
                }
            }
        }

        ts.forEachChild(node, visit);
    }
    visit(source);
}

const result = { files: files.length, counts, issues };
fs.mkdirSync('artifacts/ui-refresh', { recursive: true });
fs.writeFileSync(
    'artifacts/ui-refresh/asset-form-control-audit.json',
    JSON.stringify(result, null, 2),
);

for (const issue of issues) {
    console.error(`${issue.file}:${issue.line}: ${issue.message}`);
}

console.log(
    `Asset forms: ${files.length} files, ${counts.RecordLookup ?? 0} RecordLookup, ${counts.FormControl ?? 0} FormControl, ${issues.length} legacy violations.`,
);
process.exitCode = issues.length ? 1 : 0;
