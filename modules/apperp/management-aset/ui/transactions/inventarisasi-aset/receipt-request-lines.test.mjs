import assert from 'node:assert/strict';
import { Buffer } from 'node:buffer';
import { readFile } from 'node:fs/promises';
import { transpileModule, ModuleKind } from 'typescript';
const source = await readFile(
    new URL('./receipt-request-lines.ts', import.meta.url),
    'utf8',
);
const { outputText } = transpileModule(source, {
    compilerOptions: { module: ModuleKind.ESNext },
});
const { appendRequestLines, availableRequestLines } = await import(
    `data:text/javascript;base64,${Buffer.from(outputText).toString('base64')}`
);
let nextKey = 0;
const blank = () => ({
    clientKey: String(++nextKey),
    nama: '',
    group_aset_id: '',
    jenis_aset_id: '',
    kondisi_aset_id: '',
    pabrikan_aset_id: '',
    model_aset_id: '',
    model_number: '',
    jumlah: 1,
    nilai_per_unit: '',
    ppn_per_unit: '',
    residu_per_unit: '',
    akumulasi_per_unit: '',
    periode_berjalan: '',
    saldo_awal_buku: [],
    permintaan_pembelian_detail_id: '',
    atribut: [],
    keterangan: '',
});
const line = (id, remaining) => ({
    id,
    nama_aset: id,
    jenis_aset_id: 'type',
    remaining_quantity: remaining,
    specification: 'spec',
});
const request = {
    id: 'request-a',
    kode: 'RPA-1',
    details: [
        line('one', 6),
        line('two', 3),
        line('fraction', 0.5),
        line('received', 0),
    ],
};
const empty = blank();
const rows = appendRequestLines(
    [empty],
    0,
    request,
    ['one', 'two', 'one', 'fraction', 'received'],
    blank,
);
assert.equal(
    rows.length,
    2,
    'untouched blank row is replaced, not kept as empty receipt line',
);
assert.deepEqual(
    rows.map((row) => row.permintaan_pembelian_detail_id),
    ['one', 'two'],
);
assert.deepEqual(
    rows.map((row) => row.jumlah),
    [6, 3],
    'each imported line uses only its remaining whole units',
);
assert.equal(empty.nama, '', 'original state is immutable');
const manual = {
    ...blank(),
    nama: 'Manual asset',
    nilai_per_unit: '100',
    jumlah: 2,
};
const preserved = appendRequestLines(
    [manual],
    0,
    request,
    ['one', 'two'],
    blank,
);
assert.equal(preserved[0], manual, 'manual row and typed values remain intact');
assert.equal(preserved.length, 3);
const linked = { ...blank(), permintaan_pembelian_detail_id: 'one', jumlah: 2 };
assert.deepEqual(
    availableRequestLines(request, [linked, blank()], 1).map((row) => row.id),
    ['two'],
    'a source line already present in the draft cannot be received twice',
);
const repeat = appendRequestLines([linked], 0, request, ['one', 'two'], blank);
assert.equal(repeat[0], linked);
assert.equal(repeat.length, 2);
assert.equal(repeat[1].permintaan_pembelian_detail_id, 'two');
assert.equal(
    appendRequestLines(repeat, 0, request, ['one', 'two'], blank),
    repeat,
    'repeated select-all is idempotent',
);
assert.equal(appendRequestLines([manual], 0, request, [], blank).length, 1);
console.log('Receipt request line selection: passed.');
