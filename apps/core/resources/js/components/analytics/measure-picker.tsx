import { Field } from '@apperp/ui/field';
import { MultiSelect } from '@apperp/ui/multi-select';
import type { RefObject } from 'react';
import type { DatasetMeasure } from '@/lib/analytics/types';

/**
 * Langkah Nilai: angka yang dihitung, satu atau beberapa, urut seperti dipilih. Hanya nilai yang dinyatakan data
 * yang ditawarkan; kolom angka biasa bukan nilai.
 */
export function MeasurePicker({
    measures,
    value,
    onChange,
    portalContainer,
}: {
    measures: DatasetMeasure[];
    value: string[];
    onChange: (keys: string[]) => void;
    portalContainer?: RefObject<HTMLElement | null>;
}) {
    return (
        <Field>
            <MultiSelect
                label="Nilai yang dihitung"
                items={measures.map((measure) => ({
                    value: measure.key,
                    label: measure.caption,
                }))}
                value={value}
                onValueChange={onChange}
                searchPlaceholder="Cari nilai"
                emptyMessage="Nilai tidak ditemukan."
                portalContainer={portalContainer}
            />
        </Field>
    );
}
