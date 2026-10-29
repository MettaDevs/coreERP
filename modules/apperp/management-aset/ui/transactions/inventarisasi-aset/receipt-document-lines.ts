import type { BarisPenerimaan } from './penerimaan';

export type ReceiptAssetType = { id: string; kode: string; nama: string };

export function appendReceiptAssetTypes(
    rows: BarisPenerimaan[],
    types: ReceiptAssetType[],
    createBlank: () => BarisPenerimaan,
): BarisPenerimaan[] {
    if (types.length === 0) {
        return rows;
    }

    const blank = createBlank();
    const emptyIndex = rows.findIndex(
        (row) =>
            !row.id &&
            Object.entries(blank).every(
                ([key, value]) =>
                    key === 'clientKey' ||
                    JSON.stringify(row[key as keyof BarisPenerimaan]) ===
                        JSON.stringify(value),
            ),
    );
    const added = types.map((type) => ({
        ...createBlank(),
        jenis_aset_id: type.id,
        nama: type.nama,
    }));

    if (emptyIndex < 0) {
        return [...rows, ...added];
    }

    return [
        ...rows.slice(0, emptyIndex),
        ...added,
        ...rows.slice(emptyIndex + 1),
    ];
}

export function receiptInputTotal(
    rows: BarisPenerimaan[],
    field: 'nilai_per_unit' | 'ppn_per_unit' | 'akumulasi_per_unit',
    includeTax = false,
): string {
    const values = rows.map((row) => {
        const value = String(row[field] || '0');
        const tax = includeTax ? String(row.ppn_per_unit || '0') : '0';
        const quantity = Number(row.jumlah);

        return {
            value,
            tax,
            quantity:
                Number.isSafeInteger(quantity) && quantity >= 0
                    ? BigInt(quantity)
                    : 0n,
        };
    });
    const scale = values.reduce(
        (maximum, row) =>
            Math.max(
                maximum,
                ...[row.value, row.tax].map((value) =>
                    /^\d+(\.\d+)?$/.test(value)
                        ? (value.split('.')[1]?.length ?? 0)
                        : 0,
                ),
            ),
        0,
    );
    const scaled = (value: string) => {
        if (!/^\d+(\.\d+)?$/.test(value)) {
            return 0n;
        }

        const [whole, fraction = ''] = value.split('.');

        return BigInt(whole + fraction.padEnd(scale, '0'));
    };
    const total = values.reduce(
        (sum, row) =>
            sum + (scaled(row.value) + scaled(row.tax)) * row.quantity,
        0n,
    );
    const digits = total.toString().padStart(scale + 1, '0');
    const whole = scale ? digits.slice(0, -scale) : digits;
    const fraction = scale ? digits.slice(-scale).replace(/0+$/, '') : '';

    return (
        whole.replace(/\B(?=(\d{3})+(?!\d))/g, '.') +
        (fraction ? `,${fraction}` : '')
    );
}
