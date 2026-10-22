import { useEffect, useMemo, useRef, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { ActionButton } from '@apperp/ui/action-button';

import { Field, FieldLabel, FieldTitle } from '@apperp/ui/field';
import { FormControl, FormGrid } from '@apperp/ui/form-control';
import { FormErrorSummary, createSaveFeedback } from '@apperp/ui/form-feedback';
import type { FormFeedback } from '@apperp/ui/form-feedback';
import { Input } from '@apperp/ui/input';
import type { PageDialogNavigation } from '@apperp/ui/page-dialog';
import {
    PageDialog,
    PageDialogContent,
    PageDialogBody,
    PageDialogToolbar,
} from '@apperp/ui/page-dialog';
import { Switch } from '@apperp/ui/switch';
import { Textarea } from '@apperp/ui/textarea';
import { useRecordTransition } from '@apperp/ui/use-record-transition';
import type { ApiValidationErrors } from '../api';
import { api, ApiError, saveErrorFeedback, newIdempotencyKey } from '../api';
import {
    RecordCardHeader,
    recordQueryUrl,
} from '../transactions/_shared/record-card';
import type { DraftMasterDetailsValue } from './DraftMasterDetails';
import DraftMasterDetails, { draftMasterPayload } from './DraftMasterDetails';
import DynamicField from './DynamicField';
import type { FieldValue } from './fields';
import { isVisible, payloadValue, valueFrom } from './fields';
import LokasiAsetFields from './LokasiAsetFields';
import type {
    MasterConfig,
    MasterParentConfig,
    MasterRecord,
    Permission,
} from './masters';
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
    onNew,
    onDelete,
    navigation,
    onBusyChange,
    onDirtyChange,
    canStageDetails,
    permissions,
    initialParentIds = {},
}: {
    config: MasterConfig;
    initialParentIds?: Record<string, string>;
    canStageDetails: boolean;
    permissions: Permission[];
    value: MasterRecord | null;
    onClose: () => void;
    onSaved: (record: MasterRecord, created: boolean) => void | Promise<void>;
    /** Bagian tambahan di bawah field, misalnya matriks yang disunting di dalam form ini. */
    extraSection?: ReactNode;
    onNew?: () => void;
    onDelete?: () => void;
    navigation?: PageDialogNavigation;
    onBusyChange?: (busy: boolean) => void;
    onDirtyChange?: (dirty: boolean) => void;
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
                value
                    ? parentIdOf(value, parent)
                    : (initialParentIds[parent.field] ?? ''),
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
    const [draftDetails, setDraftDetails] = useState<DraftMasterDetailsValue>(
        {},
    );
    const [detailErrors, setDetailErrors] = useState<ApiValidationErrors>({});
    const [initialSnapshot] = useState(() =>
        JSON.stringify({ form, parentIds, extra, manualCode }),
    );
    const dirty =
        JSON.stringify({ form, parentIds, extra, manualCode }) !==
            initialSnapshot ||
        Object.values(draftDetails).some((rows) => rows && rows.length > 0);
    const [feedback, setFeedback] = useState<FormFeedback | null>(null);
    const [saving, setSaving] = useState(false);
    const creationKey = useRef(newIdempotencyKey());
    const contentRef = useRef<HTMLDivElement>(null);
    const setError = (message: string) =>
        setFeedback(
            message
                ? createSaveFeedback({
                      operation: 'Data belum dapat disimpan.',
                      message,
                  })
                : null,
        );
    const transition = useRecordTransition(dirty, saving);
    useEffect(() => {
        onBusyChange?.(saving);

        return () => onBusyChange?.(false);
    }, [saving, onBusyChange]);
    useEffect(() => {
        onDirtyChange?.(dirty);

        return () => onDirtyChange?.(false);
    }, [dirty, onDirtyChange]);

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
        setDetailErrors({});

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
                        ...(!value
                            ? {
                                  details: draftMasterPayload(
                                      config.resource,
                                      draftDetails,
                                      extra,
                                  ),
                              }
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
            await onSaved(saved.data, !value);
        } catch (caught) {
            if (caught instanceof ApiError) {
                setDetailErrors(caught.validationErrors);
            }

            setFeedback(
                saveErrorFeedback(caught, 'Data belum dapat disimpan.'),
            );
        } finally {
            setSaving(false);
        }
    }

    function parentField(parent: MasterParentConfig) {
        return (
            <FormControl
                key={parent.field}
                data-error-field={parent.field}
                error={detailErrors[parent.field]?.[0]}
            >
                <MasterSelect
                    resource={parent.resource}
                    disabled={!value && Boolean(initialParentIds[parent.field])}
                    label={parent.label}
                    required={parent.required !== false}
                    value={parentIds[parent.field] ?? ''}
                    initialValue={value ? parentSummaryOf(value, parent) : null}
                    portalContainer={contentRef}
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
            </FormControl>
        );
    }

    return (
        <>
            {' '}
            <PageDialog
                open
                onOpenChange={(open) => !open && transition.request(onClose)}
            >
                <PageDialogContent
                    ref={contentRef}
                    aria-describedby={undefined}
                    title={config.title}
                    busy={saving}
                    onNew={onNew ? () => transition.request(onNew) : undefined}
                    navigation={value ? navigation : undefined}
                >
                    <RecordCardHeader
                        recordUrl={
                            value
                                ? recordQueryUrl(config.resource, value.id)
                                : undefined
                        }
                        recordTitle={
                            value
                                ? `${value.kode} · ${form.nama}`
                                : form.nama || 'Baru'
                        }
                        editing
                        onDelete={value ? onDelete : undefined}
                    />
                    <form
                        noValidate
                        className="flex min-h-0 flex-1 flex-col"
                        onSubmit={submit}
                    >
                        <PageDialogToolbar>
                            <ActionButton
                                action="save"
                                type="submit"
                                disabled={saving}
                                pending={saving}
                            />
                            <ActionButton
                                action="cancel"
                                disabled={saving}
                                type="button"
                                onClick={() => transition.request(onClose)}
                            />
                        </PageDialogToolbar>
                        <FormErrorSummary
                            feedback={feedback}
                            scopeRef={contentRef}
                        />
                        <PageDialogBody
                            className="space-y-4"
                            scroll-region="true"
                        >
                            <FormGrid>
                                <FormControl
                                    data-error-field="kode"
                                    data-disabled={
                                        Boolean(value) || !allowManual
                                    }
                                    hint={
                                        !value && allowManual
                                            ? allowAutomatic
                                                ? 'Isi kode sendiri, atau kosongkan untuk membuatnya otomatis.'
                                                : 'Isi kode yang mudah dikenali.'
                                            : undefined
                                    }
                                    error={numberPolicyError}
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
                                        disabled={
                                            Boolean(value) || !allowManual
                                        }
                                        onChange={(event) =>
                                            setManualCode(event.target.value)
                                        }
                                    />
                                </FormControl>

                                <FormControl
                                    data-error-field="nama"
                                    error={detailErrors.nama?.[0]}
                                >
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
                                </FormControl>
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
                                        portalContainer={contentRef}
                                    />
                                )}
                                {isAsetLocation && (
                                    <FormControl
                                        hint={
                                            <>
                                                Pengaturan ini menunggu Finance.
                                                Lokasi fisik tetap dapat
                                                disimpan tanpa pengaturan ini.
                                            </>
                                        }
                                    >
                                        <FieldTitle>
                                            Dimensi keuangan
                                        </FieldTitle>
                                        <div
                                            className="bg-muted/40 rounded-md border border-dashed px-3 py-2"
                                            role="status"
                                        >
                                            <p className="text-sm font-medium">
                                                Belum tersedia
                                            </p>
                                        </div>
                                    </FormControl>
                                )}
                                {extraFields
                                    .filter(
                                        (field) =>
                                            !isAsetLocation &&
                                            isVisible(field, extra),
                                    )
                                    .map((field) =>
                                        config.resource ===
                                            'profil-penyusutan' &&
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
                                            <FormControl
                                                key={field.name}
                                                data-disabled="true"
                                                hint="Tipe data terkunci karena atribut ini sudah pernah diisi pada aset. Buat komponen atribut aset baru bila bentuk datanya berbeda."
                                            >
                                                <Input
                                                    label="Tipe data"
                                                    value={
                                                        field.options?.find(
                                                            (option) =>
                                                                option.value ===
                                                                extra[
                                                                    field.name
                                                                ],
                                                        )?.label ??
                                                        String(
                                                            extra[field.name] ??
                                                                '',
                                                        )
                                                    }
                                                    disabled
                                                />
                                            </FormControl>
                                        ) : (
                                            <DynamicField
                                                key={field.name}
                                                error={
                                                    detailErrors[
                                                        field.name
                                                    ]?.[0]
                                                }
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
                                                portalContainer={contentRef}
                                            />
                                        ),
                                    )}
                                <FormControl>
                                    <Textarea
                                        id="description"
                                        label="Keterangan"
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
                                </FormControl>
                                <FormControl>
                                    <Field orientation="horizontal">
                                        <Switch
                                            id="active"
                                            checked={form.aktif}
                                            onCheckedChange={(checked) =>
                                                setForm({
                                                    ...form,
                                                    aktif: checked,
                                                })
                                            }
                                        />
                                        <FieldLabel htmlFor="active">
                                            Data aktif dan dapat dipilih
                                        </FieldLabel>
                                    </Field>
                                </FormControl>
                            </FormGrid>
                            {!value && (
                                <DraftMasterDetails
                                    resource={config.resource}
                                    canEdit={canStageDetails}
                                    permissions={permissions}
                                    value={draftDetails}
                                    onChange={setDraftDetails}
                                    parentValues={extra}
                                    errors={detailErrors}
                                    portalContainer={contentRef}
                                />
                            )}
                            {extraSection}
                        </PageDialogBody>
                    </form>
                </PageDialogContent>
            </PageDialog>
            {transition.confirmation}
        </>
    );
}
