import { FormEvent, useEffect, useMemo, useRef, useState } from 'react';
import { Badge } from '@apperp/ui/badge';
import {
    CollapsibleSection,
    CollapsibleSectionGroup,
} from '@apperp/ui/collapsible-section';
import { Empty, EmptyDescription } from '@apperp/ui/empty';
import { Field, FieldError } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { api, errorMessage, newIdempotencyKey } from '../../api';
import DynamicField from '../DynamicField';
import GroupBookMatrix from '../GroupBookMatrix';
import JenisAsetAtribut from '../JenisAsetAtribut';
import { FieldValue, isVisible, payloadValue, valueFrom } from '../fields';
import { MasterConfig, MasterRecord, Permission, permission } from '../masters';
import PabrikanAsetCounters from './PabrikanAsetCounters';
import PabrikanModels from './PabrikanModels';
import { PabrikanAsetDetail } from './pabrikanAsetDetail';
import JenisAsetCounters from './JenisAsetCounters';
import JenisAsetModels from './JenisAsetModels';
import JenisAsetMaintenanceJobTypes from './JenisAsetMaintenanceJobTypes';
import MaintenanceJobTypeDetails from './MaintenanceJobTypeDetails';
import MaintenanceChecklistVariableValues from './MaintenanceChecklistVariableValues';
import MaintenanceChecklistTemplateLines from './MaintenanceChecklistTemplateLines';
import { JenisAsetDetail } from './jenisAsetDetail';
import { DetailSection, sectionsFor, summaryFor } from './sections';

export type DetailMode = 'view' | 'edit' | 'create';

/**
 * Panel kanan: satu record dibaca lebih dulu, lalu disunting.
 *
 * Seluruh state disemai sekali dari `record`. Itu aman karena induknya memasang komponen
 * ini dengan `key` berisi id record — berpindah record berarti komponen baru, bukan
 * komponen lama yang disinkronkan. Efek sinkronisasi justru berbahaya di sini: daftar
 * dimuat ulang setiap kali kueri berubah, sehingga record yang sama datang sebagai objek
 * baru dan akan menimpa ketikan yang sedang berjalan.
 */
export default function RecordDetailPane({
    formId,
    config,
    record,
    mode,
    permissions,
    canEdit,
    onRequestEdit,
    onDirtyChange,
    onSavingChange,
    onSaved,
}: {
    formId: string;
    config: MasterConfig;
    record: MasterRecord | null;
    mode: DetailMode;
    permissions: Permission[];
    canEdit: boolean;
    onRequestEdit: () => void;
    onDirtyChange: (dirty: boolean) => void;
    onSavingChange: (saving: boolean) => void;
    onSaved: (record: MasterRecord, created: boolean) => void;
}) {
    const readOnly = mode === 'view';
    const sections = useMemo(() => sectionsFor(config), [config]);
    const allFields = useMemo(
        () => sections.flatMap((section) => section.fields),
        [sections],
    );

    const [nama, setNama] = useState(() => record?.nama ?? '');
    const [values, setValues] = useState<Record<string, FieldValue>>(() =>
        Object.fromEntries(
            allFields.map((field) => [field.name, valueFrom(record, field)]),
        ),
    );
    const [error, setError] = useState('');
    const creationKey = useRef(newIdempotencyKey());
    const formRef = useRef<HTMLFormElement>(null);
    const [pendingFocus, setPendingFocus] = useState<string | null>(null);
    const [jenisAsetDetail, setJenisAsetDetail] =
        useState<JenisAsetDetail | null>(null);
    const [jenisAsetDetailLoading, setJenisAsetDetailLoading] = useState(false);
    const [jenisAsetDetailError, setJenisAsetDetailError] = useState('');
    const [pabrikanAsetDetail, setPabrikanAsetDetail] =
        useState<PabrikanAsetDetail | null>(null);
    const [pabrikanAsetDetailLoading, setPabrikanAsetDetailLoading] =
        useState(false);
    const [pabrikanAsetDetailError, setPabrikanAsetDetailError] = useState('');
    const [pabrikanAsetDetailVersion, setPabrikanAsetDetailVersion] =
        useState(0);

    useEffect(() => {
        if (config.resource !== 'jenis-aset' || !record?.id) {
            setJenisAsetDetail(null);
            setJenisAsetDetailLoading(false);
            setJenisAsetDetailError('');
            return;
        }

        let cancelled = false;
        setJenisAsetDetail(null);
        setJenisAsetDetailLoading(true);
        setJenisAsetDetailError('');
        api<{ data: JenisAsetDetail }>(`/jenis-aset/${record.id}/detail`)
            .then((result) => {
                if (!cancelled) setJenisAsetDetail(result.data);
            })
            .catch((caught) => {
                if (!cancelled)
                    setJenisAsetDetailError(
                        errorMessage(
                            caught,
                            'Detail jenis aset belum dapat dimuat.',
                        ),
                    );
            })
            .finally(() => {
                if (!cancelled) setJenisAsetDetailLoading(false);
            });

        return () => {
            cancelled = true;
        };
    }, [config.resource, record?.id]);

    useEffect(() => {
        if (config.resource !== 'pabrikan-aset' || !record?.id) {
            setPabrikanAsetDetail(null);
            setPabrikanAsetDetailLoading(false);
            setPabrikanAsetDetailError('');
            return;
        }

        let cancelled = false;
        setPabrikanAsetDetail(null);
        setPabrikanAsetDetailLoading(true);
        setPabrikanAsetDetailError('');
        api<{ data: PabrikanAsetDetail }>(`/pabrikan-aset/${record.id}/detail`)
            .then((result) => {
                if (!cancelled) setPabrikanAsetDetail(result.data);
            })
            .catch((caught) => {
                if (!cancelled)
                    setPabrikanAsetDetailError(
                        errorMessage(
                            caught,
                            'Detail pabrikan belum dapat dimuat.',
                        ),
                    );
            })
            .finally(() => {
                if (!cancelled) setPabrikanAsetDetailLoading(false);
            });

        return () => {
            cancelled = true;
        };
    }, [config.resource, pabrikanAsetDetailVersion, record?.id]);

    function markDirty() {
        onDirtyChange(true);
    }

    /** Pindah ke mode sunting karena pengguna menyentuh satu field, lalu fokus ke situ. */
    function enterEdit(name: string) {
        if (!readOnly || !canEdit) return;
        setPendingFocus(name);
        onRequestEdit();
    }

    /**
     * Untuk input teks fokusnya sudah benar sejak awal — elemennya tidak pernah dilepas.
     * Yang butuh pemulihan adalah Select dan Switch, yang tadi tertutup tombol perisai:
     * begitu perisainya hilang, fokus dipindahkan ke kontrol aslinya.
     */
    useEffect(() => {
        if (readOnly || !pendingFocus) return;
        const scope = formRef.current?.querySelector(
            `[data-field-name="${CSS.escape(pendingFocus)}"]`,
        );
        scope
            ?.querySelector<HTMLElement>(
                'input:not([type="hidden"]), textarea, button',
            )
            ?.focus();
        setPendingFocus(null);
    }, [readOnly, pendingFocus]);

    async function submit(event: FormEvent) {
        event.preventDefault();
        onSavingChange(true);
        setError('');
        try {
            const payload: Record<string, unknown> = { nama };
            for (const field of allFields) {
                // Field yang sedang tersembunyi tidak dikirim, supaya mengganti satu pilihan
                // tidak diam-diam menyimpan nilai milik pilihan sebelumnya.
                if (isVisible(field, values))
                    payload[field.name] = payloadValue(
                        field,
                        values[field.name],
                    );
            }
            const saved = await api<{ data: MasterRecord }>(
                `/${config.resource}${record ? `/${record.id}` : ''}`,
                {
                    method: record ? 'PATCH' : 'POST',
                    headers: record
                        ? undefined
                        : { 'Idempotency-Key': creationKey.current },
                    body: JSON.stringify(payload),
                },
            );
            onDirtyChange(false);
            // Kirim record lengkap agar induk dapat langsung mengisi daftar dan panel
            // detail. Mengirim ID saja membuat panel sempat dipasang tanpa record;
            // state nama lalu terlanjur dimulai kosong walau API sudah mengembalikan nama.
            onSaved(saved.data, !record);
        } catch (caught) {
            setError(errorMessage(caught, 'Data belum dapat disimpan.'));
        } finally {
            onSavingChange(false);
        }
    }

    if (!record && mode !== 'create') {
        return (
            <div className="flex min-h-0 items-center justify-center p-10">
                <Empty>
                    <EmptyDescription>
                        Pilih satu {config.singular} di sebelah kiri.
                    </EmptyDescription>
                </Empty>
            </div>
        );
    }

    function sectionBody(section: DetailSection) {
        if (section.manufacturerDetails) {
            return (
                <div className="space-y-5">
                    <PabrikanAsetCounters
                        detail={pabrikanAsetDetail}
                        loading={pabrikanAsetDetailLoading}
                        error={pabrikanAsetDetailError}
                    />
                    <div className="grid gap-5 pt-1 sm:grid-cols-2">
                        {section.fields
                            .filter((field) => isVisible(field, values))
                            .map((field) => (
                                <DynamicField
                                    key={field.name}
                                    config={field}
                                    value={values[field.name]}
                                    readOnly={readOnly}
                                    onRequestEdit={enterEdit}
                                    onChange={(next) => {
                                        markDirty();
                                        setValues((current) => ({
                                            ...current,
                                            [field.name]: next,
                                        }));
                                    }}
                                />
                            ))}
                    </div>
                </div>
            );
        }

        if (section.manufacturerModels) {
            return record ? (
                <PabrikanModels
                    manufacturer={record}
                    canReadModels={permissions.includes(
                        permission('model-aset', 'read'),
                    )}
                    canReadJenis={permissions.includes(
                        permission('jenis-aset', 'read'),
                    )}
                    canCreate={permissions.includes(
                        permission('model-aset', 'create'),
                    )}
                    canUpdate={permissions.includes(
                        permission('model-aset', 'update'),
                    )}
                    canArchive={permissions.includes(
                        permission('model-aset', 'archive'),
                    )}
                    canReadAssets={permissions.includes(
                        'management-aset.aset.read',
                    )}
                    onChanged={() =>
                        setPabrikanAsetDetailVersion((current) => current + 1)
                    }
                />
            ) : (
                <p className="text-muted-foreground text-sm">
                    Model dapat ditambahkan setelah pabrikan disimpan.
                </p>
            );
        }

        if (section.books) {
            return record ? (
                <GroupBookMatrix
                    groupId={record.id}
                    canEdit={!readOnly && canEdit}
                />
            ) : (
                <p className="text-muted-foreground text-sm">
                    Buku penyusutan dapat diatur setelah {config.singular}{' '}
                    disimpan.
                </p>
            );
        }

        if (section.counters) {
            return <JenisAsetCounters detail={jenisAsetDetail} />;
        }

        if (section.attributes) {
            return record ? (
                <JenisAsetAtribut
                    jenisAsetId={record.id}
                    canEdit={!readOnly && canEdit}
                />
            ) : (
                <p className="text-muted-foreground text-sm">
                    Atribut dapat diatur setelah {config.singular} disimpan.
                </p>
            );
        }

        if (section.models) {
            return record ? (
                <JenisAsetModels
                    jenisAsetId={record.id}
                    detail={jenisAsetDetail}
                    loading={jenisAsetDetailLoading}
                    error={jenisAsetDetailError}
                    canEdit={!readOnly && canEdit}
                />
            ) : (
                <p className="text-muted-foreground text-sm">
                    Model dapat diatur setelah jenis aset disimpan.
                </p>
            );
        }

        if (section.maintenanceJobType) {
            return record ? (
                <MaintenanceJobTypeDetails
                    jobTypeId={record.id}
                    canEdit={!readOnly && canEdit}
                />
            ) : (
                <p className="text-muted-foreground text-sm">
                    Rincian maintenance dapat diatur setelah jenis pekerjaan
                    disimpan.
                </p>
            );
        }

        if (section.checklistVariableValues) {
            return record ? (
                <MaintenanceChecklistVariableValues
                    variableId={record.id}
                    canEdit={!readOnly && canEdit}
                />
            ) : (
                <p className="text-muted-foreground text-sm">
                    Nilai checklist dapat diatur setelah variabel disimpan.
                </p>
            );
        }

        if (section.checklistTemplateLines) {
            return record ? (
                <MaintenanceChecklistTemplateLines
                    templateId={record.id}
                    canEdit={!readOnly && canEdit}
                />
            ) : (
                <p className="text-muted-foreground text-sm">
                    Baris checklist dapat diatur setelah template disimpan.
                </p>
            );
        }

        if (section.maintenanceJobTypes) {
            return record ? (
                <JenisAsetMaintenanceJobTypes
                    jenisAsetId={record.id}
                    canEdit={!readOnly && canEdit}
                />
            ) : (
                <p className="text-muted-foreground text-sm">
                    Jenis pekerjaan dapat dikaitkan setelah jenis aset disimpan.
                </p>
            );
        }

        if (section.placeholder) {
            return (
                <Empty>
                    <EmptyDescription>{section.placeholder}</EmptyDescription>
                </Empty>
            );
        }

        return (
            <div className="grid gap-5 pt-1 sm:grid-cols-2">
                {section.fields
                    .filter((field) => isVisible(field, values))
                    .map((field) => (
                        <DynamicField
                            key={field.name}
                            config={field}
                            value={values[field.name]}
                            readOnly={readOnly}
                            onRequestEdit={enterEdit}
                            onChange={(next) => {
                                markDirty();
                                setValues((current) => ({
                                    ...current,
                                    [field.name]: next,
                                }));
                            }}
                        />
                    ))}
            </div>
        );
    }

    return (
        <form
            id={formId}
            ref={formRef}
            onSubmit={submit}
            className="[&_[data-readonly]:hover]:bg-muted/40 flex min-h-0 flex-col [&_[data-readonly]]:rounded-md [&_[data-readonly]]:transition-colors"
        >
            <div className="shrink-0 border-b px-6 py-4">
                <div className="flex flex-wrap items-end gap-x-6 gap-y-3">
                    <div className="w-60">
                        {/* Kode diterbitkan Number Sequence, jadi memang tidak ada yang bisa
                            disunting di sini — `disabled` tepat, tidak seperti field lain. */}
                        <Input
                            id="kode"
                            label={config.kodeLabel}
                            value={
                                record?.kode ?? 'Dibuat otomatis saat disimpan'
                            }
                            disabled
                        />
                    </div>
                    <div
                        className="min-w-64 flex-1"
                        data-field-name="nama"
                        data-readonly={readOnly || undefined}
                    >
                        <Input
                            id="nama"
                            label={config.namaLabel}
                            required
                            maxLength={150}
                            readOnly={readOnly}
                            onFocus={
                                readOnly ? () => enterEdit('nama') : undefined
                            }
                            value={nama}
                            onChange={(event) => {
                                markDirty();
                                setNama(event.target.value);
                            }}
                        />
                    </div>
                    <Badge variant={values.aktif ? 'default' : 'secondary'}>
                        {values.aktif ? 'Aktif' : 'Tidak aktif'}
                    </Badge>
                </div>
            </div>

            <div className="min-h-0 flex-1 overflow-y-auto px-6 py-4">
                {(config.parents?.length ?? 0) > 0 && (
                    <Field data-invalid="true" className="mb-4">
                        <FieldError>
                            Master ini memiliki induk, dan panel detail belum
                            merendernya. Pakai tampilan daftar sampai dukungan
                            induk ditambahkan.
                        </FieldError>
                    </Field>
                )}
                <CollapsibleSectionGroup
                    defaultValue={sections
                        .filter((section) => section.defaultOpen)
                        .map((section) => section.id)}
                >
                    {sections.map((section) => (
                        <CollapsibleSection
                            key={section.id}
                            value={section.id}
                            title={section.title}
                            summary={summaryFor(section, values)}
                        >
                            {sectionBody(section)}
                        </CollapsibleSection>
                    ))}
                </CollapsibleSectionGroup>
                {error && <FieldError className="pt-4">{error}</FieldError>}
            </div>
        </form>
    );
}
