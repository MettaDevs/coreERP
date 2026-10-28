import { router, usePage } from '@inertiajs/react';
import { useEffect, useEffectEvent, useRef, useState } from 'react';
import { toast } from 'sonner';
import { useWorkDate } from '@/hooks/use-work-date';
import { apiRequest } from '@/lib/core-api';
import type { FundingSourceRecord } from '@/pages/foundation/funding-source/funding-sources';
import type { PlanningYearRecord } from '@/pages/foundation/planning-year/planning-years';
import type { Auth, Workspace } from '@/types';
import { ActionButton } from '@apperp/ui/action-button';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import {
    CollapsibleSection,
    CollapsibleSectionGroup,
} from '@apperp/ui/collapsible-section';
import { DataTable } from '@apperp/ui/data-table';
import type {
    DataTableColumn,
    DataTableRowAction,
} from '@apperp/ui/data-table';
import { DatePicker, formatDateInput } from '@apperp/ui/date-picker';
import { FormControl, FormGrid } from '@apperp/ui/form-control';
import { Input } from '@apperp/ui/input';
import { ListPageLayout } from '@apperp/ui/list-page-layout';
import {
    PageDialog,
    PageDialogContent,
    PageDialogToolbar,
    PageDialogBody,
} from '@apperp/ui/page-dialog';
import { RecordActionBar } from '@apperp/ui/record-action-bar';

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
import { FundingSourceLookup } from './FundingSourceLookup';
import { PlanningYearLookup } from './PlanningYearLookup';

type Context = {
    legal_entity_id: string | null;
    org_unit_id: string | null;
    legal_entity_name?: string | null;
    planning_org_unit_name?: string | null;
};
type Permission = string;
type AsetType = { id: string; kode: string; nama: string };
type OrganizationOption = {
    id: string;
    kode?: string | null;
    nama: string;
    display_label?: string;
};
type Detail = {
    id?: string;
    nama_aset?: string;
    jenis_aset_kode?: string;
    jenis_aset_nama?: string;
    unit?: string;
    jenis_aset_id: string;
    satuan_id: string;
    quantity: string;
    requested_specification: string;
    needed_on: string | null;
    funding_source_id: string | null;
    funding_source_name?: string | null;
    estimated_unit_price: string | null;
};
type Plan = {
    id: string;
    kode: string;
    planned_on: string;
    planning_year: number | null;
    planning_year_id: string | null;
    legal_entity_id: string | null;
    planning_org_unit_id: string | null;
    responsible_user_id: string;
    responsible_user_name?: string | null;
    legal_entity_name?: string | null;
    planning_org_unit_name?: string | null;
    description: string | null;
    status: string;
    version: number;
    total_estimated_value: string | number;
    details?: Detail[];
};

type DraftDetail = Detail & { lineKey: string };

const emptyDetail = (): DraftDetail => ({
    lineKey: newIdempotencyKey(),
    jenis_aset_id: '',
    satuan_id: '',
    quantity: '1',
    requested_specification: '',
    needed_on: null,
    funding_source_id: null,
    estimated_unit_price: null,
});

// Tanggal dokumen memakai tanggal kerja; tahun dipilih dari master perusahaan.
const emptyPlan = (
    workDate: string,
    userId: string,
    context: Context,
): Omit<Plan, 'id' | 'kode' | 'version' | 'status' | 'details'> & {
    details: DraftDetail[];
} => ({
    planned_on: workDate,
    planning_year: null,
    planning_year_id: null,
    legal_entity_id: context.legal_entity_id,
    planning_org_unit_id: context.org_unit_id,
    responsible_user_id: userId,
    description: '',
    total_estimated_value: 0,
    details: [emptyDetail()],
});

const lineTotal = (detail: Detail) =>
    Number(detail.quantity || 0) * Number(detail.estimated_unit_price || 0);

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
    const { auth, workspace } = usePage<{ auth: Auth; workspace?: Workspace }>()
        .props;
    const currentUserId = String(auth.user.id);
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
        | (Omit<Plan, 'details'> & { details: DraftDetail[] })
        | ReturnType<typeof emptyPlan>
        | undefined
    >(() =>
        createCard ? emptyPlan(workDate, currentUserId, context) : undefined,
    );
    const [viewing, setViewing] = useState<Plan | null>(null);
    const [selectedLines, setSelectedLines] = useState<string[]>([]);
    const [manualCode, setManualCode] = useState('');
    const [search, setSearch] = useState('');
    const [editSections, setEditSections] = useState(['general', 'lines']);
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
        fieldError: editFieldError,
    } = useSaveFeedback(
        sheetContentRef,
        editing !== undefined ? 'editing' : 'closed',
        (field) =>
            setEditSections((current) => [
                ...new Set([
                    ...current,
                    field.startsWith('details') ? 'lines' : 'general',
                ]),
            ]),
    );
    const editFeedback = {
        reportSaveError: editFeedbackReportSaveError,
        clearSaveFeedback: editFeedbackClearSaveFeedback,
        fieldError: editFieldError,
    };

    // Effect adalah satu-satunya pemilik pengambilan daftar rencana. Pemuatan ulang
    // setelah simpan atau arsip dinyatakan dengan menaikkan penanda ini.
    const [versiMuat, setVersiMuat] = useState(0);
    const muatUlang = () => setVersiMuat((versi) => versi + 1);

    const list = useRecordList<Plan>(
        '/perencanaan-aset',
        search,
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
            setEditing(emptyPlan(workDate, currentUserId, context));
            setViewing(null);
            setEditSections(['general', 'lines']);
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
                    lineKey: detail.id ?? newIdempotencyKey(),
                    quantity: String(detail.quantity),
                    estimated_unit_price:
                        detail.estimated_unit_price === null
                            ? null
                            : String(detail.estimated_unit_price),
                })),
            });
            setViewing(null);
            setEditSections(['general', 'lines']);
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
        if (
            !editing ||
            !selectedCompanyId ||
            !selectedUnitId ||
            !editing.planning_year_id
        ) {
            editFeedback.reportSaveError(
                null,
                'Pilih perusahaan, unit pengusul, dan tahun rencana sebelum menyimpan.',
            );

            return;
        }

        if (
            !editing.details.every(
                (detail) =>
                    detail.jenis_aset_id &&
                    detail.satuan_id &&
                    detail.funding_source_id &&
                    detail.needed_on &&
                    detail.requested_specification.trim(),
            )
        ) {
            editFeedback.reportSaveError(
                null,
                'Pilih jenis aset, satuan, sumber dana, tanggal kebutuhan, dan isi spesifikasi pada setiap rincian.',
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
            legal_entity_id: selectedCompanyId,
            planning_org_unit_id: selectedUnitId,
            planned_on: editing.planned_on,
            planning_year_id: editing.planning_year_id,
            responsible_user_id: editing.responsible_user_id,
            description: editing.description || null,
            details: editing.details.map((detail) => ({
                ...(detail.id ? { id: detail.id } : {}),
                jenis_aset_id: detail.jenis_aset_id,
                satuan_id: detail.satuan_id,
                requested_specification: detail.requested_specification,
                quantity: Number(detail.quantity),
                needed_on: detail.needed_on,
                funding_source_id: detail.funding_source_id,
                estimated_unit_price:
                    detail.estimated_unit_price === null ||
                    detail.estimated_unit_price === ''
                        ? null
                        : Number(detail.estimated_unit_price),
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
            setPlanDirty(false);
            muatUlang();
            editFeedback.clearSaveFeedback();
            toast.success('Rencana aset disimpan.');

            if (
                selectedCompanyId !== context.legal_entity_id &&
                auth.membership
            ) {
                try {
                    await apiRequest('/api/v1/workspace-context', {
                        method: 'PUT',
                        body: JSON.stringify({
                            membership_id: auth.membership.id,
                            legal_entity_id: selectedCompanyId,
                            org_unit_id: selectedUnitId,
                        }),
                    });
                    window.location.reload();
                } catch {
                    toast.warning(
                        'Rencana sudah disimpan. Pilih perusahaan dokumen di tempat kerja untuk melihatnya.',
                    );
                }
            }
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

    const canRequest = permissions.includes(
        'management-aset.permintaan-pembelian-aset.create',
    );
    const typeSource = useMasterOptions(
        editing ? 'jenis-aset' : null,
        '',
        editing?.details.map((line) => line.jenis_aset_id).filter(Boolean) ??
            [],
    );
    const unitSource = useRecordOptions<AsetType>(
        editing ? '/reference-data/units-of-measure' : null,
        (item) => item.nama,
        editing?.details.map((line) => line.satuan_id).filter(Boolean) ?? [],
    );
    const responsibleSource = useRecordOptions<AsetType>(
        editing ? '/reference-data/anggota' : null,
        (item) => item.nama,
        editing?.responsible_user_id ?? '',
    );
    const fundingSource = useRecordOptions<FundingSourceRecord>(
        editing ? '/reference-data/funding-sources' : null,
        (item) => item.name,
        editing?.details.flatMap((line) =>
            line.funding_source_id ? [line.funding_source_id] : [],
        ) ?? [],
    );
    const companySource = useRecordOptions<OrganizationOption>(
        editing ? '/perencanaan-aset/organizations?kind=legal_entities' : null,
        (item) => item.nama,
        editing?.legal_entity_id ?? '',
    );
    const singleCompany =
        companySource.options.length === 1 ? companySource.options[0] : null;
    const selectedCompanyId = editing
        ? (editing.legal_entity_id ?? singleCompany?.id ?? null)
        : null;
    const operatingUnitSource = useRecordOptions<OrganizationOption>(
        selectedCompanyId
            ? `/perencanaan-aset/organizations?kind=operating_units&legal_entity_id=${encodeURIComponent(selectedCompanyId)}`
            : null,
        (item) => item.nama,
        editing?.planning_org_unit_id ?? '',
    );
    const singleUnit =
        operatingUnitSource.options.length === 1
            ? operatingUnitSource.options[0]
            : null;
    const selectedUnitId = editing
        ? (editing.planning_org_unit_id ?? singleUnit?.id ?? null)
        : null;
    const yearSource = useRecordOptions<PlanningYearRecord>(
        selectedCompanyId
            ? `/perencanaan-aset/planning-years?legal_entity_id=${encodeURIComponent(selectedCompanyId)}`
            : null,
        (item) => String(item.year),
        editing?.planning_year_id ?? '',
    );
    const money = (value: string | number) =>
        `Rp ${Number(value).toLocaleString('id-ID', { maximumFractionDigits: 2 })}`;
    const companyName =
        context.legal_entity_name ?? workspace?.active_legal_entity?.name ?? '';
    const unitName =
        context.planning_org_unit_name ??
        workspace?.active_org_unit?.name ??
        '';
    const missingEstimateCount = (details: Detail[]) =>
        details.filter(
            (detail) =>
                detail.estimated_unit_price === null ||
                detail.estimated_unit_price === '',
        ).length;
    const estimateSummary = (details: Detail[]) => {
        const missing = missingEstimateCount(details);
        const total = money(
            details.reduce((sum, detail) => sum + lineTotal(detail), 0),
        );

        return missing ? `${total} - ${missing} baris belum diestimasi` : total;
    };
    const planActions: DataTableRowAction[] = [
        { id: 'detail', label: 'Buka rincian' },
        ...(can('update') ? [{ id: 'edit', label: 'Ubah' }] : []),
        ...(can('archive')
            ? [{ id: 'delete', label: 'Delete', destructive: true }]
            : []),
    ];
    const planColumns: DataTableColumn<Plan>[] = [
        {
            id: 'kode',
            header: 'Kode',
            width: 150,
            cell: (plan) => (
                <span className="text-primary font-medium">{plan.kode}</span>
            ),
            sortValue: (plan) => plan.kode,
        },
        {
            id: 'description',
            header: 'Tujuan perencanaan',
            minWidth: 280,
            cell: (plan) => plan.description || 'Tanpa tujuan',
        },
        {
            id: 'date',
            header: 'Tanggal',
            width: 130,
            cell: (plan) => formatDateInput(plan.planned_on.slice(0, 10)).text,
            sortValue: (plan) => plan.planned_on,
        },
        {
            id: 'planning_year',
            header: 'Tahun',
            width: 90,
            cell: (plan) => plan.planning_year,
        },
        {
            id: 'total',
            header: 'Total perkiraan',
            width: 180,
            align: 'right',
            cell: (plan) => money(plan.total_estimated_value),
        },
        {
            id: 'status',
            header: 'Status',
            width: 110,
            cell: (plan) => (
                <Badge variant="secondary">
                    {plan.status === 'draft' ? 'Draf' : plan.status}
                </Badge>
            ),
            sortValue: (plan) => plan.status,
        },
    ];
    const viewColumns: DataTableColumn<Detail>[] = [
        {
            id: 'asset_type',
            header: 'Jenis aset',
            minWidth: 220,
            cell: (line) =>
                [line.jenis_aset_kode, line.jenis_aset_nama ?? line.nama_aset]
                    .filter(Boolean)
                    .join(' - '),
        },
        {
            id: 'specification',
            header: 'Spesifikasi yang diminta',
            minWidth: 280,
            cell: (line) => line.requested_specification,
        },
        {
            id: 'quantity',
            header: 'Jumlah',
            width: 110,
            align: 'right',
            cell: (line) =>
                Number(line.quantity).toLocaleString('id-ID', {
                    maximumFractionDigits: 4,
                }),
        },
        {
            id: 'unit',
            header: 'Satuan',
            width: 140,
            cell: (line) => line.unit ?? '',
        },
        {
            id: 'price',
            header: 'Perkiraan harga satuan',
            width: 190,
            align: 'right',
            cell: (line) =>
                line.estimated_unit_price === null
                    ? 'Belum diestimasi'
                    : money(line.estimated_unit_price),
        },
        {
            id: 'needed_on',
            header: 'Tanggal kebutuhan',
            width: 160,
            cell: (line) =>
                line.needed_on
                    ? formatDateInput(line.needed_on.slice(0, 10)).text
                    : 'Belum diisi',
        },
        {
            id: 'funding_source',
            header: 'Sumber dana',
            width: 230,
            cell: (line) => line.funding_source_name ?? 'Belum diisi',
        },
        {
            id: 'total',
            header: 'Total perkiraan',
            width: 190,
            align: 'right',
            cell: (line) =>
                line.estimated_unit_price === null
                    ? 'Belum diestimasi'
                    : money(lineTotal(line)),
        },
    ];
    const editRows =
        editing?.details.map((detail, index) => ({ detail, index })) ?? [];
    const editColumns: DataTableColumn<(typeof editRows)[number]>[] = [
        {
            id: 'asset_type',
            header: 'Jenis aset',
            minWidth: 260,
            content: 'control',
            cell: ({ detail, index }) => (
                <FormControl
                    data-error-field={`details.${index}.jenis_aset_id`}
                    error={editFieldError(`details.${index}.jenis_aset_id`)}
                >
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
                        ariaLabel={`Jenis aset baris ${index + 1}`}
                        portalContainer={sheetContentRef}
                        onValueChange={(value) =>
                            updateDetail(index, { jenis_aset_id: value ?? '' })
                        }
                    />
                </FormControl>
            ),
        },
        {
            id: 'specification',
            header: 'Spesifikasi yang diminta',
            minWidth: 300,
            content: 'control',
            cell: ({ detail, index }) => (
                <FormControl
                    data-error-field={`details.${index}.requested_specification`}
                    error={editFieldError(
                        `details.${index}.requested_specification`,
                    )}
                >
                    <Input
                        label="Spesifikasi yang diminta"
                        aria-label={`Spesifikasi baris ${index + 1}`}
                        required
                        value={detail.requested_specification}
                        onChange={(event) =>
                            updateDetail(index, {
                                requested_specification: event.target.value,
                            })
                        }
                    />
                </FormControl>
            ),
        },
        {
            id: 'quantity',
            header: 'Jumlah',
            width: 150,
            content: 'control',
            cell: ({ detail, index }) => (
                <FormControl
                    data-error-field={`details.${index}.quantity`}
                    error={editFieldError(`details.${index}.quantity`)}
                >
                    <Input
                        label="Jumlah"
                        aria-label={`Jumlah baris ${index + 1}`}
                        required
                        type="number"
                        min="0.0001"
                        step="0.0001"
                        value={detail.quantity}
                        onChange={(event) =>
                            updateDetail(index, {
                                quantity: event.target.value,
                            })
                        }
                    />
                </FormControl>
            ),
        },
        {
            id: 'unit',
            header: 'Satuan',
            minWidth: 210,
            content: 'control',
            cell: ({ detail, index }) => (
                <FormControl
                    data-error-field={`details.${index}.satuan_id`}
                    error={editFieldError(`details.${index}.satuan_id`)}
                >
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
                        ariaLabel={`Satuan baris ${index + 1}`}
                        portalContainer={sheetContentRef}
                        onValueChange={(value) =>
                            updateDetail(index, { satuan_id: value ?? '' })
                        }
                    />
                </FormControl>
            ),
        },
        {
            id: 'needed_on',
            header: 'Tanggal kebutuhan',
            width: 200,
            content: 'control',
            cell: ({ detail, index }) => (
                <FormControl
                    data-error-field={`details.${index}.needed_on`}
                    error={editFieldError(`details.${index}.needed_on`)}
                >
                    <DatePicker
                        label="Tanggal kebutuhan"
                        value={detail.needed_on ?? ''}
                        min={
                            editing?.planning_year
                                ? `${editing.planning_year}-01-01`
                                : undefined
                        }
                        max={
                            editing?.planning_year
                                ? `${editing.planning_year}-12-31`
                                : undefined
                        }
                        required
                        portalContainer={sheetContentRef}
                        onValueChange={(value) =>
                            updateDetail(index, { needed_on: value || null })
                        }
                    />
                </FormControl>
            ),
        },
        {
            id: 'funding_source',
            header: 'Sumber dana',
            minWidth: 260,
            content: 'control',
            cell: ({ detail, index }) => (
                <FormControl
                    data-error-field={`details.${index}.funding_source_id`}
                    error={editFieldError(`details.${index}.funding_source_id`)}
                >
                    <FundingSourceLookup
                        source={fundingSource}
                        value={detail.funding_source_id}
                        displayValue={detail.funding_source_name ?? undefined}
                        portalContainer={sheetContentRef}
                        canCreate={
                            auth.membership?.permissions.includes(
                                'core.reference-data.update',
                            ) ?? false
                        }
                        onValueChange={(value) =>
                            updateDetail(index, {
                                funding_source_id: value.id,
                                funding_source_name: value.name,
                            })
                        }
                    />
                </FormControl>
            ),
        },
        {
            id: 'price',
            header: 'Perkiraan harga satuan',
            width: 220,
            content: 'control',
            cell: ({ detail, index }) => (
                <FormControl
                    data-error-field={`details.${index}.estimated_unit_price`}
                    error={editFieldError(
                        `details.${index}.estimated_unit_price`,
                    )}
                >
                    <Input
                        label="Perkiraan harga satuan"
                        aria-label={`Perkiraan harga satuan baris ${index + 1}`}
                        type="number"
                        min="0"
                        step="0.01"
                        value={detail.estimated_unit_price ?? ''}
                        placeholder="Belum diestimasi"
                        onChange={(event) =>
                            updateDetail(index, {
                                estimated_unit_price:
                                    event.target.value || null,
                            })
                        }
                    />
                </FormControl>
            ),
        },
        {
            id: 'total',
            header: 'Total perkiraan',
            width: 180,
            align: 'right',
            cell: ({ detail }) =>
                detail.estimated_unit_price === null ||
                detail.estimated_unit_price === ''
                    ? 'Belum diestimasi'
                    : money(lineTotal(detail)),
        },
        {
            id: 'actions',
            header: 'Tindakan',
            width: 100,
            content: 'control',
            cell: ({ index }) =>
                editing &&
                editing.details.length > 1 && (
                    <ActionButton
                        action="delete"
                        aria-label={`Delete baris ${index + 1}`}
                        onClick={() =>
                            setEditing({
                                ...editing,
                                details: editing.details.filter(
                                    (_, position) => position !== index,
                                ),
                            })
                        }
                    />
                ),
        },
    ];

    return (
        <>
            {actionConfirmation}

            {planTransitionConfirmation}
            {!createCard && (
                <ListPageLayout
                    header={
                        <RecordActionBar
                            title="Perencanaan aset"
                            feedback={!viewing ? pageFeedbackSummary : null}
                        >
                            {can('create') && (
                                <ActionButton
                                    action="create"
                                    disabled={saving || cardLoading}
                                    onClick={() => void openCreate()}
                                />
                            )}
                        </RecordActionBar>
                    }
                    filters={
                        <div className="px-4 pb-3">
                            <FormControl className="w-full sm:w-80">
                                <Input
                                    label="Cari perencanaan"
                                    value={search}
                                    onChange={(event) =>
                                        setSearch(event.target.value)
                                    }
                                />
                            </FormControl>
                        </div>
                    }
                >
                    <DataTable
                        columns={planColumns}
                        data={plans}
                        getRowKey={(plan) => plan.id}
                        incremental={list}
                        sort={list.sort}
                        onSortChange={list.onSortChange}
                        actions={planActions}
                        getRowLabel={(plan) => plan.kode}
                        onRowClick={(plan) => void openDetails(plan.id)}
                        onRowAction={(action, plan) => {
                            if (action === 'edit') {
                                void openEdit(plan.id);
                            } else if (action === 'delete') {
                                void archive(plan);
                            } else {
                                void openDetails(plan.id);
                            }
                        }}
                        emptyMessage={
                            search
                                ? 'Tidak ada perencanaan yang cocok.'
                                : 'Belum ada rencana aset. Pilih Baru untuk membuat perencanaan.'
                        }
                    />
                </ListPageLayout>
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
                        {canRequest && (
                            <PageDialogToolbar>
                                <Button
                                    variant="ghost"
                                    type="button"
                                    disabled={
                                        selectedLines.length === 0 ||
                                        saving ||
                                        cardLoading
                                    }
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
                            </PageDialogToolbar>
                        )}
                        <PageDialogBody
                            className="min-h-0 flex-1 space-y-4 overflow-y-auto p-4"
                            inert={cardLoading || saving}
                        >
                            {viewing && (
                                <CollapsibleSectionGroup
                                    defaultValue={['general', 'lines']}
                                >
                                    <CollapsibleSection
                                        value="general"
                                        title="Umum"
                                        summary={
                                            viewing.description || viewing.kode
                                        }
                                    >
                                        <FormGrid>
                                            <FormControl>
                                                <DatePicker
                                                    label="Tanggal dokumen"
                                                    value={viewing.planned_on.slice(
                                                        0,
                                                        10,
                                                    )}
                                                    readOnly
                                                />
                                            </FormControl>
                                            <FormControl>
                                                <Input
                                                    label="Tahun rencana"
                                                    value={
                                                        viewing.planning_year ??
                                                        ''
                                                    }
                                                    readOnly
                                                />
                                            </FormControl>
                                            <FormControl>
                                                <Input
                                                    label="Perusahaan"
                                                    value={
                                                        viewing.legal_entity_name ??
                                                        companyName
                                                    }
                                                    readOnly
                                                />
                                            </FormControl>
                                            <FormControl>
                                                <Input
                                                    label="Unit pengusul"
                                                    value={
                                                        viewing.planning_org_unit_name ??
                                                        unitName
                                                    }
                                                    readOnly
                                                />
                                            </FormControl>
                                            <FormControl>
                                                <Input
                                                    label="Penanggung jawab"
                                                    value={
                                                        viewing.responsible_user_name ??
                                                        'Belum tersedia'
                                                    }
                                                    readOnly
                                                />
                                            </FormControl>
                                            <FormControl>
                                                <Textarea
                                                    label="Tujuan perencanaan"
                                                    value={
                                                        viewing.description ??
                                                        ''
                                                    }
                                                    readOnly
                                                />
                                            </FormControl>
                                        </FormGrid>
                                    </CollapsibleSection>
                                    <CollapsibleSection
                                        value="lines"
                                        title="Rincian aset"
                                        summary={`${viewing.details?.length ?? 0} baris - ${estimateSummary(viewing.details ?? [])}`}
                                    >
                                        {canRequest && (
                                            <p className="text-muted-foreground mb-3 text-sm">
                                                Pilih baris yang akan dibuatkan
                                                permintaan pembelian.
                                            </p>
                                        )}
                                        <DataTable
                                            columns={viewColumns}
                                            data={viewing.details ?? []}
                                            getRowKey={(line) =>
                                                line.id ?? line.jenis_aset_id
                                            }
                                            scrollMode="inline"
                                            selection={
                                                canRequest
                                                    ? {
                                                          selectedKeys:
                                                              selectedLines,
                                                          onSelectedKeysChange:
                                                              (keys) =>
                                                                  setSelectedLines(
                                                                      keys.map(
                                                                          String,
                                                                      ),
                                                                  ),
                                                      }
                                                    : undefined
                                            }
                                            emptyMessage="Perencanaan ini belum memiliki rincian aset."
                                        />
                                        <p className="mt-3 text-right text-sm font-semibold">
                                            Total perkiraan{' '}
                                            {estimateSummary(
                                                viewing.details ?? [],
                                            )}
                                        </p>
                                    </CollapsibleSection>
                                </CollapsibleSectionGroup>
                            )}
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
                                <CollapsibleSectionGroup
                                    value={editSections}
                                    onValueChange={setEditSections}
                                >
                                    <CollapsibleSection
                                        value="general"
                                        title="Umum"
                                        keepMounted
                                        summary={
                                            editing.description ||
                                            'Identitas perencanaan'
                                        }
                                    >
                                        <FormGrid>
                                            <FormControl
                                                data-error-field="kode"
                                                error={editFieldError('kode')}
                                            >
                                                <Input
                                                    label="Kode perencanaan"
                                                    value={
                                                        'id' in editing
                                                            ? editing.kode
                                                            : numberPolicy?.allow_manual
                                                              ? manualCode
                                                              : 'Dibuat otomatis saat disimpan'
                                                    }
                                                    readOnly={
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
                                                        setManualCode(
                                                            event.target.value,
                                                        )
                                                    }
                                                />
                                            </FormControl>
                                            <FormControl
                                                data-error-field="planned_on"
                                                error={editFieldError(
                                                    'planned_on',
                                                )}
                                            >
                                                <DatePicker
                                                    label="Tanggal dokumen"
                                                    portalContainer={
                                                        sheetContentRef
                                                    }
                                                    value={editing.planned_on}
                                                    onValueChange={(value) =>
                                                        setEditing({
                                                            ...editing,
                                                            planned_on: value,
                                                        })
                                                    }
                                                    required
                                                />
                                            </FormControl>
                                            <FormControl
                                                data-error-field="legal_entity_id"
                                                error={
                                                    editFieldError(
                                                        'legal_entity_id',
                                                    ) || companySource.error
                                                }
                                            >
                                                {!('id' in editing) &&
                                                !createCard &&
                                                companySource.options.length !==
                                                    1 ? (
                                                    <RecordLookup
                                                        label="Perusahaan"
                                                        required
                                                        source={companySource}
                                                        items={companySource.options.map(
                                                            (record) => ({
                                                                value: record.id,
                                                                label: record.nama,
                                                            }),
                                                        )}
                                                        value={
                                                            selectedCompanyId
                                                        }
                                                        placeholder="Pilih perusahaan"
                                                        portalContainer={
                                                            sheetContentRef
                                                        }
                                                        allowCreate={false}
                                                        onValueChange={(
                                                            value,
                                                        ) => {
                                                            if (
                                                                !value ||
                                                                value ===
                                                                    editing.legal_entity_id
                                                            ) {
                                                                return;
                                                            }

                                                            const record =
                                                                companySource.options.find(
                                                                    (item) =>
                                                                        item.id ===
                                                                        value,
                                                                );
                                                            setEditing({
                                                                ...editing,
                                                                legal_entity_id:
                                                                    value,
                                                                legal_entity_name:
                                                                    record?.nama ??
                                                                    '',
                                                                planning_org_unit_id:
                                                                    null,
                                                                planning_org_unit_name:
                                                                    '',
                                                                planning_year_id:
                                                                    null,
                                                                planning_year:
                                                                    null,
                                                            });
                                                        }}
                                                    />
                                                ) : (
                                                    <Input
                                                        label="Perusahaan"
                                                        value={
                                                            editing.legal_entity_name ||
                                                            companySource.options.find(
                                                                (item) =>
                                                                    item.id ===
                                                                    selectedCompanyId,
                                                            )?.nama ||
                                                            companyName
                                                        }
                                                        readOnly
                                                    />
                                                )}
                                            </FormControl>
                                            <FormControl
                                                data-error-field="planning_org_unit_id"
                                                error={
                                                    editFieldError(
                                                        'planning_org_unit_id',
                                                    ) ||
                                                    operatingUnitSource.error
                                                }
                                            >
                                                {operatingUnitSource.options
                                                    .length !== 1 ? (
                                                    <RecordLookup
                                                        label="Unit pengusul"
                                                        required
                                                        source={
                                                            operatingUnitSource
                                                        }
                                                        items={operatingUnitSource.options.map(
                                                            (record) => ({
                                                                value: record.id,
                                                                label: record.nama,
                                                            }),
                                                        )}
                                                        value={selectedUnitId}
                                                        placeholder="Pilih unit pengusul"
                                                        portalContainer={
                                                            sheetContentRef
                                                        }
                                                        allowCreate={false}
                                                        onValueChange={(
                                                            value,
                                                        ) =>
                                                            setEditing({
                                                                ...editing,
                                                                planning_org_unit_id:
                                                                    value,
                                                                planning_org_unit_name:
                                                                    operatingUnitSource.options.find(
                                                                        (
                                                                            item,
                                                                        ) =>
                                                                            item.id ===
                                                                            value,
                                                                    )?.nama ??
                                                                    '',
                                                            })
                                                        }
                                                    />
                                                ) : (
                                                    <Input
                                                        label="Unit pengusul"
                                                        value={
                                                            editing.planning_org_unit_name ||
                                                            operatingUnitSource.options.find(
                                                                (item) =>
                                                                    item.id ===
                                                                    selectedUnitId,
                                                            )?.nama ||
                                                            unitName
                                                        }
                                                        readOnly
                                                    />
                                                )}
                                            </FormControl>
                                            <FormControl
                                                data-error-field="planning_year_id"
                                                error={editFieldError(
                                                    'planning_year_id',
                                                )}
                                            >
                                                <PlanningYearLookup
                                                    key={
                                                        selectedCompanyId ??
                                                        'no-company'
                                                    }
                                                    source={yearSource}
                                                    value={
                                                        editing.planning_year_id
                                                    }
                                                    displayValue={
                                                        editing.planning_year ===
                                                        null
                                                            ? undefined
                                                            : String(
                                                                  editing.planning_year,
                                                              )
                                                    }
                                                    legalEntityId={
                                                        selectedCompanyId
                                                    }
                                                    canCreate={
                                                        auth.membership?.permissions.includes(
                                                            'core.reference-data.update',
                                                        ) ?? false
                                                    }
                                                    portalContainer={
                                                        sheetContentRef
                                                    }
                                                    onValueChange={(record) =>
                                                        setEditing({
                                                            ...editing,
                                                            planning_year_id:
                                                                record.id,
                                                            planning_year:
                                                                record.year,
                                                        })
                                                    }
                                                />
                                            </FormControl>
                                            <FormControl
                                                data-error-field="responsible_user_id"
                                                error={editFieldError(
                                                    'responsible_user_id',
                                                )}
                                            >
                                                <RecordLookup
                                                    label="Penanggung jawab"
                                                    required
                                                    source={responsibleSource}
                                                    items={responsibleSource.options.map(
                                                        (record) => ({
                                                            value: record.id,
                                                            label: responsibleSource.getLabel(
                                                                record,
                                                            ),
                                                        }),
                                                    )}
                                                    value={
                                                        editing.responsible_user_id ||
                                                        null
                                                    }
                                                    placeholder="Pilih penanggung jawab"
                                                    ariaLabel="Penanggung jawab"
                                                    portalContainer={
                                                        sheetContentRef
                                                    }
                                                    onCreate={
                                                        auth.membership?.permissions.includes(
                                                            'core.access.update',
                                                        )
                                                            ? () => {
                                                                  window.open(
                                                                      '/settings/access?section=invitations',
                                                                      '_blank',
                                                                      'noopener,noreferrer',
                                                                  );
                                                              }
                                                            : undefined
                                                    }
                                                    onValueChange={(value) =>
                                                        setEditing({
                                                            ...editing,
                                                            responsible_user_id:
                                                                value ?? '',
                                                        })
                                                    }
                                                />
                                            </FormControl>
                                            <FormControl
                                                data-error-field="description"
                                                error={editFieldError(
                                                    'description',
                                                )}
                                            >
                                                <Textarea
                                                    id="planning-description"
                                                    label="Tujuan perencanaan"
                                                    rows={2}
                                                    value={
                                                        editing.description ??
                                                        ''
                                                    }
                                                    onChange={(event) =>
                                                        setEditing({
                                                            ...editing,
                                                            description:
                                                                event.target
                                                                    .value,
                                                        })
                                                    }
                                                />
                                            </FormControl>
                                        </FormGrid>
                                    </CollapsibleSection>
                                    <CollapsibleSection
                                        value="lines"
                                        title="Rincian aset"
                                        keepMounted
                                        summary={`${editing.details.length} baris - ${estimateSummary(editing.details)}`}
                                    >
                                        <DataTable
                                            columns={editColumns}
                                            data={editRows}
                                            getRowKey={({ detail }) =>
                                                detail.lineKey
                                            }
                                            scrollMode="inline"
                                            serverSort
                                            onAddRow={() =>
                                                setEditing({
                                                    ...editing,
                                                    details: [
                                                        ...editing.details,
                                                        emptyDetail(),
                                                    ],
                                                })
                                            }
                                            addRowLabel="Tambah"
                                            emptyMessage="Belum ada rincian aset. Pilih Tambah untuk mengisi."
                                        />
                                        <p className="mt-3 text-right text-sm font-semibold">
                                            Total perkiraan{' '}
                                            {estimateSummary(editing.details)}
                                        </p>
                                    </CollapsibleSection>
                                </CollapsibleSectionGroup>
                            </PageDialogBody>
                        </div>
                    )}
                </PageDialogContent>
            </PageDialog>
        </>
    );
}
