import assert from 'node:assert/strict';
import { readFile, readdir } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { ESLint } from 'eslint';
import globals from 'globals';
import typescript from 'typescript-eslint';

const root = fileURLToPath(new URL('../', import.meta.url));
const hint =
    'Gunakan @apperp/ui/use-confirmation atau Dialog SDK; jangan memakai dialog bawaan browser.';
const lint = new ESLint({
    cwd: root,
    overrideConfigFile: true,
    ignore: false,
    allowInlineConfig: false,
    overrideConfig: [
        {
            files: ['**/*.{js,jsx,ts,tsx}'],
            languageOptions: {
                parser: typescript.parser,
                globals: globals.browser,
                parserOptions: { ecmaFeatures: { jsx: true } },
            },
            rules: {
                'no-alert': 'error',
                'no-restricted-syntax': [
                    'error',
                    {
                        selector:
                            "CallExpression[callee.type='MemberExpression'][callee.property.name='reportValidity']",
                        message:
                            'Tampilkan validasi melalui header dan field SDK, bukan reportValidity() browser.',
                    },
                    {
                        selector:
                            'JSXOpeningElement[name.name=/^(form|Form)$/]:not(:has(JSXAttribute[name.name="noValidate"]))',
                        message:
                            'Form harus memakai noValidate dan menampilkan validasi di header serta field SDK.',
                    },
                    {
                        selector:
                            "AssignmentExpression[left.type='MemberExpression'][left.property.name='returnValue']",
                        message:
                            'Jangan memicu popup keluar halaman bawaan browser; gunakan konfirmasi SDK untuk navigasi aplikasi.',
                    },
                ],
                'no-restricted-properties': [
                    'error',
                    ...['window', 'globalThis', 'self'].flatMap((object) =>
                        ['alert', 'confirm', 'prompt'].map((property) => ({
                            object,
                            property,
                            message: hint,
                        })),
                    ),
                ],
            },
        },
    ],
});

async function inspect(source, filePath) {
    const [result] = await lint.lintText(source, { filePath });

    return result.messages;
}

if (process.argv.includes('--self-test')) {
    const samples = [
        "window.confirm('Buang perubahan yang belum disimpan?');",
        "window.prompt('Alasan membatalkan?');",
        "alert('Error simpan');",
        "globalThis['alert']('Error');",
        "const ask = window.confirm; ask('Delete?');",
        "const { prompt: ask } = window; ask('Alasan?');",
        'input.reportValidity();',
        '<form><input required /></form>;',
        "event.returnValue = '';",
    ];

    for (const source of samples) {
        assert.ok(
            (await inspect(source, 'dialog-guard-test.tsx')).some(
                (message) => message.severity === 2,
            ),
            source,
        );
    }

    assert.deepEqual(
        await inspect(
            "const { confirm } = useConfirmation(); await confirm({ title: 'Delete?', description: 'Data akan diarsipkan.' });",
            'dialog-guard-test.tsx',
        ),
        [],
    );
    console.log(
        'Browser dialog guard rejects past mistakes and accepts SDK confirmation.',
    );
} else {
    const excluded = new Set([
        'node_modules',
        '.git',
        'dist',
        'build',
        'public',
        'storage',
    ]);
    const files = [];
    async function collect(directory) {
        for (const item of await readdir(directory, { withFileTypes: true })) {
            const fullPath = path.join(directory, item.name);

            if (item.isDirectory() && !excluded.has(item.name)) {
                await collect(fullPath);
            } else if (
                item.isFile() &&
                /\.(?:[cm]?[jt]sx?|blade\.php)$/.test(item.name)
            ) {
                files.push(fullPath);
            }
        }
    }

    for (const directory of [
        'apps/core/resources',
        'apps/control-plane/resources',
        'packages/ui/src',
        'modules',
    ]) {
        await collect(path.join(root, directory));
    }

    const failures = [];

    for (const file of files) {
        const source = await readFile(file, 'utf8');

        if (file.endsWith('.blade.php')) {
            const browserCall =
                /\b(?:(?:window|globalThis|self)\s*\.\s*)?(?:alert|confirm|prompt)\s*\(/g;

            for (const match of source.matchAll(browserCall)) {
                const line = source.slice(0, match.index).split('\n').length;
                failures.push(`${path.relative(root, file)}:${line} ${hint}`);
            }

            continue;
        }

        for (const message of await inspect(source, file)) {
            if (message.severity === 2) {
                failures.push(
                    `${path.relative(root, file)}:${message.line} ${message.message}`,
                );
            }
        }
    }

    if (failures.length) {
        process.stderr.write(`${failures.join('\n')}\n`);
        process.exitCode = 1;
    } else {
        console.log(
            `Browser dialog guard passed (${files.length} source files checked).`,
        );
    }
}
