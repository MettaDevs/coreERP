import { FieldConfig } from '../../master/fields';

/** Bentuk definisi atribut yang dikirim `GET /jenis-aset/{id}/atribut-definisi`. */
export type AttributeDefinition = {
    tipe_atribut_id: string;
    kode: string;
    nama: string;
    data_type: string;
    satuan: string | null;
    min_value: string | number | null;
    max_value: string | number | null;
    wajib: boolean;
    nilai_pilihan: { id: string; nilai: string }[];
};

const numeric = (value: string | number | null): number | undefined =>
    value === null || value === undefined ? undefined : Number(value);

/**
 * Menerjemahkan definisi atribut menjadi `FieldConfig`.
 *
 * Hasilnya dirender oleh `DynamicField` yang sama dengan field statis milik master,
 * sehingga tidak ada komponen form kedua yang perlu dirawat.
 */
export function toFieldConfig(definition: AttributeDefinition): FieldConfig {
    const base = {
        name: definition.tipe_atribut_id,
        label: definition.nama,
        required: definition.wajib,
    };

    switch (definition.data_type) {
        case 'number':
            return { ...base, type: 'number', suffix: definition.satuan ?? undefined };
        case 'value_range':
            return {
                ...base,
                type: 'number',
                suffix: definition.satuan ?? undefined,
                min: numeric(definition.min_value),
                max: numeric(definition.max_value),
                help: `Antara ${definition.min_value} dan ${definition.max_value}.`,
            };
        case 'boolean':
            return { ...base, type: 'boolean' };
        case 'date':
            return { ...base, type: 'date' };
        case 'fixed_list':
            return {
                ...base,
                type: 'select',
                options: definition.nilai_pilihan.map((choice) => ({ value: choice.nilai, label: choice.nilai })),
            };
        default:
            return { ...base, type: 'text' };
    }
}
