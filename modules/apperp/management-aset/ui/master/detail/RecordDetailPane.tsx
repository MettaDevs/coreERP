import type { FormEvent } from 'react';
import { useEffect, useMemo, useRef, useState } from 'react';
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
import type { FieldValue } from '../fields';
import { isVisible, payloadValue, valueFrom } from '../fields';
import GroupBookMatrix from '../GroupBookMatrix';
import JenisAsetAtribut from '../JenisAsetAtribut';
import type { MasterConfig, MasterRecord, Permission } from '../masters';
import {
    MANUAL_CODE_PATTERN,
    normalizeManualCode,
    permission,
} from '../masters';
import JenisAsetCounters from './JenisAsetCounters';
import type { JenisAsetDetail } from './jenisAsetDetail';
import JenisAsetMaintenanceJobTypes from './JenisAsetMaintenanceJobTypes';
import JenisAsetModels from './JenisAsetModels';
import MaintenanceChecklistTemplateLines from './MaintenanceChecklistTemplateLines';
import MaintenanceChecklistVariableValues from './MaintenanceChecklistVariableValues';
import MaintenanceJobTypeDetails from './MaintenanceJobTypeDetails';
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
    const manualCode = !record ? config.manualCode : undefined;
    const [kode, setKode] = useState('');
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
        onSavingChange(true);
        setError('');

        try {
            const payload: Record<string, unknown> = manualCode
                ? { kode, nama }
                : { nama };

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
                    // Dipasang ulang setiap ganti record: varian dan daftar jenis aset
                    // dimuat dari keadaan bersih, tanpa sisa pesan dari record lama.
                    key={record.id}
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
                    // Dipasang ulang setiap ganti record supaya penanda "tersimpan" dan
                    // pilihan dari record sebelumnya tidak ikut terbawa.
                    key={record.id}
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
                        {manualCode ? (
                            <Input
                                id="kode"
                                label={config.kodeLabel}
                                required
                                maxLength={30}
                                pattern={MANUAL_CODE_PATTERN}
                                placeholder={manualCode.placeholder}
                                className="font-mono"
                                value={kode}
                                onChange={(event) => {
                                    markDirty();
                                    setKode(
                                        normalizeManualCode(event.target.value),
                                    );
                                }}
                            />
                        ) : (
                            /* Kode diterbitkan Number Sequence, atau diketik saat membuat lalu
                               dikunci, jadi memang tidak ada yang bisa disunting di sini —
                               `disabled` tepat, tidak seperti field lain. */
                            <Input
                                id="kode"
                                label={config.kodeLabel}
                                value={
                                    record?.kode ??
                                    'Dibuat otomatis saat disimpan'
                                }
                                disabled
                            />
                        )}
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
                {manualCode && (
                    <p className="text-muted-foreground mt-2 text-sm">
                        {manualCode.help}
                    </p>
                )}
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
