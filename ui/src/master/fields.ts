/**
 * Deskripsi satu field form yang dirender `DynamicField`.
 *
 * Bentuk ini sengaja bebas domain. Ia dipakai dua sumber yang berbeda: field statis
 * milik satu master (dideklarasikan di `masters.ts`) dan kelak atribut dinamis milik
 * jenis aset yang datang dari server. Keduanya berakhir pada komponen yang sama.
 */
export type FieldOption = { value: string; label: string };

export type FieldConfig = {
    name: string;
    label: string;
    type:
        | 'text'
        | 'textarea'
        | 'number'
        | 'boolean'
        | 'date'
        | 'select'
        | 'multiselect'
        | 'reference';
    required?: boolean;
    options?: FieldOption[];
    /**
     * Slug master sumber pilihan untuk `reference`, misalnya `profil-penyusutan`.
     *
     * Berbeda dari `select` yang pilihannya ditulis di konfigurasi, `reference` memuat
     * pilihannya dari API saat dirender. Ini yang membedakan kolom foreign key dari
     * enum: isinya milik tenant dan berubah tanpa menyentuh kode.
     */
    resource?: string;
    /** Batas untuk `number`; berasal dari definisi atribut bertipe rentang nilai. */
    min?: number;
    max?: number;
    step?: number | 'any';
    /** Satuan yang ditempel di belakang input, misalnya "kg" atau "IDR". */
    suffix?: string;
    placeholder?: string;
    help?: string;
    /** Menyembunyikan field sampai kondisi terpenuhi, misalnya bergantung metode. */
    visibleWhen?: (form: Record<string, unknown>) => boolean;
    /**
     * Jembatan untuk field yang bentuk simpannya berbeda dari bentuk isiannya, misalnya
     * jadwal manual penyusutan yang diisi sebagai daftar angka tetapi dikirim sebagai
     * array objek. Tanpa ini `DynamicField` tetap tidak perlu tahu apa pun soal domain.
     */
    toPayload?: (value: FieldValue) => unknown;
    fromRecord?: (raw: unknown) => FieldValue;
};

export type FieldValue = string | number | boolean | string[] | null;

/** Nilai awal satu field saat form dibuka tanpa data. */
export function emptyValue(field: FieldConfig): FieldValue {
    if (field.type === 'boolean') return false;
    if (field.type === 'multiselect') return [];

    return '';
}

/** Nilai field dari sebuah record, dikembalikan dalam bentuk yang dipakai form. */
export function valueFrom(record: Record<string, unknown> | null, field: FieldConfig): FieldValue {
    if (!record) return emptyValue(field);
    const raw = record[field.name];
    if (field.fromRecord) return field.fromRecord(raw);
    if (raw === null || raw === undefined) return emptyValue(field);
    if (field.type === 'boolean') return Boolean(raw);
    if (field.type === 'multiselect') return Array.isArray(raw) ? raw.map(String) : [];

    return String(raw);
}

/**
 * Nilai yang dikirim ke API. String kosong menjadi `null` supaya mengosongkan field
 * benar-benar menghapus nilainya, bukan menyimpan string kosong.
 */
export function payloadValue(field: FieldConfig, value: FieldValue): unknown {
    if (field.toPayload) return field.toPayload(value);
    if (field.type === 'boolean') return Boolean(value);
    if (field.type === 'multiselect') return Array.isArray(value) ? value : [];
    if (value === '' || value === null) return null;
    if (field.type === 'number') return Number(value);

    return value;
}

export function isVisible(field: FieldConfig, form: Record<string, unknown>): boolean {
    return field.visibleWhen ? field.visibleWhen(form) : true;
}
