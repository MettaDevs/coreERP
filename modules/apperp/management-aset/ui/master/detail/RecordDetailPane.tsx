import { Badge } from '@apperp/ui/badge';
import {
    CollapsibleSection,
    CollapsibleSectionGroup,
} from '@apperp/ui/collapsible-section';
import { Empty, EmptyDescription } from '@apperp/ui/empty';
import { Field, FieldDescription, FieldError } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { RecordEditBoundary } from '@apperp/ui/record-edit-boundary';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { api, errorMessage, newIdempotencyKey } from '../../api';
import DynamicField from '../DynamicField';
import type { FieldValue } from '../fields';
import { isVisible, payloadValue, valueFrom } from '../fields';
import GroupBookMatrix from '../GroupBookMatrix';
import JenisAsetAtribut from '../JenisAsetAtribut';
import type { MasterConfig, MasterRecord, Permission } from '../masters';
import { permission } from '../masters';
import { changeProfileField } from '../serviceLife';
import ServiceLifeFields from '../ServiceLifeFields';
import { useNumberPolicy } from '../useNumberPolicy';
import AssetTypeConditions from './AssetTypeConditions';
import JenisAsetCounters from './JenisAsetCounters';
import JenisAsetCounterTypes from './JenisAsetCounterTypes';
import type { JenisAsetDetail } from './jenisAsetDetail';
import JenisAsetMaintenanceJobTypes from './JenisAsetMaintenanceJobTypes';
import JenisAsetModels from './JenisAsetModels';
import MaintenanceChecklistTemplateLines from './MaintenanceChecklistTemplateLines';
import MaintenanceChecklistVariableValues from './MaintenanceChecklistVariableValues';
import MaintenanceJobTypeDetails from './MaintenanceJobTypeDetails';
import MaintenancePlanLines from './MaintenancePlanLines';
import MaintenancePlanTargets from './MaintenancePlanTargets';
import PabrikanAsetCounters from './PabrikanAsetCounters';
import type { PabrikanAsetDetail } from './pabrikanAsetDetail';
import PabrikanModels from './PabrikanModels';
import type { DetailSection } from './sections';
import { sectionsFor, summaryFor } from './sections';

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
    onVersionChange,
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
    /** Rincian yang disimpan menaikkan versi record ini; induk mencatat versi barunya. */
    onVersionChange: (version: number) => void;
    onSaved: (record: MasterRecord, created: boolean) => void;
}) {
    const readOnly = mode === 'view';
    const sections = useMemo(() => sectionsFor(config), [config]);
    const allFields = useMemo(
        () => sections.flatMap((section) => section.fields),
        [sections],
    );

    const [nama, setNama] = useState(() => record?.nama ?? '');
    const [manualCode, setManualCode] = useState('');
    const numberPolicy = useNumberPolicy(config.resource, mode === 'create');
    const [values, setValues] = useState<Record<string, FieldValue>>(() =>
        Object.fromEntries(
            allFields.map((field) => [field.name, valueFrom(record, field)]),
        ),
    );
    const [error, setError] = useState('');
    const creationKey = useRef(newIdempotencyKey());
    const formRef = useRef<HTMLFormElement>(null);
    /**
     * Field yang menunggu fokus setelah perisai sunting hilang. Disimpan pada ref, bukan
     * state, karena tidak ikut menentukan tampilan apa pun: ia hanya dibaca sekali oleh
     * effect di bawah begitu `readOnly` berubah, lalu dikosongkan lagi.
     */
    const pendingFocusRef = useRef<string | null>(null);
    const [pabrikanAsetDetailVersion, setPabrikanAsetDetailVersion] =
        useState(0);

    /**
     * Detail tambahan disimpan berpasangan dengan kunci yang menghasilkannya, lalu
     * keadaan memuat dan pesan kesalahannya dihitung saat render. Dengan begitu ganti
     * record langsung menampilkan keadaan memuat pada render pertama: tidak ada effect
     * yang perlu mengosongkan isi lama lebih dulu, dan tidak ada satu frame pun yang
     * memperlihatkan detail milik record sebelumnya.
     */
    const jenisAsetKunci =
        config.resource === 'jenis-aset' && record?.id ? record.id : null;
    const [jenisAsetMuatan, setJenisAsetMuatan] = useState<{
        kunci: string;
        detail: JenisAsetDetail | null;
        error: string;
    } | null>(null);
    const jenisAsetTermuat =
        jenisAsetKunci !== null && jenisAsetMuatan?.kunci === jenisAsetKunci
            ? jenisAsetMuatan
            : null;
    const jenisAsetDetail = jenisAsetTermuat?.detail ?? null;
    const jenisAsetDetailError = jenisAsetTermuat?.error ?? '';
    const jenisAsetDetailLoading =
        jenisAsetKunci !== null && jenisAsetTermuat === null;

    // Nomor versi ikut masuk kunci: memintanya naik berarti detail pabrikan dianggap
    // belum termuat lagi, persis seperti saat record-nya baru dibuka.
    const pabrikanAsetKunci =
        config.resource === 'pabrikan-aset' && record?.id
            ? `${record.id}|${pabrikanAsetDetailVersion}`
            : null;
    const [pabrikanAsetMuatan, setPabrikanAsetMuatan] = useState<{
        kunci: string;
        detail: PabrikanAsetDetail | null;
        error: string;
    } | null>(null);
    const pabrikanAsetTermuat =
        pabrikanAsetKunci !== null &&
        pabrikanAsetMuatan?.kunci === pabrikanAsetKunci
            ? pabrikanAsetMuatan
            : null;
    const pabrikanAsetDetail = pabrikanAsetTermuat?.detail ?? null;
    const pabrikanAsetDetailError = pabrikanAsetTermuat?.error ?? '';
    const pabrikanAsetDetailLoading =
        pabrikanAsetKunci !== null && pabrikanAsetTermuat === null;

    useEffect(() => {
        if (jenisAsetKunci === null) {
            return;
        }

        let cancelled = false;
        api<{ data: JenisAsetDetail }>(`/jenis-aset/${jenisAsetKunci}/detail`)
            .then((result) => {
                if (!cancelled) {
                    setJenisAsetMuatan({
                        kunci: jenisAsetKunci,
                        detail: result.data,
                        error: '',
                    });
                }
            })
            .catch((caught) => {
                if (!cancelled) {
                    setJenisAsetMuatan({
                        kunci: jenisAsetKunci,
                        detail: null,
                        error: errorMessage(
                            caught,
                            'Detail jenis aset belum dapat dimuat.',
                        ),
                    });
                }
            });

        return () => {
            cancelled = true;
        };
    }, [jenisAsetKunci]);

    const pabrikanAsetId = record?.id;

    useEffect(() => {
        if (pabrikanAsetKunci === null || !pabrikanAsetId) {
            return;
        }

        let cancelled = false;
        api<{ data: PabrikanAsetDetail }>(
            `/pabrikan-aset/${pabrikanAsetId}/detail`,
        )
            .then((result) => {
                if (!cancelled) {
                    setPabrikanAsetMuatan({
                        kunci: pabrikanAsetKunci,
                        detail: result.data,
                        error: '',
                    });
                }
            })
            .catch((caught) => {
                if (!cancelled) {
                    setPabrikanAsetMuatan({
                        kunci: pabrikanAsetKunci,
                        detail: null,
                        error: errorMessage(
                            caught,
                            'Detail pabrikan belum dapat dimuat.',
                        ),
                    });
                }
            });

        return () => {
            cancelled = true;
        };
    }, [pabrikanAsetId, pabrikanAsetKunci]);

    function markDirty() {
        onDirtyChange(true);
    }

    /** Pindah ke mode sunting karena pengguna menyentuh satu field, lalu fokus ke situ. */
    function enterEdit(name: string) {
        if (!readOnly || !canEdit) {
            return;
        }

        pendingFocusRef.current = name;
        onRequestEdit();
    }

    /**
     * Untuk input teks fokusnya sudah benar sejak awal — elemennya tidak pernah dilepas.
     * Yang butuh pemulihan adalah Select dan Switch, yang tadi tertutup tombol perisai:
     * begitu perisainya hilang, fokus dipindahkan ke kontrol aslinya.
     */
    useEffect(() => {
        const menunggu = pendingFocusRef.current;

        if (readOnly || !menunggu) {
            return;
        }

        pendingFocusRef.current = null;
        const scope = formRef.current?.querySelector(
            `[data-field-name="${CSS.escape(menunggu)}"]`,
        );
        scope
            ?.querySelector<HTMLElement>(
                'input:not([type="hidden"]), textarea, button',
            )
            ?.focus();
    }, [readOnly]);

    async function submit(event: FormEvent) {
        event.preventDefault();

        if (mode === 'create' && (numberPolicy.loading || numberPolicy.error)) {
            setError(
                numberPolicy.error || 'Tunggu pengaturan kode selesai dimuat.',
            );

            return;
        }

        onSavingChange(true);
        setError('');

        try {
            const payload: Record<string, unknown> = { nama };

            if (!record && numberPolicy.allowManual) {
                payload.kode = manualCode.trim() || null;
            }

            if (record) {
                // Dari `record` saat menyimpan: rincian di panel ini dapat menaikkannya lebih dahulu.
                payload.version = record.version;
            }

            for (const field of allFields) {
                // Field yang sedang tersembunyi tidak dikirim, supaya mengganti satu pilihan
                // tidak diam-diam menyimpan nilai milik pilihan sebelumnya.
                if (isVisible(field, values)) {
                    payload[field.name] = payloadValue(
                        field,
                        values[field.name],
                    );
                }
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
                    // Ganti record berarti panel ini dipasang ulang, sehingga nomor
                    // halaman dan daftar modelnya mulai dari keadaan bersih tanpa
                    // effect yang menyetel ulang state.
                    key={record.id}
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
                    canReadAset={permissions.includes(
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
                    version={record.version}
                    onVersionChange={onVersionChange}
                />
            ) : (
                <p className="text-muted-foreground text-sm">
                    Buku penyusutan dapat diatur setelah {config.singular}{' '}
                    disimpan.
                </p>
            );
        }

        if (section.attributes) {
            return record ? (
                <JenisAsetAtribut
                    jenisAsetId={record.id}
                    canEdit={!readOnly && canEdit}
                    version={record.version}
                    onVersionChange={onVersionChange}
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
                    version={record.version}
                    onVersionChange={onVersionChange}
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
                    // Dipasang ulang setiap ganti record: varian dan daftar jenis aset
                    // dimuat dari keadaan bersih, tanpa sisa pesan dari record lama.
                    key={record.id}
                    jobTypeId={record.id}
                    canEdit={!readOnly && canEdit}
                    version={record.version}
                    onVersionChange={onVersionChange}
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
                    version={record.version}
                    onVersionChange={onVersionChange}
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
                    version={record.version}
                    onVersionChange={onVersionChange}
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
                    // Dipasang ulang setiap ganti record supaya penanda "tersimpan" dan
                    // pilihan dari record sebelumnya tidak ikut terbawa.
                    key={record.id}
                    jenisAsetId={record.id}
                    canEdit={!readOnly && canEdit}
                    version={record.version}
                    onVersionChange={onVersionChange}
                />
            ) : (
                <p className="text-muted-foreground text-sm">
                    Jenis pekerjaan dapat dikaitkan setelah jenis aset disimpan.
                </p>
            );
        }

        if (section.assetTypeConditions) {
            return record ? (
                <AssetTypeConditions
                    key={record.id}
                    assetTypeId={record.id}
                    canEdit={!readOnly && canEdit}
                    version={record.version}
                    onVersionChange={onVersionChange}
                />
            ) : (
                <p className="text-muted-foreground text-sm">
                    Kondisi dapat dipilih setelah jenis aset disimpan.
                </p>
            );
        }

        if (section.assetTypeCounters) {
            return record ? (
                <JenisAsetCounterTypes
                    key={record.id}
                    jenisAsetId={record.id}
                    canEdit={!readOnly && canEdit}
                    version={record.version}
                    onVersionChange={onVersionChange}
                />
            ) : (
                <p className="text-muted-foreground text-sm">
                    Counter dapat dikaitkan setelah jenis aset disimpan.
                </p>
            );
        }

        if (section.maintenancePlanLines) {
            return record ? (
                <MaintenancePlanLines
                    key={record.id}
                    planId={record.id}
                    canEdit={!readOnly && canEdit}
                    version={record.version}
                    onVersionChange={onVersionChange}
                />
            ) : (
                <p className="text-muted-foreground text-sm">
                    Baris rencana dapat diatur setelah rencana disimpan.
                </p>
            );
        }

        if (section.maintenancePlanTargets) {
            return record ? (
                <MaintenancePlanTargets
                    key={record.id}
                    planId={record.id}
                    canEdit={!readOnly && canEdit}
                    version={record.version}
                    onVersionChange={onVersionChange}
                />
            ) : (
                <p className="text-muted-foreground text-sm">
                    Aset yang dikenai rencana dapat dipilih setelah rencana
                    disimpan.
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
            <div className="space-y-5">
                {section.counters && (
                    <JenisAsetCounters detail={jenisAsetDetail} />
                )}
                <div className="grid gap-5 pt-1 sm:grid-cols-2">
                    {section.fields
                        .filter((field) => isVisible(field, values))
                        .map((field) =>
                            config.resource === 'profil-penyusutan' &&
                            field.name === 'useful_life_periods' ? (
                                <ServiceLifeFields
                                    key={field.name}
                                    value={values[field.name]}
                                    frequency={values.frequency}
                                    required={field.required}
                                    readOnly={readOnly}
                                    onChange={(next) => {
                                        markDirty();
                                        setValues((current) => ({
                                            ...current,
                                            [field.name]: next,
                                        }));
                                    }}
                                />
                            ) : (
                                <DynamicField
                                    key={field.name}
                                    config={field}
                                    value={values[field.name]}
                                    readOnly={readOnly}
                                    onRequestEdit={enterEdit}
                                    onChange={(next) => {
                                        markDirty();
                                        setValues((current) =>
                                            config.resource ===
                                            'profil-penyusutan'
                                                ? changeProfileField(
                                                      current,
                                                      field.name,
                                                      next,
                                                  )
                                                : {
                                                      ...current,
                                                      [field.name]: next,
                                                  },
                                        );
                                    }}
                                />
                            ),
                        )}
                </div>
            </div>
        );
    }

    return (
        <RecordEditBoundary
            editing={!readOnly}
            canEdit={canEdit && !!record}
            onEdit={onRequestEdit}
            className="flex h-full min-h-0 min-w-0 flex-col"
        >
            <form
                id={formId}
                ref={formRef}
                onSubmit={submit}
                className="[&_[data-readonly]:hover]:bg-muted/40 flex min-h-0 flex-col [&_[data-readonly]]:rounded-md [&_[data-readonly]]:transition-colors"
            >
                <div className="shrink-0 border-b px-6 py-4">
                    <div className="flex flex-wrap items-end gap-x-6 gap-y-3">
                        <div className="w-60">
                            <Input
                                id="kode"
                                label={config.kodeLabel}
                                value={
                                    record?.kode ??
                                    (numberPolicy.loading
                                        ? 'Memuat pengaturan kode…'
                                        : numberPolicy.allowManual
                                          ? manualCode
                                          : 'Dibuat otomatis saat disimpan')
                                }
                                disabled={
                                    Boolean(record) ||
                                    numberPolicy.loading ||
                                    !numberPolicy.allowManual
                                }
                                required={
                                    !record && !numberPolicy.allowAutomatic
                                }
                                maxLength={50}
                                onChange={(event) => {
                                    setManualCode(event.target.value);
                                    markDirty();
                                }}
                            />
                            {!record && numberPolicy.allowManual && (
                                <FieldDescription>
                                    {numberPolicy.allowAutomatic
                                        ? 'Isi kode sendiri, atau kosongkan untuk membuatnya otomatis.'
                                        : 'Isi kode yang mudah dikenali.'}
                                </FieldDescription>
                            )}
                            <FieldError>{numberPolicy.error}</FieldError>
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
                                Master ini memiliki induk, dan panel detail
                                belum merendernya. Pakai tampilan daftar sampai
                                dukungan induk ditambahkan.
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
        </RecordEditBoundary>
    );
}
