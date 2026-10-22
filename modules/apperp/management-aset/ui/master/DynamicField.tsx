import type { RefObject } from 'react';
import { DatePicker } from '@apperp/ui/date-picker';
import { FieldLabel } from '@apperp/ui/field';
import { FormControl } from '@apperp/ui/form-control';
import { Input } from '@apperp/ui/input';
import { Select } from '@apperp/ui/select';
import { Switch } from '@apperp/ui/switch';
import { Textarea } from '@apperp/ui/textarea';
import EditShield from '../_shared/EditShield';
import type { FieldConfig, FieldValue } from './fields';
import MasterSelect from './MasterSelect';

/** Konfigurasi menentukan kontrol; SDK menentukan susunan field dan bantuannya. */
export default function DynamicField({
    config,
    value,
    onChange,
    portalContainer,
    readOnly = false,
    onRequestEdit,
    referenceQuery,
    error,
}: {
    error?: string;
    referenceQuery?: string;
    config: FieldConfig;
    value: FieldValue;
    onChange: (value: FieldValue) => void;
    portalContainer?: RefObject<HTMLDivElement | null>;
    /** Mode baca: nilai tetap tampil utuh, tetapi belum dapat diubah. */
    readOnly?: boolean;
    /** Dipanggil saat pengguna menyentuh field ini selagi mode baca. */
    onRequestEdit?: (name: string) => void;
}) {
    const requestEdit = () => onRequestEdit?.(config.name);
    /** Ditempel pada setiap kontrol agar mode sunting dapat menaruh fokus di field yang diklik. */
    const anchor = {
        'data-field-name': config.name,
        'data-error-field': config.name,
        'data-readonly': readOnly || undefined,
    };
    // `Select` merender penanda required merahnya sendiri. Jangan memasukkan `*`
    // ke teks label karena itu membuat indikator tampil dua kali.
    const label = config.label;
    const inputLabel = config.required ? `${config.label} *` : config.label;

    if (config.type === 'reference') {
        return (
            <FormControl hint={config.help} error={error} {...anchor}>
                <EditShield
                    active={readOnly}
                    label={config.label}
                    onActivate={requestEdit}
                >
                    <MasterSelect
                        resource={config.resource ?? ''}
                        label={label}
                        required={config.required}
                        value={String(value ?? '')}
                        onValueChange={onChange}
                        query={referenceQuery}
                        placeholder={
                            config.placeholder ??
                            `Pilih ${config.label.toLowerCase()}`
                        }
                        portalContainer={portalContainer}
                    />
                </EditShield>
            </FormControl>
        );
    }

    if (config.type === 'boolean') {
        return (
            <FormControl hint={config.help} error={error} {...anchor}>
                <div className="flex items-center gap-2">
                    <EditShield
                        active={readOnly}
                        label={config.label}
                        onActivate={requestEdit}
                    >
                        <Switch
                            id={config.name}
                            checked={Boolean(value)}
                            onCheckedChange={onChange}
                        />
                    </EditShield>
                    <FieldLabel htmlFor={config.name}>
                        {config.label}
                    </FieldLabel>
                </div>
            </FormControl>
        );
    }

    if (config.type === 'select') {
        const options = config.options ?? [];
        const selected = options.find((option) => option.value === value);

        return (
            <FormControl hint={config.help} error={error} {...anchor}>
                <EditShield
                    active={readOnly}
                    label={config.label}
                    onActivate={requestEdit}
                >
                    <Select
                        label={label}
                        required={config.required}
                        items={options.map((option) => option.label)}
                        value={selected ? selected.label : undefined}
                        placeholder={
                            config.placeholder ??
                            `Pilih ${config.label.toLowerCase()}`
                        }
                        searchPlaceholder={`Cari ${config.label.toLowerCase()}`}
                        emptyMessage={`${config.label} tidak ditemukan.`}
                        ariaLabel={`Pilih ${config.label.toLowerCase()}`}
                        portalContainer={portalContainer}
                        onValueChange={(item) =>
                            onChange(
                                options.find((option) => option.label === item)
                                    ?.value ?? '',
                            )
                        }
                    />
                </EditShield>
            </FormControl>
        );
    }

    if (config.type === 'multiselect') {
        const options = config.options ?? [];
        const chosen = Array.isArray(value) ? value : [];

        return (
            <FormControl hint={config.help} error={error} {...anchor}>
                <FieldLabel>{inputLabel}</FieldLabel>
                {/* Satu perisai untuk seluruh baris, bukan per pilihan: empat tombol
                    transparan berjajar hanya akan menambah empat perhentian tab kosong. */}
                <EditShield
                    active={readOnly}
                    label={config.label}
                    onActivate={requestEdit}
                >
                    <div className="flex flex-wrap gap-3">
                        {options.map((option) => (
                            <label
                                key={option.value}
                                className="flex items-center gap-2 text-sm"
                            >
                                <Switch
                                    id={`${config.name}-${option.value}`}
                                    checked={chosen.includes(option.value)}
                                    onCheckedChange={(checked) =>
                                        onChange(
                                            checked
                                                ? [...chosen, option.value]
                                                : chosen.filter(
                                                      (item) =>
                                                          item !== option.value,
                                                  ),
                                        )
                                    }
                                />
                                {option.label}
                            </label>
                        ))}
                    </div>
                </EditShield>
            </FormControl>
        );
    }

    if (config.type === 'textarea') {
        return (
            <FormControl hint={config.help} error={error} {...anchor}>
                <Textarea
                    label={label}
                    id={config.name}
                    rows={4}
                    required={config.required}
                    placeholder={config.placeholder}
                    readOnly={readOnly}
                    value={String(value ?? '')}
                    onChange={(event) => onChange(event.target.value)}
                />
            </FormControl>
        );
    }

    if (config.type === 'date') {
        return (
            <FormControl hint={config.help} error={error} {...anchor}>
                <DatePicker
                    id={config.name}
                    label={label}
                    required={config.required}
                    readOnly={readOnly}
                    value={String(value ?? '')}
                    onValueChange={onChange}
                    portalContainer={portalContainer}
                />
            </FormControl>
        );
    }

    return (
        <FormControl hint={config.help} error={error} {...anchor}>
            {/* Input sungguhan yang dibuat `readOnly`, bukan teks biasa. Karena elemennya
                tidak pernah dilepas, kursor hasil klik tetap berada di huruf yang diklik
                ketika mode berpindah — tidak ada fokus yang perlu dipulihkan. */}
            <Input
                id={config.name}
                label={config.suffix ? `${label} (${config.suffix})` : label}
                type={config.type === 'number' ? 'number' : 'text'}
                required={config.required}
                min={config.min}
                max={config.max}
                step={config.step}
                placeholder={config.placeholder}
                readOnly={readOnly}
                value={String(value ?? '')}
                onChange={(event) => onChange(event.target.value)}
            />
        </FormControl>
    );
}
