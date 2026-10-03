import type { ResultColumn, ResultValue } from '@/lib/analytics/types';

/**
 * Satu-satunya tempat angka hasil analitik diformat di layar (`docs/todo/analitik/dasbor-dan-visual.md`,
 * bagian *Format angka*). Nilai uang dan desimal datang sebagai string supaya presisinya utuh; di sini
 * ia diubah menjadi angka hanya untuk ditulis, tidak untuk dihitung.
 *
 * `Intl` bahasa Indonesia menulis ringkasan sebagai "rb", "jt", "M", dan "T" — "Rp 1,3 M" untuk satu
 * koma tiga miliar — sama dengan cara orang menyebutnya.
 */

const NUMBER = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 });
const COMPACT = new Intl.NumberFormat('id-ID', {
    notation: 'compact',
    maximumFractionDigits: 1,
});

export function formatMeasureValue(
    column: ResultColumn,
    row: Record<string, ResultValue>,
    compact = false,
): string {
    const raw = row[column.key];

    if (raw === null || raw === undefined) {
        return '—';
    }

    const value = Number(raw);

    switch (column.format) {
        case 'money': {
            const currency = String(row[column.currency_key ?? ''] ?? 'IDR');

            return new Intl.NumberFormat('id-ID', {
                style: 'currency',
                currency,
                notation: compact ? 'compact' : 'standard',
                maximumFractionDigits: compact ? 1 : 2,
            }).format(value);
        }
        case 'percent':
            return `${NUMBER.format(value)}%`;
        case 'hours':
            return `${NUMBER.format(value)} jam`;
        default:
            return compact ? COMPACT.format(value) : NUMBER.format(value);
    }
}

/** Nilai dimensi untuk dibaca orang: label pilihan bila ada, "(kosong)" untuk nilai kosong. */
export function formatDimensionValue(
    column: ResultColumn,
    row: Record<string, ResultValue>,
): string {
    const value = row[column.label_key ?? column.key] ?? row[column.key];

    if (value === null || value === undefined || value === '') {
        return '(kosong)';
    }

    return String(value);
}
