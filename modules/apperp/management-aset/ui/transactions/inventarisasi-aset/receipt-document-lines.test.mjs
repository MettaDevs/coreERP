import assert from 'node:assert/strict';
import { Buffer } from 'node:buffer';
import { readFileSync } from 'node:fs';
import { transpileModule } from 'typescript';

const { outputText } = transpileModule(
    readFileSync(
        new URL('./receipt-document-lines.ts', import.meta.url),
        'utf8',
    ),
    { compilerOptions: { module: 99, target: 99 } },
);
const { appendReceiptAssetTypes, receiptInputTotal } = await import(
    `data:text/javascript;base64,${Buffer.from(outputText).toString('base64')}`
);
let key = 0;
const blank = () => ({
    clientKey: String(++key),
    nama: '',
    jenis_aset_id: '',
    jumlah: 1,
    nilai_per_unit: '',
    ppn_per_unit: '',
    akumulasi_per_unit: '',
    atribut: [],
    permintaan_pembelian_detail_id: '',
});
const original = {
    ...blank(),
    nama: 'Barang sudah diisi',
    jumlah: 3,
    permintaan_pembelian_detail_id: 'request-line',
};
const result = appendReceiptAssetTypes(
    [original, blank()],
    [
        { id: 'type-a', nama: 'Laptop' },
        { id: 'type-b', nama: 'Monitor' },
    ],
    blank,
);
assert.equal(result.length, 3);
assert.equal(result[0], original);
assert.deepEqual(
    result
        .slice(1)
        .map((line) => [
            line.jenis_aset_id,
            line.nama,
            line.permintaan_pembelian_detail_id,
        ]),
    [
        ['type-a', 'Laptop', ''],
        ['type-b', 'Monitor', ''],
    ],
);
assert.equal(new Set(result.map((line) => line.clientKey)).size, 3);
assert.equal(appendReceiptAssetTypes([original], [], blank)[0], original);
assert.equal(
    appendReceiptAssetTypes(
        [original],
        [{ id: 'type-a', nama: 'Laptop' }],
        blank,
    ).length,
    2,
);
const values = [
    {
        jumlah: 3,
        nilai_per_unit: '0.1',
        ppn_per_unit: '0.2',
        akumulasi_per_unit: '0.01',
    },
    {
        jumlah: 2,
        nilai_per_unit: '1000.0001',
        ppn_per_unit: '',
        akumulasi_per_unit: '1.23',
    },
];
assert.equal(receiptInputTotal(values, 'nilai_per_unit'), '2.000,3002');
assert.equal(receiptInputTotal(values, 'nilai_per_unit', true), '2.000,9002');
assert.equal(receiptInputTotal(values, 'akumulasi_per_unit'), '2,49');
assert.equal(
    receiptInputTotal(
        [{ jumlah: '', nilai_per_unit: '', ppn_per_unit: '' }],
        'nilai_per_unit',
        true,
    ),
    '0',
);
console.log(
    'Receipt document lines: bulk insertion and exact input totals passed.',
);
