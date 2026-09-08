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
        case 'decimal':
        case 'integer':
            return {
                ...base,
                type: 'number',
                suffix: definition.satuan ?? undefined,
                min: numeric(definition.min_value),
                max: numeric(definition.max_value),
                step: definition.data_type === 'integer' ? 1 : 'any',
                help:
                    definition.min_value !== null && definition.max_value !== null
                        ? `Isi antara ${definition.min_value} dan ${definition.max_value}.`
                        : undefined,
            };
        case 'boolean':
            return { ...base, type: 'boolean' };
        case 'date':
            return { ...base, type: 'date' };
        case 'string':
            return definition.nilai_pilihan.length > 0
                ? {
                      ...base,
                      type: 'select',
                      options: definition.nilai_pilihan.map((choice) => ({
                          value: choice.nilai,
                          label: choice.nilai,
                      })),
                  }
                : { ...base, type: 'text' };
        default:
            return { ...base, type: 'text' };
    }
}
