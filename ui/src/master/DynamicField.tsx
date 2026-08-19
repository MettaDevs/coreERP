import { ReactNode, RefObject } from 'react';
import { CircleAlert } from 'lucide-react';
import { Field, FieldDescription, FieldHint, FieldLabel } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { Select } from '@apperp/ui/select';
import { Switch } from '@apperp/ui/switch';
import { Textarea } from '@apperp/ui/textarea';
import EditShield from '../_shared/EditShield';
import { FieldConfig, FieldValue } from './fields';
import { optionLabel, useMasterOptions } from './useMasterOptions';

/**
 * Satu komponen untuk seluruh field yang dideklarasikan lewat `FieldConfig`.
 * Tidak memuat logika domain apa pun: apa yang dirender ditentukan konfigurasi,
 * sehingga sumber konfigurasi boleh berupa daftar statis milik master maupun definisi
 * atribut yang datang dari server.
 */

/**
 * Menaruh ikon bantuan kecil di sebelah kontrolnya, bukan membungkus seluruh kontrol
 * sebagai target hover.
 *
 * Percobaan pertama membungkus seluruh field terasa aneh saat dipakai sungguhan: kursor
 * yang lewat begitu saja di atas Select atau Input memicu tooltip padahal pengguna cuma
 * mau mengklik nilainya. Ikon terpisah kembali dipakai justru karena ia TIDAK bertumpang
 * tindih dengan area yang dipakai untuk berinteraksi dengan kontrolnya.
 */
function wrapHint(control: ReactNode, help: string | undefined) {
    if (!help) return control;

    return (
        <div className="flex items-center gap-1.5">
            <div className="min-w-0 flex-1">{control}</div>
            <FieldHint hint={help}>
                <button
                    type="button"
                    aria-label="Lihat penjelasan"
                    className="shrink-0 text-muted-foreground hover:text-foreground"
                >
                    <CircleAlert className="size-4" />
                </button>
            </FieldHint>
        </div>
    );
}

export default function DynamicField({
    config,
    value,
    onChange,
    portalContainer,
    readOnly = false,
    onRequestEdit,
}: {
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
    /** Ditempel pada setiap Field agar mode sunting dapat menaruh fokus di field yang diklik. */
    const anchor = { 'data-field-name': config.name, 'data-readonly': readOnly || undefined };
    // `Select` merender penanda required merahnya sendiri. Jangan memasukkan `*`
    // ke teks label karena itu membuat indikator tampil dua kali.
    const label = config.label;
    const inputLabel = config.required ? `${config.label} *` : config.label;
    // Dipanggil tanpa syarat karena hook tidak boleh berada di balik cabang; untuk
    // field selain `reference` sumbernya null sehingga tidak ada permintaan apa pun.
    const reference = useMasterOptions(config.type === 'reference' ? config.resource : null);

    if (config.type === 'reference') {
        const selected = reference.options.find((option) => option.id === value);

        return (
            <Field data-invalid={Boolean(reference.error)} {...anchor}>
                {wrapHint(
                    <EditShield active={readOnly} label={config.label} onActivate={requestEdit}>
                        <Select
                            label={label}
                            required={config.required}
                            items={reference.options.map(optionLabel)}
                            value={selected ? optionLabel(selected) : undefined}
                            placeholder={config.placeholder ?? `Pilih ${config.label.toLowerCase()}`}
                            searchPlaceholder={`Cari ${config.label.toLowerCase()}`}
                            emptyMessage={`${config.label} tidak ditemukan.`}
                            ariaLabel={`Pilih ${config.label.toLowerCase()}`}
                            portalContainer={portalContainer}
                            onValueChange={(item) => onChange(reference.options.find((option) => optionLabel(option) === item)?.id ?? '')}
                        />
                    </EditShield>,
                    config.help,
                )}
                {/* Kegagalan memuat pilihan bukan penjelasan, melainkan masalah nyata:
                    tetap tampil, tidak ikut disembunyikan di balik hover. */}
                {reference.error && <FieldDescription>{reference.error}</FieldDescription>}
            </Field>
        );
    }

    if (config.type === 'boolean') {
        return (
            <Field orientation="horizontal" {...anchor}>
                {wrapHint(
                    <div className="flex items-center gap-2">
                        <EditShield active={readOnly} label={config.label} onActivate={requestEdit}>
                            <Switch id={config.name} checked={Boolean(value)} onCheckedChange={onChange} />
                        </EditShield>
                        <FieldLabel htmlFor={config.name}>{config.label}</FieldLabel>
                    </div>,
                    config.help,
                )}
            </Field>
        );
    }

    if (config.type === 'select') {
        const options = config.options ?? [];
        const selected = options.find((option) => option.value === value);

        return (
            <Field {...anchor}>
                {wrapHint(
                    <EditShield active={readOnly} label={config.label} onActivate={requestEdit}>
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
                    </EditShield>,
                    config.help,
                )}
            </Field>
        );
    }

    if (config.type === 'multiselect') {
        const options = config.options ?? [];
        const chosen = Array.isArray(value) ? value : [];

        return (
            <Field {...anchor}>
                <FieldLabel>{inputLabel}</FieldLabel>
                {/* Satu perisai untuk seluruh baris, bukan per pilihan: empat tombol
                    transparan berjajar hanya akan menambah empat perhentian tab kosong. */}
                {wrapHint(
                    <EditShield active={readOnly} label={config.label} onActivate={requestEdit}>
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
                    </EditShield>,
                    config.help,
                )}
            </Field>
        );
    }

    if (config.type === 'textarea') {
        return (
            <Field {...anchor}>
                <FieldLabel htmlFor={config.name}>{inputLabel}</FieldLabel>
                {wrapHint(
                    <Textarea
                        id={config.name}
                        rows={4}
                        required={config.required}
                        placeholder={config.placeholder}
                        readOnly={readOnly}
                        onFocus={readOnly ? requestEdit : undefined}
                        value={String(value ?? '')}
                        onChange={(event) => onChange(event.target.value)}
                    />,
                    config.help,
                )}
            </Field>
        );
    }

    return (
        <Field {...anchor}>
            {/* Input sungguhan yang dibuat `readOnly`, bukan teks biasa. Karena elemennya
                tidak pernah dilepas, kursor hasil klik tetap berada di huruf yang diklik
                ketika mode berpindah — tidak ada fokus yang perlu dipulihkan. */}
            {wrapHint(
                <Input
                    id={config.name}
                    label={config.suffix ? `${inputLabel} (${config.suffix})` : inputLabel}
                    type={config.type === 'number' ? 'number' : config.type === 'date' ? 'date' : 'text'}
                    required={config.required}
                    min={config.min}
                    max={config.max}
                    step={config.step}
                    placeholder={config.placeholder}
                    readOnly={readOnly}
                    onFocus={readOnly ? requestEdit : undefined}
                    value={String(value ?? '')}
                    onChange={(event) => onChange(event.target.value)}
                />,
                config.help,
            )}
        </Field>
    );
}
