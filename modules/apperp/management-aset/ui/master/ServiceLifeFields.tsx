import { Field, FieldDescription } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { monthsPerPeriod, serviceLifeMonths } from './serviceLife';

/** Tahun dan bulan menyunting umur yang sama; jumlah periode hanya bentuk simpan mesin. */
export default function ServiceLifeFields({
    value,
    frequency,
    onChange,
    required = false,
    readOnly = false,
    onRequestEdit,
}: {
    value: unknown;
    frequency: unknown;
    onChange: (value: string | number) => void;
    required?: boolean;
    readOnly?: boolean;
    onRequestEdit?: () => void;
}) {
    const months = serviceLifeMonths(value, frequency);
    const periodMonths = monthsPerPeriod(frequency);
    const change = (raw: string, multiplier: number) => {
        if (raw === '') {
            onChange('');
        } else if (periodMonths) {
            onChange((Number(raw) * multiplier) / periodMonths);
        }
    };

    return (
        <Field
            data-field-name="useful_life_periods"
            data-readonly={readOnly || undefined}
        >
            <div className="grid gap-3 sm:grid-cols-2">
                <Input
                    label="Masa manfaat (tahun)"
                    type="number"
                    min={0}
                    step="any"
                    required={required}
                    readOnly={readOnly}
                    disabled={!periodMonths}
                    onFocus={readOnly ? onRequestEdit : undefined}
                    value={
                        months === null ? '' : Number((months / 12).toFixed(8))
                    }
                    onChange={(event) => change(event.target.value, 12)}
                />
                <Input
                    label="Masa manfaat (bulan)"
                    type="number"
                    min={periodMonths ?? 1}
                    step={periodMonths ?? 1}
                    required={required}
                    readOnly={readOnly}
                    disabled={!periodMonths}
                    onFocus={readOnly ? onRequestEdit : undefined}
                    value={months ?? ''}
                    onChange={(event) => change(event.target.value, 1)}
                />
            </div>
            <FieldDescription>
                {periodMonths
                    ? `Cukup isi tahun atau bulan; keduanya menunjukkan umur yang sama. Frekuensi tidak mengubah umur aset.${periodMonths > 1 ? ` Gunakan kelipatan ${periodMonths} bulan untuk frekuensi ini.` : ''}`
                    : 'Pilih frekuensi terlebih dahulu.'}
            </FieldDescription>
        </Field>
    );
}
