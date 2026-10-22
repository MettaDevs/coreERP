import { router } from '@inertiajs/react';
import { useEffect, useEffectEvent, useRef, useState } from 'react';
import type { RefObject } from 'react';
import { toast } from 'sonner';
import { useWorkDate } from '@/hooks/use-work-date';
import { ActionButton } from '@apperp/ui/action-button';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import {
    Card,
    CardAction,
    CardContent,
    CardHeader,
    CardTitle,
} from '@apperp/ui/card';
import { Checkbox } from '@apperp/ui/checkbox';
import { DatePicker } from '@apperp/ui/date-picker';

import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@apperp/ui/empty';
import { FieldLabel } from '@apperp/ui/field';
import { FormControl, FormGrid } from '@apperp/ui/form-control';
import { IncrementalListStatus } from '@apperp/ui/incremental-list-status';
import { Input } from '@apperp/ui/input';
import {
    PageDialog,
    PageDialogContent,
    PageDialogToolbar,
    PageDialogBody,
} from '@apperp/ui/page-dialog';
import { Select } from '@apperp/ui/select';

import { Textarea } from '@apperp/ui/textarea';
import { useConfirmation } from '@apperp/ui/use-confirmation';
import { useRecordNavigation } from '@apperp/ui/use-record-navigation';
import { useRecordTransition } from '@apperp/ui/use-record-transition';
import { RecordLookup } from '../../_shared/RecordLookup';
import { api, errorMessage, newIdempotencyKey } from '../../api';
import { useMasterOptions } from '../../master/useMasterOptions';
import { useRecordList } from '../../use-record-list';
import { useRecordOptions } from '../../use-record-list';
import { useSaveFeedback } from '../../use-save-feedback';
import { RecordCardHeader, recordQueryUrl } from '../_shared/record-card';
import { useRecordCardDirty } from '../_shared/use-record-card-dirty';
import { useRecordQueryTarget } from '../_shared/use-record-query-target';

type Context = { legal_entity_id: string | null; org_unit_id: string | null };
type Permission = string;
type AsetType = { id: string; kode: string; nama: string };
type Detail = {
    id?: string;
    nama_aset?: string;
    unit?: string;
    jenis_aset_id: string;
    satuan_id: string;
    quantity: string;
    requested_specification: string;
    estimated_unit_price: string;
};
type Plan = {
    id: string;
    kode: string;
    planned_on: string;
    planning_year: number;
    planning_type: 'regular' | 'additional';
    funding_source: string | null;
    description: string | null;
    status: string;
    version: number;
    total_estimated_value: string | number;
    details?: Detail[];
};

const emptyDetail = (): Detail => ({
    jenis_aset_id: '',
    satuan_id: '',
    quantity: '1',
    requested_specification: '',
    estimated_unit_price: '0',
});

// Tanggal dan tahun rencana dari tanggal kerja pengguna, bukan dari jam perangkat.
const emptyPlan = (
    workDate: string,
): Omit<Plan, 'id' | 'kode' | 'version' | 'status'> & {
    details: Detail[];
} => ({
    planned_on: workDate,
    planning_year: Number(workDate.slice(0, 4)),
    planning_type: 'regular',
    funding_source: '',
    description: '',
    total_estimated_value: 0,
    details: [emptyDetail()],
});

function AsetDetailRow({
    detail,
    index,
    canRemove,
    portalContainer,
    onChange,
    onRemove,
}: {
    detail: Detail;
    index: number;
    canRemove: boolean;
    portalContainer: RefObject<HTMLDivElement | null>;
    onChange: (change: Partial<Detail>) => void;
    onRemove: () => void;
}) {
    const typeSource = useMasterOptions('jenis-aset', '', detail.jenis_aset_id);
    const unitSource = useRecordOptions<AsetType>(
        '/reference-data/units-of-measure',
        (item) => item.nama,
        detail.satuan_id,
    );

    return (
        <div className="space-y-3 rounded-lg border p-3">
            <div className="flex justify-between">
                <span className="text-sm font-medium">Baris {index + 1}</span>
                {canRemove && (
                    <ActionButton
                        action="delete"
                        type="button"
                        onClick={onRemove}
                    />
                )}
            </div>
            <FormGrid className="sm:grid-cols-[minmax(0,1fr)_10rem_8rem]">
                <FormControl data-error-field="jenis_aset_id">
                    <RecordLookup
                        label="Jenis aset"
                        required
                        source={typeSource}
                        items={typeSource.options.map((record) => ({
                            value: record.id,
                            label: typeSource.getLabel(record),
                        }))}
                        value={detail.jenis_aset_id || null}
                        placeholder="Pilih jenis aset"
                        searchPlaceholder="Cari jenis aset"
                        ariaLabel={`Jenis aset baris ${index + 1}`}
                        portalContainer={portalContainer}
                        onValueChange={(value) =>
                            onChange({
                                jenis_aset_id: value ?? '',
                            })
                        }
                    />
                </FormControl>
                <FormControl data-error-field="satuan_id">
                    <RecordLookup
                        label="Satuan"
                        required
                        source={unitSource}
                        items={unitSource.options.map((record) => ({
                            value: record.id,
                            label: unitSource.getLabel(record),
                        }))}
                        value={detail.satuan_id || null}
                        placeholder="Pilih satuan"
                        searchPlaceholder="Cari satuan"
                        ariaLabel={`Satuan baris ${index + 1}`}
                        portalContainer={portalContainer}
                        onValueChange={(value) =>
                            onChange({
                                satuan_id: value ?? '',
                            })
                        }
                    />
                </FormControl>
                <FormControl data-error-field="quantity">
                    <Input
                        label="Jumlah"
                        type="number"
                        min="0.0001"
                        step="0.0001"
                        value={detail.quantity}
                        onChange={(event) =>
                            onChange({ quantity: event.target.value })
                        }
                    />
                </FormControl>
            </FormGrid>
            <FormControl data-error-field="requested_specification">
                <Input
                    label="Spesifikasi yang diminta"
                    value={detail.requested_specification}
                    onChange={(event) =>
                        onChange({
                            requested_specification: event.target.value,
                        })
                    }
                    required
                />
            </FormControl>
            <FormControl data-error-field="estimated_unit_price">
                <Input
                    label="Perkiraan harga satuan"
                    type="number"
                    min="0"
                    step="0.01"
                    value={detail.estimated_unit_price}
                    onChange={(event) =>
                        onChange({ estimated_unit_price: event.target.value })
                    }
                />
            </FormControl>
        </div>
    );
}

export default function PlanningPage({
    context,
    permissions,
    createCard,
}: {
    context: Context;
    permissions: Permission[];
    createCard?: {
        onCreated: (plan: Plan) => void;
        onClose: () => void;
    };
}) {
    const { confirm: confirmAction, confirmation: actionConfirmation } =
        useConfirmation();

    const {
        feedbackSummary: pageFeedbackSummary,
        reportSaveError: pageFeedbackReportSaveError,
        clearSaveFeedback: pageFeedbackClearSaveFeedback,
    } = useSaveFeedback();
    const pageFeedback = {
        reportSaveError: pageFeedbackReportSaveError,
        clearSaveFeedback: pageFeedbackClearSaveFeedback,
    };

    const can = (action: string) =>
        permissions.includes(`management-aset.perencanaan-aset.${action}`);
    const { date: workDate } = useWorkDate();
    const [editing, setEditing] = useState<
        | (Plan & { details: Detail[] })
        | ReturnType<typeof emptyPlan>
        | undefined
    >(() => (createCard ? emptyPlan(workDate) : undefined));
    const [viewing, setViewing] = useState<Plan | null>(null);
    const [selectedLines, setSelectedLines] = useState<string[]>([]);
    const [manualCode, setManualCode] = useState('');
    const [numberPolicy, setNumberPolicy] = useState<{
        allow_manual: boolean;
        allow_automatic: boolean;
    } | null>(null);
    const [saving, setSaving] = useState(false);
    const [cardLoading, setCardLoading] = useState(Boolean(createCard));
    const [planDirty, setPlanDirty] = useState(false);
    const sheetContentRef = useRef<HTMLDivElement>(null);
    const {
        feedbackSummary: editFeedbackSummary,
        reportSaveError: editFeedbackReportSaveError,
        clearSaveFeedback: editFeedbackClearSaveFeedback,
    } = useSaveFeedback(
        sheetContentRef,
        editing !== undefined ? 'editing' : 'closed',
    );
    const editFeedback = {
        reportSaveError: editFeedbackReportSaveError,
        clearSaveFeedback: editFeedbackClearSaveFeedback,
    };

    // Effect adalah satu-satunya pemilik pengambilan daftar rencana. Pemuatan ulang
    // setelah simpan atau arsip dinyatakan dengan menaikkan penanda ini.
    const [versiMuat, setVersiMuat] = useState(0);
    const muatUlang = () => setVersiMuat((versi) => versi + 1);

    const list = useRecordList<Plan>(
        '/perencanaan-aset',
        '',
        {
            refresh: String(versiMuat),
        },
        !createCard,
    );
    const plans = list.items;
    const openCreate = async () => {
        editFeedback.clearSaveFeedback();

        setCardLoading(true);
        setNumberPolicy(null);
        setManualCode('');

        try {
            const result = await api<{
                data: { allow_manual: boolean; allow_automatic: boolean };
            }>('/perencanaan-aset/number-policy');
            setNumberPolicy(result.data);
            setEditing(emptyPlan(workDate));
        } catch (caught) {
            toast.error(
                errorMessage(caught, 'Pengaturan kode belum dapat dimuat.'),
            );
            createCard?.onClose();
        } finally {
            setCardLoading(false);
        }
    };

    const closeCreateCard = useEffectEvent(() => createCard?.onClose());
    const isCreateCard = Boolean(createCard);
    useEffect(() => {
        if (!isCreateCard) {
            return;
        }

        const request = new AbortController();
        const loadPolicy = async () => {
            try {
                const result = await api<{
                    data: { allow_manual: boolean; allow_automatic: boolean };
                }>('/perencanaan-aset/number-policy', {
                    signal: request.signal,
                });

                if (!request.signal.aborted) {
                    setNumberPolicy(result.data);
                }
            } catch (caught) {
                if (!request.signal.aborted) {
                    toast.error(
                        errorMessage(
                            caught,
                            'Pengaturan kode belum dapat dimuat.',
                        ),
                    );
                    closeCreateCard();
                }
            } finally {
                if (!request.signal.aborted) {
                    setCardLoading(false);
                }
            }
        };
        void loadPolicy();

        return () => request.abort();
    }, [isCreateCard]);

    const openDetails = async (id: string, signal?: AbortSignal) => {
        setCardLoading(true);

        try {
            const result = await api<{ data: Plan }>(
                `/perencanaan-aset/${id}`,
                { signal },
            );

            if (signal?.aborted) {
                return;
            }

            setViewing(result.data);
            setSelectedLines(
                (result.data.details ?? []).flatMap((line) =>
                    line.id ? [line.id] : [],
                ),
            );
        } catch (caught) {
            if (signal?.aborted) {
                return;
            }

            toast.error(
                errorMessage(caught, 'Rincian perencanaan belum dapat dimuat.'),
            );
        } finally {
            setCardLoading(false);
        }
    };

    const clearRecordTarget = useRecordQueryTarget({
        scopeKey: 'perencanaan-aset',
        enabled: !createCard,
        onOpen: (id, signal) => openDetails(id, signal),
    });
    const openEdit = async (id: string) => {
        editFeedback.clearSaveFeedback();

        setCardLoading(true);

        try {
            const result = await api<{ data: Plan & { details: Detail[] } }>(
                `/perencanaan-aset/${id}`,
            );
            setEditing({
                ...result.data,
                details: result.data.details.map((detail) => ({
                    ...detail,
                    quantity: String(detail.quantity),
                    estimated_unit_price: String(detail.estimated_unit_price),
                })),
            });
        } catch (caught) {
            toast.error(
                errorMessage(caught, 'Rencana aset belum dapat dibuka.'),
            );
        } finally {
            setCardLoading(false);
        }
    };

    const archive = async (plan: Pick<Plan, 'id' | 'kode' | 'version'>) => {
        if (
            !(await confirmAction({
                title: `Delete rencana ${plan.kode}?`,
                description:
                    'Data yang dihapus tidak lagi tampil pada daftar pilihan.',
                confirmLabel: 'Delete',
                destructive: true,
            }))
        ) {
            return;
        }

        setSaving(true);

        try {
            await api(`/perencanaan-aset/${plan.id}`, {
                method: 'DELETE',
                body: JSON.stringify({ version: plan.version }),
            });
            setViewing((current) => (current?.id === plan.id ? null : current));
            setEditing((current) =>
                current && 'id' in current && current.id === plan.id
                    ? undefined
                    : current,
            );
            muatUlang();
        } catch (caught) {
            pageFeedback.reportSaveError(
                caught,
                'Rencana aset belum dapat diarsipkan.',
            );
        } finally {
            setSaving(false);
        }
    };

    const updateDetail = (index: number, change: Partial<Detail>) => {
        setEditing(
            (current) =>
                current && {
                    ...current,
                    details: current.details.map((detail, position) =>
                        position === index ? { ...detail, ...change } : detail,
                    ),
                },
        );
    };

    const save = async () => {
        if (!editing || !context.legal_entity_id || !context.org_unit_id) {
            editFeedback.reportSaveError(
                null,
                'Pilih perusahaan dan unit kerja aktif sebelum membuat rencana.',
            );

            return;
        }

        if (
            !editing.details.every(
                (detail) =>
                    detail.jenis_aset_id &&
                    detail.satuan_id &&
                    detail.requested_specification.trim(),
            )
        ) {
            editFeedback.reportSaveError(
                null,
                'Pilih jenis aset, satuan, dan isi spesifikasi pada setiap rincian.',
            );

            return;
        }

        if (
            !('id' in editing) &&
            !numberPolicy?.allow_automatic &&
            !manualCode.trim()
        ) {
            editFeedback.reportSaveError(
                null,
                'Isi kode perencanaan terlebih dahulu.',
            );

            return;
        }

        setSaving(true);
        const body = {
            ...(!('id' in editing) ? { kode: manualCode.trim() || null } : {}),
            legal_entity_id: context.legal_entity_id,
            planning_org_unit_id: context.org_unit_id,
            planned_on: editing.planned_on,
            planning_year: Number(editing.planning_year),
            planning_type: editing.planning_type,
            funding_source: editing.funding_source || null,
            description: editing.description || null,
            details: editing.details.map((detail) => ({
                ...detail,
                quantity: Number(detail.quantity),
                estimated_unit_price: Number(detail.estimated_unit_price || 0),
            })),
        };

        try {
            if ('id' in editing) {
                await api(`/perencanaan-aset/${editing.id}`, {
                    method: 'PATCH',
                    body: JSON.stringify({ ...body, version: editing.version }),
                });
            } else {
                const result = await api<{ data: Plan }>('/perencanaan-aset', {
                    method: 'POST',
                    headers: { 'Idempotency-Key': newIdempotencyKey() },
                    body: JSON.stringify(body),
                });

                if (createCard) {
                    editFeedback.clearSaveFeedback();
                    toast.success('Rencana aset disimpan.');
                    createCard.onCreated(result.data);

                    return;
                }
            }

            clearRecordTarget();
            setEditing(undefined);
            muatUlang();
            editFeedback.clearSaveFeedback();
            toast.success('Rencana aset disimpan.');
        } catch (caught) {
            editFeedback.reportSaveError(
                caught,
                'Rencana aset belum dapat disimpan.',
            );
        } finally {
            setSaving(false);
        }
    };

    const {
        request: requestPlanTransition,
        confirmation: planTransitionConfirmation,
    } = useRecordTransition(planDirty, saving || cardLoading);
    const newPlan = () => {
        requestPlanTransition(() => {
            clearRecordTarget();
            void openCreate();
        });
    };
    const closePlan = () => {
        requestPlanTransition(() => {
            setEditing(undefined);
            createCard?.onClose();
        });
    };
    useRecordCardDirty({
        value: { editing, manualCode },
        ready: editing !== undefined && editing !== null && !cardLoading,
        scopeKey: editing && 'id' in editing ? editing.id : 'new',
        onDirtyChange: setPlanDirty,
    });
    const viewNavigation = useRecordNavigation({
        items: list.items,
        currentId: viewing?.id ?? null,
        getId: (item) => item.id,
        onNavigate: (id) => void openDetails(id),
        scopeKey: JSON.stringify([versiMuat, list.sort]),
        hasMore: list.hasMore,
        loading: list.loading,
        loadMore: list.loadMore,
        disabled: saving || cardLoading || planDirty,
    });
    const editNavigation = useRecordNavigation({
        items: list.items,
        currentId: editing && 'id' in editing ? editing.id : null,
        getId: (item) => item.id,
        onNavigate: (id) => void openEdit(id),
        scopeKey: JSON.stringify([versiMuat, list.sort]),
        hasMore: list.hasMore,
        loading: list.loading,
        loadMore: list.loadMore,
        disabled: saving || cardLoading || planDirty,
    });

    return (
        <>
            {actionConfirmation}

            {planTransitionConfirmation}
            {!createCard && (
                <Card className="min-h-full rounded-none border-0 shadow-none">
                    <CardHeader className="border-b">
                        <CardTitle>Perencanaan aset</CardTitle>
                        {can('create') && (
                            <CardAction>
                                <ActionButton
                                    action="create"
                                    onClick={() => void openCreate()}
                                />
                            </CardAction>
                        )}
                    </CardHeader>
                    <CardContent className="px-0">
                        {!viewing && pageFeedbackSummary}
                        {!plans.length && !list.loading && !list.error ? (
                            <Empty>
                                <EmptyHeader>
                                    <EmptyTitle>
                                        Belum ada rencana aset
                                    </EmptyTitle>
                                    <EmptyDescription>
                                        Rencana dibuat dari jenis aset.
                                        Spesifikasi dicatat pada rincian
                                        rencana, bukan pada master.
                                    </EmptyDescription>
                                </EmptyHeader>
                            </Empty>
                        ) : (
                            <div className="divide-y">
                                {plans.map((plan) => (
                                    <div
                                        key={plan.id}
                                        className="flex flex-wrap items-center justify-between gap-3 px-5 py-4"
                                    >
                                        <div className="min-w-0 flex-1">
                                            <p
                                                className="truncate font-medium"
                                                title={`${plan.kode} - ${plan.description || 'Tanpa tujuan'}`}
                                            >
                                                {plan.kode} ·{' '}
                                                {plan.description ||
                                                    'Tanpa tujuan'}
                                            </p>
                                            <p className="text-muted-foreground text-sm">
                                                {plan.planned_on} ·{' '}
                                                {plan.planning_type ===
                                                'regular'
                                                    ? 'Reguler'
                                                    : 'Tambahan'}{' '}
                                                · Rp{' '}
                                                {Number(
                                                    plan.total_estimated_value,
                                                ).toLocaleString('id-ID')}
                                            </p>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <Badge variant="secondary">
                                                {plan.status === 'draft'
                                                    ? 'Draf'
                                                    : plan.status}
                                            </Badge>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    void openDetails(plan.id)
                                                }
                                            >
                                                Buka rincian
                                            </Button>
                                            {can('update') && (
                                                <ActionButton
                                                    action="edit"
                                                    onClick={() =>
                                                        void openEdit(plan.id)
                                                    }
                                                />
                                            )}
                                            {can('archive') && (
                                                <ActionButton
                                                    action="archive"
                                                    onClick={() =>
                                                        void archive(plan)
                                                    }
                                                />
                                            )}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                        <IncrementalListStatus state={list} />
                    </CardContent>
                </Card>
            )}

            <PageDialog
                open={viewing !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        clearRecordTarget();
                        setViewing(null);
                    }
                }}
            >
                <PageDialogContent
                    title={'Perencanaan aset'}
                    busy={saving || cardLoading}
                    onNew={can('create') ? newPlan : undefined}
                    navigation={viewNavigation}
                >
                    <div className="flex min-h-0 flex-1 flex-col">
                        <RecordCardHeader
                            recordTitle={viewing?.kode}
                            recordUrl={
                                viewing
                                    ? recordQueryUrl(
                                          'perencanaan-aset',
                                          viewing.id,
                                      )
                                    : undefined
                            }
                            onEdit={
                                viewing && can('update')
                                    ? () => void openEdit(viewing.id)
                                    : undefined
                            }
                            onDelete={
                                viewing && can('archive')
                                    ? () => void archive(viewing)
                                    : undefined
                            }
                        />
                        {pageFeedbackSummary}
                        {permissions.includes(
                            'management-aset.permintaan-pembelian-aset.create',
                        ) && (
                            <PageDialogToolbar>
                                {permissions.includes(
                                    'management-aset.permintaan-pembelian-aset.create',
                                ) && (
                                    <Button
                                        disabled={selectedLines.length === 0}
                                        onClick={() => {
                                            if (!viewing) {
                                                return;
                                            }

                                            const query = new URLSearchParams({
                                                planning: viewing.id,
                                                lines: selectedLines.join(','),
                                            });
                                            router.visit(
                                                `/management-aset/permintaan-pembelian-aset?${query.toString()}`,
                                            );
                                        }}
                                    >
                                        Buat permintaan
                                    </Button>
                                )}
                            </PageDialogToolbar>
                        )}
                        <PageDialogBody
                            className="min-h-0 flex-1 space-y-4 overflow-y-auto p-4"
                            inert={cardLoading || saving}
                        >
                            <p className="text-muted-foreground text-sm">
                                {viewing?.planned_on.slice(0, 10)} · Pilih baris
                                yang akan dibuatkan permintaan.
                            </p>
                            {viewing?.details?.map((line, index) => (
                                <label
                                    key={line.id ?? index}
                                    className="flex items-start gap-3 rounded-lg border p-3"
                                >
                                    <Checkbox
                                        aria-label={`Pilih baris ${index + 1}`}
                                        checked={selectedLines.includes(
                                            line.id ?? '',
                                        )}
                                        onCheckedChange={(checked) => {
                                            if (line.id) {
                                                setSelectedLines((current) =>
                                                    checked
                                                        ? [...current, line.id!]
                                                        : current.filter(
                                                              (id) =>
                                                                  id !==
                                                                  line.id,
                                                          ),
                                                );
                                            }
                                        }}
                                    />
                                    <span className="space-y-1">
                                        <span className="block font-medium">
                                            {line.nama_aset}
                                        </span>
                                        <span className="block text-sm">
                                            {line.quantity} {line.unit} ·{' '}
                                            {line.requested_specification}
                                        </span>
                                    </span>
                                </label>
                            ))}
                        </PageDialogBody>
                    </div>
                </PageDialogContent>
            </PageDialog>

            <PageDialog
                open={editing !== undefined}
                onOpenChange={(open) => {
                    if (!open) {
                        closePlan();
                    }
                }}
            >
                <PageDialogContent
                    ref={sheetContentRef}
                    title={'Perencanaan aset'}
                    busy={saving || cardLoading}
                    onNew={
                        editing && 'id' in editing && can('create')
                            ? newPlan
                            : undefined
                    }
                    navigation={
                        editing && 'id' in editing ? editNavigation : undefined
                    }
                >
                    {editing && (
                        <div className="flex min-h-0 flex-1 flex-col">
                            <RecordCardHeader
                                recordTitle={
                                    'kode' in editing
                                        ? editing.kode
                                        : manualCode || 'Baru'
                                }
                                editing
                                onDelete={
                                    'id' in editing && can('archive')
                                        ? () => void archive(editing)
                                        : undefined
                                }
                                recordUrl={
                                    'id' in editing
                                        ? recordQueryUrl(
                                              'perencanaan-aset',
                                              editing.id,
                                          )
                                        : undefined
                                }
                            />
                            <PageDialogToolbar>
                                <ActionButton
                                    action="save"
                                    type="button"
                                    disabled={saving || cardLoading}
                                    onClick={() => void save()}
                                    pending={saving}
                                />
                                <ActionButton
                                    action="cancel"
                                    type="button"
                                    onClick={closePlan}
                                />
                            </PageDialogToolbar>
                            {editFeedbackSummary}
                            <PageDialogBody
                                className="min-h-0 flex-1 space-y-4 overflow-y-auto p-4"
                                inert={cardLoading || saving}
                            >
                                <p className="text-muted-foreground text-sm">
                                    Unit perencanaan dan penanggung jawab
                                    mengikuti konteks aktif Anda.
                                </p>
                                <FormControl data-error-field="kode">
                                    <Input
                                        label="Kode perencanaan"
                                        value={
                                            'id' in editing
                                                ? editing.kode
                                                : numberPolicy?.allow_manual
                                                  ? manualCode
                                                  : 'Dibuat otomatis saat disimpan'
                                        }
                                        disabled={
                                            'id' in editing ||
                                            !numberPolicy?.allow_manual
                                        }
                                        required={
                                            !('id' in editing) &&
                                            !numberPolicy?.allow_automatic
                                        }
                                        placeholder={
                                            numberPolicy?.allow_automatic
                                                ? 'Kosongkan untuk membuat kode otomatis'
                                                : 'Masukkan kode'
                                        }
                                        onChange={(event) =>
                                            setManualCode(event.target.value)
                                        }
                                    />
                                </FormControl>
                                <FormGrid>
                                    <FormControl data-error-field="planned_on">
                                        <DatePicker
                                            label="Tanggal perencanaan"

                                            value={editing.planned_on}
                                            onValueChange={(value) =>
                                                setEditing({
                                                    ...editing,
                                                    planned_on: value,
                                                    planning_year: Number(
                                                        value.slice(0, 4),
                                                    ),
                                                })
                                            }
                                            required
                                        />
                                    </FormControl>
                                    <FormControl data-error-field="planning_year">
                                        <Input
                                            label="Tahun perencanaan"
                                            type="number"
                                            value={editing.planning_year}
                                            onChange={(event) =>
                                                setEditing({
                                                    ...editing,
                                                    planning_year: Number(
                                                        event.target.value,
                                                    ),
                                                })
                                            }
                                            required
                                        />
                                    </FormControl>
                                    <FormControl data-error-field="planning_type">
                                        <Select
                                            label="Jenis perencanaan"
                                            required
                                            items={['Reguler', 'Tambahan']}
                                            value={
                                                editing.planning_type ===
                                                'regular'
                                                    ? 'Reguler'
                                                    : 'Tambahan'
                                            }
                                            searchPlaceholder="Cari jenis perencanaan"
                                            ariaLabel="Jenis perencanaan"
                                            portalContainer={sheetContentRef}
                                            onValueChange={(value) =>
                                                setEditing({
                                                    ...editing,
                                                    planning_type:
                                                        value === 'Tambahan'
                                                            ? 'additional'
                                                            : 'regular',
                                                })
                                            }
                                        />
                                    </FormControl>
                                    <FormControl data-error-field="funding_source">
                                        <Input
                                            label="Sumber dana"
                                            value={editing.funding_source ?? ''}
                                            onChange={(event) =>
                                                setEditing({
                                                    ...editing,
                                                    funding_source:
                                                        event.target.value,
                                                })
                                            }
                                        />
                                    </FormControl>
                                </FormGrid>
                                <FormControl data-error-field="description">
                                    <FieldLabel htmlFor="planning-description">
                                        Tujuan perencanaan
                                    </FieldLabel>
                                    <Textarea
                                        id="planning-description"
                                        rows={6}
                                        className="min-h-36 resize-y"
                                        value={editing.description ?? ''}
                                        onChange={(event) =>
                                            setEditing({
                                                ...editing,
                                                description: event.target.value,
                                            })
                                        }
                                    />
                                </FormControl>
                                <div className="space-y-3">
                                    <div className="flex items-center justify-between">
                                        <h3 className="font-medium">
                                            Rincian aset
                                        </h3>
                                        <ActionButton
                                            action="add"
                                            type="button"
                                            onClick={() =>
                                                setEditing({
                                                    ...editing,
                                                    details: [
                                                        ...editing.details,
                                                        emptyDetail(),
                                                    ],
                                                })
                                            }
                                        />
                                    </div>
                                    {editing.details.map((detail, index) => (
                                        <AsetDetailRow
                                            key={index}
                                            detail={detail}
                                            index={index}
                                            canRemove={
                                                editing.details.length > 1
                                            }
                                            portalContainer={sheetContentRef}
                                            onChange={(change) =>
                                                updateDetail(index, change)
                                            }
                                            onRemove={() =>
                                                setEditing({
                                                    ...editing,
                                                    details:
                                                        editing.details.filter(
                                                            (_, position) =>
                                                                position !==
                                                                index,
                                                        ),
                                                })
                                            }
                                        />
                                    ))}
                                </div>
                            </PageDialogBody>
                        </div>
                    )}
                </PageDialogContent>
            </PageDialog>
        </>
    );
}
