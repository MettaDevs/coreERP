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
import LokasiAsetFields from './LokasiAsetFields';
import type { MasterConfig, MasterParentConfig, MasterRecord } from './masters';
import { parentIdOf, parentSummaryOf } from './masters';
import MasterSelect from './MasterSelect';
import { changeProfileField } from './serviceLife';
import ServiceLifeFields from './ServiceLifeFields';
import { useNumberPolicy } from './useNumberPolicy';

type FormValue = { nama: string; keterangan: string; aktif: boolean };

export default function MasterForm({
    config,
    value,
    onClose,
    onSaved,
    extraSection,
}: {
    config: MasterConfig;
    value: MasterRecord | null;
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
    const [manualCode, setManualCode] = useState('');
    const {
        allowManual,
        allowAutomatic,
        error: numberPolicyError,
        loading: numberPolicyLoading,
    } = useNumberPolicy(config.resource, !value);
    const [extra, setExtra] = useState<Record<string, FieldValue>>(() =>
        Object.fromEntries(
            extraFields.map((field) => [field.name, valueFrom(value, field)]),
        ),
    );
    const [error, setError] = useState('');
    const [saving, setSaving] = useState(false);
    const creationKey = useRef(newIdempotencyKey());
    const sheetContentRef = useRef<HTMLDivElement>(null);

    async function submit(event: FormEvent) {
        event.preventDefault();

        if (!value && (numberPolicyLoading || numberPolicyError)) {
            setError(
                numberPolicyError || 'Tunggu pengaturan kode selesai dimuat.',
            );

            return;
        }

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
                        // Versi dibaca dari `value` saat menyimpan, bukan disalin ke state form:
                        // rincian di bawah form dapat menaikkannya lebih dahulu.
                        ...(value ? { version: value.version } : {}),
                        ...(!value && allowManual
                            ? { kode: manualCode.trim() || null }
                            : {}),
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
        return (
            <Field key={parent.field}>
                <MasterSelect
                    resource={parent.resource}
                    label={parent.label}
                    required={parent.required !== false}
                    value={parentIds[parent.field] ?? ''}
                    initialValue={value ? parentSummaryOf(value, parent) : null}
                    portalContainer={sheetContentRef}
                    emptyLabel={
                        parent.required === false ? 'Tidak dipilih' : undefined
                    }
                    onValueChange={(id) =>
                        setParentIds((current) => ({
                            ...current,
                            [parent.field]: id,
                        }))
                    }
                />
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
                            <Field
                                data-disabled={Boolean(value) || !allowManual}
                            >
                                <Input
                                    id="code"
                                    label={config.kodeLabel}
                                    value={
                                        value?.kode ??
                                        (allowManual
                                            ? manualCode
                                            : 'Dibuat otomatis saat disimpan')
                                    }
                                    placeholder={
                                        allowAutomatic
                                            ? 'Kosongkan untuk membuat kode otomatis'
                                            : 'Masukkan kode'
                                    }
                                    required={!value && !allowAutomatic}
                                    maxLength={50}
                                    disabled={Boolean(value) || !allowManual}
                                    onChange={(event) =>
                                        setManualCode(event.target.value)
                                    }
                                />
                                {!value && allowManual && (
                                    <FieldDescription>
                                        {allowAutomatic
                                            ? 'Isi kode sendiri, atau kosongkan untuk membuatnya otomatis.'
                                            : 'Isi kode yang mudah dikenali.'}
                                    </FieldDescription>
                                )}
                                {numberPolicyError && (
                                    <FieldError>{numberPolicyError}</FieldError>
                                )}
                            </Field>

                            <Field>
                                <Input
                                    id="name"
                                    label={config.namaLabel}
                                    autoFocus
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
                                <LokasiAsetFields
                                    fields={extraFields}
                                    value={value}
                                    extra={extra}
                                    parentId={parentIds.parent_id ?? ''}
                                    onChange={(name, next) =>
                                        setExtra((current) => ({
                                            ...current,
                                            [name]: next,
                                        }))
                                    }
                                    portalContainer={sheetContentRef}
                                />
                            )}
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
                                .filter(
                                    (field) =>
                                        !isAsetLocation &&
                                        isVisible(field, extra),
                                )
                                .map((field) =>
                                    config.resource === 'profil-penyusutan' &&
                                    field.name === 'useful_life_periods' ? (
                                        <ServiceLifeFields
                                            key={field.name}
                                            value={extra[field.name]}
                                            frequency={extra.frequency}
                                            required={field.required}
                                            onChange={(next) =>
                                                setExtra((current) => ({
                                                    ...current,
                                                    [field.name]: next,
                                                }))
                                            }
                                        />
                                    ) : value?.data_type_locked &&
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
                                                pada aset. Buat komponen atribut
                                                aset baru bila bentuk datanya
                                                berbeda.
                                            </FieldDescription>
                                        </Field>
                                    ) : (
                                        <DynamicField
                                            key={field.name}
                                            config={field}
                                            value={extra[field.name]}
                                            onChange={(next) =>
                                                setExtra((current) =>
                                                    config.resource ===
                                                    'profil-penyusutan'
                                                        ? changeProfileField(
                                                              current,
                                                              field.name,
                                                              next,
                                                          )
                                                        : {
                                                              ...current,
                                                              [field.name]:
                                                                  next,
                                                          },
                                                )
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
