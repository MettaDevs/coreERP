import assert from 'node:assert/strict';
import { Buffer } from 'node:buffer';
import { readFile } from 'node:fs/promises';
import ts from 'typescript';

async function functionsFromSource(url, names) {
    const text = await readFile(url, 'utf8');
    const source = ts.createSourceFile(
        url.pathname,
        text,
        ts.ScriptTarget.Latest,
        true,
        ts.ScriptKind.TSX,
    );
    const functions = source.statements.filter(
        (node) =>
            ts.isFunctionDeclaration(node) && names.includes(node.name?.text),
    );
    assert.equal(functions.length, names.length);

    return functions.map((node) => node.getText(source)).join('\n');
}

const sdk = await functionsFromSource(
    new URL('../packages/ui/src/components/form-feedback.tsx', import.meta.url),
    ['createSaveFeedback'],
);

for (const app of ['core', 'control-plane']) {
    const callbacksSource = await functionsFromSource(
        new URL(
            `../apps/${app}/resources/js/hooks/use-inertia-feedback.tsx`,
            import.meta.url,
        ),
        ['createInertiaFeedbackCallbacks', 'withInertiaErrors'],
    );
    const { outputText } = ts.transpileModule(`${sdk}\n${callbacksSource}`, {
        compilerOptions: {
            module: ts.ModuleKind.ESNext,
            target: ts.ScriptTarget.ES2022,
        },
    });
    const { createInertiaFeedbackCallbacks, withInertiaErrors } = await import(
        `data:text/javascript;base64,${Buffer.from(outputText).toString('base64')}`
    );
    const feedback = [];
    const events = [];
    const callbacks = createInertiaFeedbackCallbacks(
        'Dokumen belum disimpan',
        (next) => feedback.push(next),
        {
            onStart: () => events.push('start'),
            onHttpException: () => events.push('http'),
            onNetworkError: () => events.push('network'),
        },
    );
    callbacks.onStart({});
    assert.equal(feedback[0], null);
    assert.equal(
        callbacks.onHttpException({
            status: 500,
            data: '<html>SQLSTATE private data</html>',
            headers: {},
        }),
        false,
        'default library error modal must be suppressed',
    );
    assert.doesNotMatch(
        JSON.stringify(feedback[1]),
        /SQLSTATE|private data|<html>/,
    );
    assert.match(feedback[1].message, /gangguan sistem/);
    assert.match(feedback[1].details, /500/);
    assert.equal(
        callbacks.onNetworkError(new Error('raw transport internals')),
        false,
    );
    assert.doesNotMatch(
        JSON.stringify(feedback[2]),
        /raw transport|pasti batal|belum tersimpan|belum disimpan/,
    );
    assert.deepEqual(events, ['start', 'http', 'network']);
    const errors = [];
    const finish = () => events.push('finish');
    const adapted = withInertiaErrors({
        onError: (value) => errors.push(value),
        onFinish: finish,
        preserveScroll: true,
    });
    assert.equal(adapted.onFinish, finish);
    assert.equal(adapted.preserveScroll, true);
    adapted.onError({ name: 'Isi nama.' });
    assert.deepEqual(errors[0], { name: 'Isi nama.' });
    assert.equal(
        adapted.onHttpException({
            status: 500,
            data: 'secret SQL',
            headers: {},
        }),
        false,
    );
    assert.match(errors[1]._form, /gangguan sistem/);
    assert.doesNotMatch(errors[1]._form, /secret SQL|Salin detail/);
}

console.log('Scoped Inertia HTTP failure checks passed for Core and console.');
