import { RefObject } from 'react';
import { Field, FieldDescription, FieldLabel } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { Select } from '@apperp/ui/select';
import { Switch } from '@apperp/ui/switch';
import { Textarea } from '@apperp/ui/textarea';
import { FieldConfig, FieldValue } from './fields';

/**
 * Satu komponen untuk seluruh field yang dideklarasikan lewat `FieldConfig`.
 * Tidak memuat logika domain apa pun: apa yang dirender ditentukan konfigurasi,
 * sehingga sumber konfigurasi boleh berupa daftar statis milik master maupun definisi
 * atribut yang datang dari server.
 */
export default function DynamicField({
    config,
    value,
    onChange,
    portalContainer,
}: {
    config: FieldConfig;
    value: FieldValue;
    onChange: (value: FieldValue) => void;
    portalContainer?: RefObject<HTMLDivElement | null>;
}) {
    const label = config.required ? `${config.label} *` : config.label;

    if (config.type === 'boolean') {
        return (
            <Field orientation="horizontal">
                <Switch id={config.name} checked={Boolean(value)} onCheckedChange={onChange} />
                <FieldLabel htmlFor={config.name}>{config.label}</FieldLabel>
                {config.help && <FieldDescription>{config.help}</FieldDescription>}
            </Field>
        );
    }

    if (config.type === 'select') {
        const options = config.options ?? [];
        const selected = options.find((option) => option.value === value);

        return (
            <Field>
                <Select
                    label={label}
                    required={config.required}
                    items={options.map((option) => option.label)}
                    value={selected ? selected.label : undefined}
                    placeholder={config.placeholder ?? `Pilih ${config.label.toLowerCase()}`}
                    searchPlaceholder={`Cari ${config.label.toLowerCase()}`}
                    emptyMessage={`${config.label} tidak ditemukan.`}
                    ariaLabel={`Pilih ${config.label.toLowerCase()}`}
                    portalContainer={portalContainer}
                    onValueChange={(item) => onChange(options.find((option) => option.label === item)?.value ?? '')}
                />
                {config.help && <FieldDescription>{config.help}</FieldDescription>}
            </Field>
        );
    }

    if (config.type === 'multiselect') {
        const options = config.options ?? [];
        const chosen = Array.isArray(value) ? value : [];

        return (
            <Field>
                <FieldLabel>{label}</FieldLabel>
                <div className="flex flex-wrap gap-3">
                    {options.map((option) => (
                        <label key={option.value} className="flex items-center gap-2 text-sm">
                            <Switch
                                id={`${config.name}-${option.value}`}
                                checked={chosen.includes(option.value)}
                                onCheckedChange={(checked) => onChange(checked
                                    ? [...chosen, option.value]
                                    : chosen.filter((item) => item !== option.value))}
                            />
                            {option.label}
                        </label>
                    ))}
                </div>
                {config.help && <FieldDescription>{config.help}</FieldDescription>}
            </Field>
        );
    }

    if (config.type === 'textarea') {
        return (
            <Field>
                <FieldLabel htmlFor={config.name}>{label}</FieldLabel>
                <Textarea
                    id={config.name}
                    rows={4}
                    required={config.required}
                    placeholder={config.placeholder}
                    value={String(value ?? '')}
                    onChange={(event) => onChange(event.target.value)}
                />
                {config.help && <FieldDescription>{config.help}</FieldDescription>}
            </Field>
        );
    }

    return (
        <Field>
            <Input
                id={config.name}
                label={config.suffix ? `${label} (${config.suffix})` : label}
                type={config.type === 'number' ? 'number' : config.type === 'date' ? 'date' : 'text'}
                required={config.required}
                min={config.min}
                max={config.max}
                step={config.step}
                placeholder={config.placeholder}
                value={String(value ?? '')}
                onChange={(event) => onChange(event.target.value)}
            />
            {config.help && <FieldDescription>{config.help}</FieldDescription>}
        </Field>
    );
}
