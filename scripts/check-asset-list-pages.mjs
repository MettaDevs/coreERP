import fs from 'node:fs';
import path from 'node:path';
import ts from 'typescript';

const root = process.argv[2] ?? 'modules/apperp/management-aset/ui';
const issues = [];
let tableCount = 0;
let pageCount = 0;

function discover(directory) {
    return fs
        .readdirSync(directory, { withFileTypes: true })
        .flatMap((entry) => {
            const target = path.join(directory, entry.name);

            return entry.isDirectory()
                ? discover(target)
                : target.endsWith('.tsx')
                  ? [target]
                  : [];
        });
}

for (const file of discover(root)) {
    const text = fs.readFileSync(file, 'utf8');
    const source = ts.createSourceFile(
        file,
        text,
        ts.ScriptTarget.Latest,
        true,
        ts.ScriptKind.TSX,
    );
    const imports = new Map();
    const elements = [];
    const normalized = file.replaceAll('\\', '/');
    function report(node, message) {
        const line =
            source.getLineAndCharacterOfPosition(node.getStart(source)).line +
            1;
        issues.push(`${file}:${line}: ${message}`);
    }
    function visit(node) {
        if (
            ts.isImportDeclaration(node) &&
            ts.isStringLiteral(node.moduleSpecifier)
        ) {
            const bindings = node.importClause?.namedBindings;

            if (bindings && ts.isNamedImports(bindings)) {
                for (const specifier of bindings.elements) {
                    imports.set(specifier.name.text, {
                        imported: (specifier.propertyName ?? specifier.name)
                            .text,
                        module: node.moduleSpecifier.text,
                    });

                    if (
                        (specifier.propertyName ?? specifier.name).text ===
                            'Table' &&
                        node.moduleSpecifier.text === '@apperp/ui/table'
                    ) {
                        report(
                            node,
                            'Gunakan DataTable SDK; TableRow/TableCell hanya untuk renderRows.',
                        );
                    }
                }
            }
        }

        if (ts.isJsxOpeningElement(node) || ts.isJsxSelfClosingElement(node)) {
            elements.push(node);
        }

        ts.forEachChild(node, visit);
    }
    visit(source);
    const tables = elements.filter(
        (node) =>
            imports.get(node.tagName.getText(source))?.imported === 'DataTable',
    );
    const listPage = elements.some(
        (node) => node.tagName.getText(source) === 'RecordListPage',
    );
    const embeddedPage = /(?:DetailPage|StatusValidationPage)\.tsx$/.test(file);
    const primary =
        tables.length > 0 &&
        !embeddedPage &&
        /(?:Page|PageLayout)\.tsx$/.test(file);

    if (listPage) {
        pageCount++;
    }

    if (primary && !listPage) {
        report(
            tables[0],
            'Daftar utama memakai _shared/RecordListPage, seperti Inventarisasi aset.',
        );
    }

    for (const node of elements) {
        const name = node.tagName.getText(source);

        if (name === 'table') {
            report(node, 'Gunakan DataTable SDK, bukan tabel HTML.');
        }

        if (
            name === 'ListPageLayout' &&
            !normalized.endsWith('/_shared/RecordListPage.tsx')
        ) {
            report(
                node,
                'Pakai RecordListPage agar toolbar, jarak tabel, dan filter kiri tetap bersama.',
            );
        }
    }

    for (const table of tables) {
        tableCount++;

        if (
            imports.get(table.tagName.getText(source)).module !==
            '@apperp/ui/data-table'
        ) {
            report(table, 'Impor DataTable dari @apperp/ui/data-table.');
        }

        for (const attribute of table.attributes.properties) {
            if (!ts.isJsxAttribute(attribute)) {
                continue;
            }

            const name = attribute.name.getText(source);
            const value = attribute.initializer?.getText(source) ?? '';

            if (
                name === 'className' &&
                /\[&|(?:^|\s|["'`])(?:text|font|bg|border|rounded|p[xy]?|h)-/.test(
                    value,
                )
            ) {
                report(
                    attribute,
                    'Gaya tabel milik SDK; className hanya untuk layout.',
                );
            }

            if (
                primary &&
                ((name === 'scrollMode' && value === '"inline"') ||
                    (name === 'showRowNumbers' && value === '{false}') ||
                    (name === 'actionsPlacement' && value === '"trailing"'))
            ) {
                report(
                    attribute,
                    'Daftar utama memakai scroll viewport, nomor baris, dan menu aksi inline SDK.',
                );
            }
        }
    }
}

for (const issue of issues) {
    console.error(issue);
}

console.log(
    `Asset lists: ${pageCount} shared list pages, ${tableCount} SDK tables, ${issues.length} violations.`,
);
process.exitCode = issues.length ? 1 : 0;
