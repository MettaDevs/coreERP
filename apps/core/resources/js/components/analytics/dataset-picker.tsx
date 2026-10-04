import { Field, FieldDescription } from '@apperp/ui/field';
import { Select } from '@apperp/ui/select';
import type { RefObject } from 'react';
import type { DatasetSummary } from '@/lib/analytics/types';

/**
 * Langkah Data: memilih satu data dari katalog yang boleh dibaca pengguna (module yang tidak terpasang tidak
 * muncul). Nama dan keterangannya dari yang dinyatakan module. Mengganti data mengosongkan pilihan lain, karena
 * kolom dan nilainya milik data sebelumnya.
 */
export function DatasetPicker({
    datasets,
    value,
    onChange,
    portalContainer,
}: {
    datasets: DatasetSummary[];
    value: string;
    onChange: (code: string) => void;
    portalContainer?: RefObject<HTMLElement | null>;
}) {
    const selected = datasets.find((dataset) => dataset.code === value);

    return (
        <Field>
            <Select
                label="Data"
                required
                items={datasets.map((dataset) => ({
                    value: dataset.code,
                    label: dataset.caption,
                }))}
                value={value === '' ? null : value}
                onValueChange={(next) => {
                    if (next !== null && next !== value) {
                        onChange(next);
                    }
                }}
                placeholder="Pilih data"
                searchPlaceholder="Cari data"
                emptyMessage="Data tidak ditemukan."
                portalContainer={portalContainer}
            />
            {selected?.description && (
                <FieldDescription>{selected.description}</FieldDescription>
            )}
        </Field>
    );
}
