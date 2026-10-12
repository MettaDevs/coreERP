import assert from 'node:assert/strict';
import { Buffer } from 'node:buffer';
import { readFile } from 'node:fs/promises';
import { afterEach, beforeEach, test } from 'node:test';
import ts from 'typescript';

// Pakai compiler repo agar pemeriksaan ini tidak bergantung dukungan TypeScript bawaan versi Node runner.
const source = await readFile(
    new URL('../resources/js/lib/core-api.ts', import.meta.url),
    'utf8',
);
const compiled = ts
    .transpileModule(source, {
        compilerOptions: {
            module: ts.ModuleKind.ESNext,
            target: ts.ScriptTarget.ES2022,
        },
    })
    .outputText.replace(
        "from 'sonner'",
        `from ${JSON.stringify(import.meta.resolve('sonner'))}`,
    );
const { apiUpload, apiRequest, CoreApiError } = await import(
    `data:text/javascript;base64,${Buffer.from(compiled).toString('base64')}`
);

class UploadRequest {
    static last;
    headers = {};
    upload = {};
    constructor() {
        UploadRequest.last = this;
    }
    open(method, path) {
        this.method = method;
        this.path = path;
    }
    setRequestHeader(name, value) {
        this.headers[name] = value;
    }
    send(body) {
        this.body = body;
    }
    abort() {
        this.onabort();
        this.onloadend();
    }
    respond(status, body) {
        this.status = status;
        this.responseText =
            typeof body === 'string' ? body : JSON.stringify(body);
        this.onload();
        this.onloadend();
    }
}

const original = {
    document: globalThis.document,
    XMLHttpRequest: globalThis.XMLHttpRequest,
    fetch: globalThis.fetch,
};
beforeEach(() => {
    globalThis.document = { cookie: 'XSRF-TOKEN=test%20token' };
    globalThis.XMLHttpRequest = UploadRequest;
    UploadRequest.last = undefined;
});
afterEach(() => {
    Object.assign(globalThis, original);
});
const file = () => new File(['test photo'], 'test.png', { type: 'image/png' });

test('upload memakai sesi/CSRF, multipart asli, dan progres transfer', async () => {
    const fractions = [];
    const promise = apiUpload(
        '/api/v1/records/test/1/pictures',
        file(),
        (value) => fractions.push(value),
        new AbortController().signal,
    );
    const request = UploadRequest.last;
    assert.equal(request.method, 'POST');
    assert.equal(request.path, '/api/v1/records/test/1/pictures');
    assert.equal(request.headers['X-XSRF-TOKEN'], 'test token');
    assert.equal(request.headers.Accept, 'application/json');
    assert.equal(request.headers['Content-Type'], undefined);
    assert.equal(request.body.get('file').name, 'test.png');
    request.upload.onprogress({
        lengthComputable: true,
        loaded: 57,
        total: 100,
    });
    request.upload.onprogress({ lengthComputable: false });
    assert.deepEqual(fractions, [0.57]);
    request.respond(201, { data: { id: 'saved' } });
    assert.deepEqual(await promise, { data: { id: 'saved' } });
});

test('validasi dan sesi upload tetap memakai CoreApiError seperti fetch', async () => {
    for (const status of [422, 403, 419, 500]) {
        const body = {
            errors: { file: ['Berkas ditolak.'] },
            error: { code: 'upload_error' },
        };
        const promise = apiUpload(
            '/upload',
            file(),
            () => {},
            new AbortController().signal,
        );
        UploadRequest.last.respond(status, body);
        await assert.rejects(
            promise,
            (error) =>
                error instanceof CoreApiError &&
                error.status === status &&
                error.message === 'Berkas ditolak.' &&
                error.code === 'upload_error',
        );
        globalThis.fetch = async () =>
            new Response(JSON.stringify(body), { status });
        await assert.rejects(
            apiRequest('/upload'),
            (error) =>
                error instanceof CoreApiError &&
                error.status === status &&
                error.message === 'Berkas ditolak.',
        );
    }
});

test('jawaban sukses yang bukan JSON tidak dianggap berkas tersimpan', async () => {
    const promise = apiUpload(
        '/upload',
        file(),
        () => {},
        new AbortController().signal,
    );
    UploadRequest.last.respond(200, '<html>login</html>');
    await assert.rejects(
        promise,
        (error) => error instanceof CoreApiError && error.status === 502,
    );
});

test('koneksi gagal dan unmount membatalkan permintaan tanpa sukses palsu', async () => {
    const controller = new AbortController();
    const first = apiUpload('/upload', file(), () => {}, controller.signal);
    controller.abort();
    await assert.rejects(first, { name: 'AbortError' });
    const second = apiUpload(
        '/upload',
        file(),
        () => {},
        new AbortController().signal,
    );
    UploadRequest.last.onerror();
    await assert.rejects(second, /Koneksi terputus/);
    const third = apiUpload('/upload', file(), () => {}, controller.signal);
    assert.equal(UploadRequest.last.body, undefined);
    await assert.rejects(third, { name: 'AbortError' });
});
