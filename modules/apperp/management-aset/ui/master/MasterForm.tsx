import type { FormEvent, ReactNode } from 'react';
import { useMemo, useRef, useState } from 'react';
import { Button } from '@apperp/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
    FieldTitle,
} from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { Select } from '@apperp/ui/select';
import {
    Sheet,
    SheetContent,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@apperp/ui/sheet';
import { Switch } from '@apperp/ui/switch';
import { Textarea } from '@apperp/ui/textarea';
import { api, errorMessage, newIdempotencyKey } from '../api';
import DynamicField from './DynamicField';
import type { FieldValue } from './fields';
import { isVisible, payloadValue, valueFrom } from './fields';
import type {
    MasterConfig,
    MasterParentConfig,
    MasterRecord,
    ParentSummary,
} from './masters';
import {
    MANUAL_CODE_PATTERN,
    normalizeManualCode,
    parentIdOf,
    parentSummaryOf,
} from './masters';

type FormValue = { nama: string; keterangan: string; aktif: boolean };

/** Label pilihan induk; satu bentuk untuk seluruh master agar tidak ada varian pemisah. */
function optionLabel(option: ParentSummary): string {
    return `${option.kode} — ${option.nama}`;
}

export default function MasterForm({
    config,
    value,
    parentOptions,
    parentOptionsError,
    onClose,
    onSaved,
    extraSection,
}: {
    config: MasterConfig;
    value: MasterRecord | null;
    /** Pilihan induk per kolom foreign key. */
    parentOptions: Record<string, ParentSummary[]>;
    /** Pesan kegagalan pemuatan pilihan, per kolom foreign key. */
    parentOptionsError: Record<string, string>;
    onClose: () => void;
    onSaved: (record: MasterRecord, created: boolean) => void;
    /** Bagian tambahan di bawah field, misalnya matriks yang disunting di dalam form ini. */
    extraSection?: ReactNode;
}) {
    const parents = useMemo(() => config.parents ?? [], [config.parents]);
    const [form, setForm] = useState<FormValue>(() => ({
        nama: value?.nama ?? '',
        keterangan: value?.keterangan ?? '',
        aktif: value?.aktif ?? true,
    }));
    const [parentIds, setParentIds] = useState<Record<string, string>>(() =>
        Object.fromEntries(
            parents.map((parent) => [
                parent.field,
                value ? parentIdOf(value, parent) : '',
            ]),
        ),
    );
    const extraFields = useMemo(
        () => config.extraFields ?? [],
        [config.extraFields],
    );
    const isAsetLocation = config.resource === 'lokasi-aset';
    const [extra, setExtra] = useState<Record<string, FieldValue>>(() =>
        Object.fromEntries(
            extraFields.map((field) => [field.name, valueFrom(value, field)]),
        ),
    );
    const [error, setError] = useState('');
    const [saving, setSaving] = useState(false);
    const creationKey = useRef(newIdempotencyKey());
    const manualCode = !value ? config.manualCode : undefined;
    const [kode, setKode] = useState('');
    const sheetContentRef = useRef<HTMLDivElement>(null);

    /**
     * Induk yang sedang dipakai record ini tetap dapat dipilih meski sudah diarsipkan,
     * supaya menyunting field lain tidak diam-diam memutus tautan induknya.
     */
    const optionsOf = useMemo(() => {
        const resolved: Record<string, ParentSummary[]> = {};

        for (const parent of parents) {
            const available = parentOptions[parent.field] ?? [];
            const current = value ? parentSummaryOf(value, parent) : null;
            resolved[parent.field] =
                !current || available.some((option) => option.id === current.id)
                    ? available
                    : [current, ...available];
        }

        return resolved;
    }, [parents, parentOptions, value]);

    async function submit(event: FormEvent) {
        event.preventDefault();
        const missing = parents.find(
            (parent) => parent.required !== false && !parentIds[parent.field],
        );

        if (missing) {
            setError(`Pilih ${missing.label.toLowerCase()} terlebih dahulu.`);

            return;
        }

        setSaving(true);
        setError('');

        try {
            const saved = await api<{ data: MasterRecord }>(
                `/${config.resource}${value ? `/${value.id}` : ''}`,
                {
                    method: value ? 'PATCH' : 'POST',
                    headers: value
                        ? undefined
                        : { 'Idempotency-Key': creationKey.current },
                    body: JSON.stringify({
                        ...(manualCode ? { kode } : {}),
                        nama: form.nama,
                        keterangan: form.keterangan,
                        aktif: form.aktif,
                        ...Object.fromEntries(
                            parents.map((parent) => [
                                parent.field,
                                parentIds[parent.field] || null,
                            ]),
                        ),
                        // Field yang sedang tersembunyi tidak dikirim, supaya mengganti metode
                        // tidak diam-diam menyimpan nilai milik metode sebelumnya.
                        ...Object.fromEntries(
                            extraFields
                                .filter((field) => isVisible(field, extra))
                                .map((field) => [
                                    field.name,
                                    payloadValue(field, extra[field.name]),
                                ]),
                        ),
                    }),
                },
            );
            onSaved(saved.data, !value);
        } catch (caught) {
            setError(errorMessage(caught, 'Data belum dapat disimpan.'));
        } finally {
            setSaving(false);
        }
    }

    function parentField(parent: MasterParentConfig) {
        const options = optionsOf[parent.field] ?? [];
        const selected = options.find(
            (option) => option.id === parentIds[parent.field],
        );
        const loadError = parentOptionsError[parent.field] ?? '';

        return (
            <Field key={parent.field} data-invalid={Boolean(loadError)}>
                <Select
                    label={parent.label}
                    required={parent.required !== false}
                    items={options.map(optionLabel)}
                    value={selected ? optionLabel(selected) : undefined}
                    placeholder={`Pilih ${parent.label.toLowerCase()}`}
                    searchPlaceholder={`Cari ${parent.label.toLowerCase()}`}
                    emptyMessage={`${parent.label} tidak ditemukan.`}
                    ariaLabel={`Pilih ${parent.label.toLowerCase()}`}
                    portalContainer={sheetContentRef}
                    onValueChange={(item) =>
                        setParentIds({
                            ...parentIds,
                            [parent.field]:
                                options.find(
                                    (option) => optionLabel(option) === item,
                                )?.id ?? '',
                        })
                    }
                />
                {loadError && <FieldDescription>{loadError}</FieldDescription>}
            </Field>
        );
    }

    return (
        <Sheet open onOpenChange={(open) => !open && onClose()}>
            <SheetContent
                ref={sheetContentRef}
                side="right"
                className="w-full gap-0 p-0 sm:max-w-xl"
            >
                <SheetHeader className="border-b px-6 py-5 pr-12">
                    <SheetTitle>
                        {value ? 'Ubah' : 'Tambah'} {config.singular}
                    </SheetTitle>
                </SheetHeader>
                <form
                    className="flex min-h-0 flex-1 flex-col"
                    onSubmit={submit}
                >
                    <div className="min-h-0 flex-1 overflow-y-auto px-6 py-5">
                        <FieldGroup>
                            {manualCode ? (
                                <Field>
                                    <Input
                                        id="code"
                                        label={config.kodeLabel}
                                        required
                                        autoFocus
                                        maxLength={30}
                                        pattern={MANUAL_CODE_PATTERN}
                                        placeholder={manualCode.placeholder}
                                        className="font-mono"
                                        value={kode}
                                        onChange={(event) =>
                                            setKode(
                                                normalizeManualCode(
                                                    event.target.value,
                                                ),
                                            )
                                        }
                                    />
                                    <FieldDescription>
                                        {manualCode.help}
                                    </FieldDescription>
                                </Field>
                            ) : (
                                <Field data-disabled="true">
                                    <Input
                                        id="code"
                                        label={config.kodeLabel}
                                        value={
                                            value?.kode ??
                                            'Dibuat otomatis saat disimpan'
                                        }
                                        disabled
                                    />
                                </Field>
                            )}
                            <Field>
                                <Input
                                    id="name"
                                    label={config.namaLabel}
                                    autoFocus={!manualCode}
                                    required
                                    maxLength={150}
                                    value={form.nama}
                                    onChange={(event) =>
                                        setForm({
                                            ...form,
                                            nama: event.target.value,
                                        })
                                    }
                                />
                            </Field>
                            {/* Induk dirender sejajar: tidak ada yang menyaring pilihan yang lain. */}
                            {parents.map(parentField)}
                            {isAsetLocation && (
                                <Field>
                                    <FieldTitle>Dimensi keuangan</FieldTitle>
                                    <div
                                        className="bg-muted/40 rounded-md border border-dashed px-3 py-2"
                                        role="status"
                                    >
                                        <p className="text-sm font-medium">
                                            Belum tersedia
                                        </p>
                                    </div>
                                    <FieldDescription>
                                        Pengaturan ini menunggu Finance. Lokasi
                                        fisik tetap dapat disimpan tanpa
                                        pengaturan ini.
                                    </FieldDescription>
                                </Field>
                            )}
                            {extraFields
                                .filter((field) => isVisible(field, extra))
                                .map((field) =>
                                    value?.data_type_locked &&
                                    field.name === 'data_type' ? (
                                        <Field
                                            key={field.name}
                                            data-disabled="true"
                                        >
                                            <Input
                                                label="Tipe data"
                                                value={
                                                    field.options?.find(
                                                        (option) =>
                                                            option.value ===
                                                            extra[field.name],
                                                    )?.label ??
                                                    String(
                                                        extra[field.name] ?? '',
                                                    )
                                                }
                                                disabled
                                            />
                                            <FieldDescription>
                                                Tipe data terkunci karena
                                                atribut ini sudah pernah diisi
                                                pada aset. Buat tipe atribut
                                                baru bila bentuk datanya
                                                berbeda.
                                            </FieldDescription>
                                        </Field>
                                    ) : (
                                        <DynamicField
                                            key={field.name}
                                            config={field}
                                            value={extra[field.name]}
                                            onChange={(next) =>
                                                setExtra((current) => ({
                                                    ...current,
                                                    [field.name]: next,
                                                }))
                                            }
                                            portalContainer={sheetContentRef}
                                        />
                                    ),
                                )}
                            <Field>
                                <FieldLabel htmlFor="description">
                                    Keterangan
                                </FieldLabel>
                                <Textarea
                                    id="description"
                                    rows={4}
                                    maxLength={2000}
                                    value={form.keterangan}
                                    onChange={(event) =>
                                        setForm({
                                            ...form,
                                            keterangan: event.target.value,
                                        })
                                    }
                                />
                            </Field>
                            <Field orientation="horizontal">
                                <Switch
                                    id="active"
                                    checked={form.aktif}
                                    onCheckedChange={(checked) =>
                                        setForm({ ...form, aktif: checked })
                                    }
                                />
                                <FieldLabel htmlFor="active">
                                    Data aktif dan dapat dipilih
                                </FieldLabel>
                            </Field>
                            {error && <FieldError>{error}</FieldError>}
                        </FieldGroup>
                    </div>
                    {extraSection}
                    <SheetFooter className="border-t px-6 py-4 sm:flex-row sm:justify-end">
                        <Button
                            variant="outline"
                            type="button"
                            onClick={onClose}
                        >
                            Batal
                        </Button>
                        <Button disabled={saving}>
                            {saving ? 'Menyimpan…' : 'Simpan'}
                        </Button>
                    </SheetFooter>
                </form>
            </SheetContent>
        </Sheet>
    );
}
