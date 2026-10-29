import { useRef, useState } from 'react';
import { toast } from 'sonner';
import { useToday } from '@/hooks/use-work-date';
import { ActionButton } from '@apperp/ui/action-button';
import { Badge } from '@apperp/ui/badge';

import { DataTable } from '@apperp/ui/data-table';
import type { DataTableColumn } from '@apperp/ui/data-table';
import { DatePicker } from '@apperp/ui/date-picker';

import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@apperp/ui/empty';
import { FieldError } from '@apperp/ui/field';
import { FormControl, FormGrid } from '@apperp/ui/form-control';
import { Input } from '@apperp/ui/input';
import {
    PageDialog,
    PageDialogContent,
    PageDialogToolbar,
    PageDialogClose,
    PageDialogBody,
} from '@apperp/ui/page-dialog';
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { Select } from '@apperp/ui/select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@apperp/ui/tabs';
import { Textarea } from '@apperp/ui/textarea';
import { useRecordNavigation } from '@apperp/ui/use-record-navigation';
import { useRecordTransition } from '@apperp/ui/use-record-transition';
import { day, money } from '../../_shared/format';
import { RecordLookup } from '../../_shared/RecordLookup';
import { RecordMultiLookup } from '../../_shared/RecordMultiLookup';
import { useVendors } from '../../_shared/useVendors';
import { ApiError, api, errorMessage, newIdempotencyKey } from '../../api';
import { optionLabel, useMasterOptions } from '../../master/useMasterOptions';
import { useRecordList } from '../../use-record-list';
import { useSaveFeedback } from '../../use-save-feedback';
import { RecordCardHeader, recordQueryUrl } from '../_shared/record-card';
import { useRecordCardDirty } from '../_shared/use-record-card-dirty';
import { useRecordQueryTarget } from '../_shared/use-record-query-target';
import type { Contract, Expiring, Warranty } from './warranty';
import { WARRANTY_TYPES } from './warranty';

type WarrantyDraft = {
    id: string | null;
    version: number;
    aset_id: string;
    legal_entity_id: string | null;
    vendor_id: string;
    jenis_garansi: string;
    nomor_referensi: string;
    berlaku_mulai: string;
    berlaku_sampai: string;
    catatan: string;
};

type ContractDraft = {
    id: string | null;
    version: number;
    legal_entity_id: string | null;
    nomor_kontrak: string;
    vendor_id: string;
    berlaku_mulai: string;
    berlaku_sampai: string;
    cakupan: string;
    nilai_kontrak: string;
    keterangan: string;
    aset_ids: string[];
};

const WINDOWS = [
    { value: '30', label: '30 hari' },
    { value: '60', label: '60 hari' },
    { value: '90', label: '90 hari' },
];

/** Sisa hari sebagai teks, dengan penanda untuk yang sudah atau hampir habis. */
function remaining(row: { berlaku: boolean; sisa_hari: number }) {
    if (!row.berlaku && row.sisa_hari === 0) {
        return <Badge variant="secondary">Tidak berlaku</Badge>;
    }

    return row.sisa_hari <= 30 ? (
        <Badge variant="destructive">{row.sisa_hari} hari lagi</Badge>
    ) : (
        `${row.sisa_hari} hari lagi`
    );
}

/**
 * Garansi aset dan kontrak servis vendor; padanan *Vendor warranty* pada aset Dynamics 365 F&O.
 *
 * Garansi menempel pada satu aset, kontrak servis menanggung banyak aset dengan satu vendor dan satu
 * periode. Tab ketiga mengumpulkan keduanya yang akan berakhir dalam 30, 60, atau 90 hari.
 */
export default function WarrantyPage({
    context,
    permissions,
}: {
    context: { legal_entity_id: string | null };
    permissions: string[];
}) {
    const {
        scopeRef: pageFeedbackScopeRef,
        feedbackSummary: pageFeedbackSummary,
        reportSaveError: pageFeedbackReportSaveError,
        clearSaveFeedback: pageFeedbackClearSaveFeedback,
    } = useSaveFeedback();
    const pageFeedback = {
        reportSaveError: pageFeedbackReportSaveError,
        clearSaveFeedback: pageFeedbackClearSaveFeedback,
    };

    const can = (permission: string) =>
        permissions.includes(`management-aset.${permission}`);
    const today = useToday();
    const [tab, setTab] = useState(
        can('garansi-aset.read') ? 'garansi' : 'kontrak',
    );
    const [days, setDays] = useState('30');
    const [reload, setReload] = useState(0);
    const [warrantyDraft, setWarrantyDraft] = useState<WarrantyDraft | null>(
        null,
    );
    const [contractDraft, setContractDraft] = useState<ContractDraft | null>(
        null,
    );
    const [openedWarranty, setOpenedWarranty] = useState<Warranty | null>(null);
    const [openedContract, setOpenedContract] = useState<Contract | null>(null);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [busy, setBusy] = useState(false);
    const [warrantyDirty, setWarrantyDirty] = useState(false);
    const [contractDirty, setContractDirty] = useState(false);
    const warrantyRef = useRef<HTMLDivElement>(null);
    const {
        feedbackSummary: warrantyFeedbackSummary,
        reportSaveError: warrantyFeedbackReportSaveError,
        clearSaveFeedback: warrantyFeedbackClearSaveFeedback,
    } = useSaveFeedback(
        warrantyRef,
        warrantyDraft || openedWarranty
            ? `warranty:${openedWarranty?.id ?? 'new'}`
            : 'closed',
    );
    const warrantyFeedback = {
        reportSaveError: warrantyFeedbackReportSaveError,
        clearSaveFeedback: warrantyFeedbackClearSaveFeedback,
    };

    const contractRef = useRef<HTMLDivElement>(null);
    const {
        feedbackSummary: contractFeedbackSummary,
        reportSaveError: contractFeedbackReportSaveError,
        clearSaveFeedback: contractFeedbackClearSaveFeedback,
    } = useSaveFeedback(
        contractRef,
        contractDraft || openedContract
            ? `contract:${openedContract?.id ?? 'new'}`
            : 'closed',
    );
    const contractFeedback = {
        reportSaveError: contractFeedbackReportSaveError,
        clearSaveFeedback: contractFeedbackClearSaveFeedback,
    };

    const assetSource = useMasterOptions(
        warrantyDraft || contractDraft ? 'aset' : null,
        '',
        [
            ...(warrantyDraft?.aset_id ? [warrantyDraft.aset_id] : []),
            ...(contractDraft?.aset_ids ?? []),
        ],
    );
    const assets = assetSource.options;
    const warrantyVendorsSource = useVendors(
        '/garansi-aset/vendor',
        warrantyDraft?.legal_entity_id ?? null,
    );
    const contractVendorsSource = useVendors(
        '/garansi-aset/vendor',
        contractDraft?.legal_entity_id ?? null,
    );
    const warrantyVendor = openedWarranty?.vendor;
    const warrantyVendors =
        warrantyVendor &&
        warrantyVendor.id === warrantyDraft?.vendor_id &&
        !warrantyVendorsSource.options.some(
            (row) => row.id === warrantyVendor.id,
        )
            ? [warrantyVendor, ...warrantyVendorsSource.options]
            : warrantyVendorsSource.options;
    const contractVendor = openedContract?.vendor;
    const contractVendors =
        contractVendor &&
        contractVendor.id === contractDraft?.vendor_id &&
        !contractVendorsSource.options.some(
            (row) => row.id === contractVendor.id,
        )
            ? [contractVendor, ...contractVendorsSource.options]
            : contractVendorsSource.options;
    const message = (field: string) => errors[field]?.[0];
    const refresh = () => setReload((current) => current + 1);

    const warrantyList = useRecordList<Warranty>(
        '/garansi-aset',
        '',
        { refresh: String(reload) },
        tab === 'garansi' && can('garansi-aset.read'),
    );
    const contractList = useRecordList<Contract>(
        '/kontrak-servis',
        '',
        { refresh: String(reload) },
        tab === 'kontrak' && can('kontrak-servis.read'),
    );
    const expiryList = useRecordList<Expiring>(
        '/garansi-aset/akan-berakhir',
        '',
        { hari: days, refresh: String(reload) },
        tab === 'berakhir',
        undefined,
        (row) => `${row.jenis}:${row.id}`,
    );
    const warranties =
        warrantyList.loading && !warrantyList.items.length
            ? null
            : warrantyList.items;
    const contracts =
        contractList.loading && !contractList.items.length
            ? null
            : contractList.items;
    const expiring =
        expiryList.loading && !expiryList.items.length
            ? null
            : { days, items: expiryList.items };

    const assetChoices = assets.map((asset) => ({
        value: asset.id,
        label: optionLabel(asset),
    }));
    const legalEntityOf = (asetId: string) => {
        const asset = assets.find((item) => item.id === asetId);

        return typeof asset?.legal_entity_id === 'string'
            ? asset.legal_entity_id
            : context.legal_entity_id;
    };

    async function saveWarranty() {
        if (!warrantyDraft) {
            return;
        }

        const draft = warrantyDraft;
        setBusy(true);
        setErrors({});

        try {
            const body = {
                vendor_id: draft.vendor_id || null,
                jenis_garansi: draft.jenis_garansi,
                nomor_referensi: draft.nomor_referensi || null,
                berlaku_mulai: draft.berlaku_mulai,
                berlaku_sampai: draft.berlaku_sampai,
                catatan: draft.catatan || null,
            };
            await (draft.id
                ? api(`/garansi-aset/${draft.id}`, {
                      method: 'PATCH',
                      body: JSON.stringify({ ...body, version: draft.version }),
                  })
                : api('/garansi-aset', {
                      method: 'POST',
                      headers: { 'Idempotency-Key': newIdempotencyKey() },
                      body: JSON.stringify({ ...body, aset_id: draft.aset_id }),
                  }));
            warrantyFeedback.clearSaveFeedback();
            toast.success('Garansi disimpan.');
            clearRecordTarget();
            setWarrantyDraft(null);
            refresh();
        } catch (caught) {
            if (caught instanceof ApiError) {
                setErrors(caught.validationErrors);
            }

            warrantyFeedback.reportSaveError(
                caught,
                'Garansi belum dapat disimpan.',
            );
        } finally {
            setBusy(false);
        }
    }

    async function saveContract() {
        if (!contractDraft) {
            return;
        }

        const draft = contractDraft;
        setBusy(true);
        setErrors({});

        try {
            const body = {
                legal_entity_id: draft.legal_entity_id,
                nomor_kontrak: draft.nomor_kontrak,
                vendor_id: draft.vendor_id,
                berlaku_mulai: draft.berlaku_mulai,
                berlaku_sampai: draft.berlaku_sampai,
                cakupan: draft.cakupan || null,
                nilai_kontrak:
                    draft.nilai_kontrak === ''
                        ? null
                        : Number(draft.nilai_kontrak),
                keterangan: draft.keterangan || null,
                aset_ids: draft.aset_ids,
            };
            await (draft.id
                ? api(`/kontrak-servis/${draft.id}`, {
                      method: 'PATCH',
                      body: JSON.stringify({ ...body, version: draft.version }),
                  })
                : api('/kontrak-servis', {
                      method: 'POST',
                      headers: { 'Idempotency-Key': newIdempotencyKey() },
                      body: JSON.stringify(body),
                  }));
            contractFeedback.clearSaveFeedback();
            toast.success('Kontrak servis disimpan.');
            clearRecordTarget();
            setContractDraft(null);
            refresh();
        } catch (caught) {
            if (caught instanceof ApiError) {
                setErrors(caught.validationErrors);
            }

            contractFeedback.reportSaveError(
                caught,
                'Kontrak servis belum dapat disimpan.',
            );
        } finally {
            setBusy(false);
        }
    }

    async function archive(path: string, version: number, done: string) {
        setBusy(true);

        try {
            await api(path, {
                method: 'DELETE',
                body: JSON.stringify({ version }),
            });
            pageFeedback.clearSaveFeedback();
            toast.success(done);
            clearRecordTarget();
            refresh();

            return true;
        } catch (caught) {
            (openedWarranty && path.endsWith(`/${openedWarranty.id}`)
                ? warrantyFeedback
                : openedContract && path.endsWith(`/${openedContract.id}`)
                  ? contractFeedback
                  : pageFeedback
            ).reportSaveError(caught, 'Belum dapat diarsipkan.');

            return false;
        } finally {
            setBusy(false);
        }
    }

    async function openContract(
        contract: Pick<Contract, 'id'>,
        signal?: AbortSignal,
    ) {
        try {
            const result = await api<{ data: Contract }>(
                `/kontrak-servis/${contract.id}`,
                { signal },
            );
            setErrors({});

            if (signal?.aborted) {
                return;
            }

            setOpenedContract(result.data);
            setContractDraft({
                id: result.data.id,
                version: result.data.version,
                legal_entity_id: result.data.legal_entity_id,
                nomor_kontrak: result.data.nomor_kontrak,
                vendor_id: result.data.vendor_id,
                berlaku_mulai: result.data.berlaku_mulai,
                berlaku_sampai: result.data.berlaku_sampai,
                cakupan: result.data.cakupan ?? '',
                nilai_kontrak: result.data.nilai_kontrak ?? '',
                keterangan: result.data.keterangan ?? '',
                aset_ids: (result.data.aset ?? []).map((line) => line.aset_id),
            });
        } catch (caught) {
            if (signal?.aborted) {
                return;
            }

            toast.error(errorMessage(caught, 'Kontrak belum dapat dibuka.'));
        }
    }

    const clearRecordTarget = useRecordQueryTarget({
        scopeKey: 'garansi-kontrak-servis',
        enabled: can('kontrak-servis.read') || can('garansi-aset.read'),
        onOpen: async (id, signal, kind) => {
            if (kind === 'contract' && can('kontrak-servis.read')) {
                setTab('kontrak');
                await openContract({ id }, signal);
            } else if (kind === 'warranty' && can('garansi-aset.read')) {
                const result = await api<{ data: Warranty }>(
                    '/garansi-aset/' + id,
                    { signal },
                );

                if (!signal.aborted) {
                    setTab('garansi');
                    editWarranty(result.data);
                }
            }
        },
    });
    const warrantyColumns: DataTableColumn<Warranty>[] = [
        {
            id: 'aset',
            header: 'Aset',
            cell: (row) =>
                [row.aset_kode, row.aset_nama].filter(Boolean).join(' · '),
            sortValue: (row) => row.aset_kode,
            minWidth: 200,
        },
        {
            id: 'jenis',
            header: 'Garansi',
            cell: (row) => row.jenis_garansi_label,
            width: 110,
        },
        {
            id: 'vendor',
            header: 'Penjamin',
            cell: (row) => row.vendor?.name ?? '—',
            width: 180,
        },
        {
            id: 'referensi',
            header: 'No. kartu garansi',
            cell: (row) => row.nomor_referensi ?? '—',
            width: 150,
        },
        {
            id: 'periode',
            header: 'Masa berlaku',
            cell: (row) =>
                `${day(row.berlaku_mulai)} – ${day(row.berlaku_sampai)}`,
            sortValue: (row) => row.berlaku_sampai,
            width: 200,
        },
        {
            id: 'sisa',
            header: 'Sisa',
            cell: remaining,
            width: 140,
        },
    ];

    const contractColumns: DataTableColumn<Contract>[] = [
        { id: 'kode', header: 'No.', cell: (row) => row.kode, width: 130 },
        {
            id: 'nomor',
            header: 'Nomor kontrak',
            cell: (row) => row.nomor_kontrak,
            width: 160,
        },
        {
            id: 'vendor',
            header: 'Vendor servis',
            cell: (row) => row.vendor?.name ?? '—',
            minWidth: 180,
        },
        {
            id: 'aset',
            header: 'Aset',
            cell: (row) => `${row.lines_count ?? 0} aset`,
            align: 'right',
            width: 100,
        },
        {
            id: 'periode',
            header: 'Masa berlaku',
            cell: (row) =>
                `${day(row.berlaku_mulai)} – ${day(row.berlaku_sampai)}`,
            sortValue: (row) => row.berlaku_sampai,
            width: 200,
        },
        {
            id: 'nilai',
            header: 'Nilai kontrak',
            cell: (row) => money(row.nilai_kontrak),
            align: 'right',
            width: 150,
        },
        { id: 'sisa', header: 'Sisa', cell: remaining, width: 140 },
    ];

    const expiringColumns: DataTableColumn<Expiring>[] = [
        {
            id: 'jenis',
            header: 'Jenis',
            cell: (row) =>
                row.jenis === 'garansi' ? 'Garansi' : 'Kontrak servis',
            width: 140,
        },
        {
            id: 'untuk',
            header: 'Untuk',
            cell: (row) =>
                row.jenis === 'garansi'
                    ? [row.aset_kode, row.aset_nama].filter(Boolean).join(' · ')
                    : `${row.referensi ?? ''} · ${row.jumlah_aset} aset`,
            minWidth: 220,
        },
        {
            id: 'vendor',
            header: 'Vendor',
            cell: (row) => row.vendor_nama ?? '—',
            width: 180,
        },
        {
            id: 'sampai',
            header: 'Berakhir',
            cell: (row) => day(row.berlaku_sampai),
            sortValue: (row) => row.berlaku_sampai,
            width: 130,
        },
        {
            id: 'sisa',
            header: 'Sisa',
            cell: (row) => `${row.sisa_hari} hari`,
            sortValue: (row) => row.sisa_hari,
            align: 'right',
            width: 100,
        },
    ];

    const expiringItems =
        expiring && expiring.days === days ? expiring.items : [];

    const warrantyEditable = warrantyDraft?.id
        ? can('garansi-aset.update')
        : can('garansi-aset.create');
    const {
        request: requestWarrantyTransition,
        confirmation: warrantyTransitionConfirmation,
    } = useRecordTransition(warrantyDirty, busy);
    const {
        request: requestContractTransition,
        confirmation: contractTransitionConfirmation,
    } = useRecordTransition(contractDirty, busy);
    const newWarranty = () => {
        requestWarrantyTransition(() => {
            clearRecordTarget();
            (() => {
                setErrors({});
                setWarrantyDraft({
                    id: null,
                    version: 0,
                    aset_id: '',
                    legal_entity_id: null,
                    vendor_id: '',
                    jenis_garansi: 'penuh',
                    nomor_referensi: '',
                    berlaku_mulai: today,
                    berlaku_sampai: '',
                    catatan: '',
                });
            })();
        });
    };
    const newContract = () => {
        requestContractTransition(() => {
            clearRecordTarget();
            (() => {
                setErrors({});
                setContractDraft({
                    id: null,
                    version: 0,
                    legal_entity_id: context.legal_entity_id,
                    nomor_kontrak: '',
                    vendor_id: '',
                    berlaku_mulai: today,
                    berlaku_sampai: '',
                    cakupan: '',
                    nilai_kontrak: '',
                    keterangan: '',
                    aset_ids: [],
                });
            })();
        });
    };
    const closeWarranty = () => {
        requestWarrantyTransition(() => {
            clearRecordTarget();
            setWarrantyDraft(null);
        });
    };
    const closeContract = () => {
        requestContractTransition(() => {
            clearRecordTarget();
            setContractDraft(null);
        });
    };
    useRecordCardDirty({
        value: warrantyDraft,
        ready: warrantyDraft !== null,
        scopeKey: warrantyDraft?.id ?? 'new',
        onDirtyChange: setWarrantyDirty,
    });
    useRecordCardDirty({
        value: contractDraft,
        ready: contractDraft !== null,
        scopeKey: contractDraft?.id ?? 'new',
        onDirtyChange: setContractDirty,
    });
    const editWarranty = (row: Warranty) => {
        setOpenedWarranty(row);
        setErrors({});
        setWarrantyDraft({
            id: row.id,
            version: row.version,
            aset_id: row.aset_id,
            legal_entity_id: row.legal_entity_id,
            vendor_id: row.vendor_id ?? '',
            jenis_garansi: row.jenis_garansi,
            nomor_referensi: row.nomor_referensi ?? '',
            berlaku_mulai: row.berlaku_mulai,
            berlaku_sampai: row.berlaku_sampai,
            catatan: row.catatan ?? '',
        });
    };
    const selectWarranty = (id: string) => {
        const row = warrantyList.items.find((item) => item.id === id);

        if (row) {
            editWarranty(row);
        }
    };
    const warrantyNavigation = useRecordNavigation({
        items: warrantyList.items,
        currentId: warrantyDraft?.id ?? null,
        getId: (item) => item.id,
        onNavigate: selectWarranty,
        scopeKey: JSON.stringify([tab, reload, warrantyList.sort]),
        hasMore: warrantyList.hasMore,
        loading: warrantyList.loading,
        loadMore: warrantyList.loadMore,
        disabled: busy || warrantyDirty,
    });
    const contractNavigation = useRecordNavigation({
        items: contractList.items,
        currentId: contractDraft?.id ?? null,
        getId: (item) => item.id,
        onNavigate: (id) => {
            const row = contractList.items.find((item) => item.id === id);

            if (row) {
                void openContract(row);
            }
        },
        scopeKey: JSON.stringify([tab, reload, contractList.sort]),
        hasMore: contractList.hasMore,
        loading: contractList.loading,
        loadMore: contractList.loadMore,
        disabled: busy || contractDirty,
    });

    return (
        <Tabs ref={pageFeedbackScopeRef} value={tab} onValueChange={setTab}>
            {warrantyTransitionConfirmation}
            {contractTransitionConfirmation}
            <RecordActionBar
                feedback={pageFeedbackSummary}
                title="Garansi dan kontrak servis"
            >
                {tab === 'garansi' && can('garansi-aset.create') && (
                    <ActionButton
                        action="create"
                        type="button"
                        onClick={newWarranty}
                    />
                )}
                {tab === 'kontrak' && can('kontrak-servis.create') && (
                    <ActionButton
                        action="create"
                        type="button"
                        disabled={!context.legal_entity_id}
                        onClick={newContract}
                    />
                )}
                <TabsList>
                    {can('garansi-aset.read') && (
                        <TabsTrigger value="garansi">Garansi</TabsTrigger>
                    )}
                    {can('kontrak-servis.read') && (
                        <TabsTrigger value="kontrak">
                            Kontrak servis
                        </TabsTrigger>
                    )}
                    <TabsTrigger value="berakhir">Akan berakhir</TabsTrigger>
                </TabsList>
            </RecordActionBar>

            <TabsContent value="garansi">
                {warranties !== null &&
                warranties.length === 0 &&
                !warrantyList.error ? (
                    <Empty>
                        <EmptyHeader>
                            <EmptyTitle>Belum ada garansi tercatat</EmptyTitle>
                            <EmptyDescription>
                                Catat garansi dari vendor atau pabrikan supaya
                                perbaikan selama masa garansi diarahkan ke
                                penjaminnya.
                            </EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <DataTable
                        columns={warrantyColumns}
                        incremental={warrantyList}
                        sort={warrantyList.sort}
                        onSortChange={warrantyList.onSortChange}
                        data={warranties ?? []}
                        getRowKey={(row) => row.id}
                        getRowLabel={(row) => row.aset_kode}
                        actions={[
                            ...(can('garansi-aset.update')
                                ? [{ id: 'ubah', label: 'Ubah' }]
                                : []),
                            ...(can('garansi-aset.archive')
                                ? [
                                      {
                                          id: 'arsip',
                                          label: 'Delete',
                                          destructive: true,
                                      },
                                  ]
                                : []),
                        ]}
                        onRowAction={(id, row) => {
                            if (id === 'arsip') {
                                void archive(
                                    `/garansi-aset/${row.id}`,
                                    row.version,
                                    'Garansi diarsipkan.',
                                );

                                return;
                            }

                            editWarranty(row);
                        }}
                    />
                )}
            </TabsContent>

            <TabsContent value="kontrak">
                {contracts !== null &&
                contracts.length === 0 &&
                !contractList.error ? (
                    <Empty>
                        <EmptyHeader>
                            <EmptyTitle>Belum ada kontrak servis</EmptyTitle>
                            <EmptyDescription>
                                Catat kontrak pemeliharaan dengan vendor beserta
                                aset yang ditanggungnya.
                            </EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <DataTable
                        columns={contractColumns}
                        incremental={contractList}
                        sort={contractList.sort}
                        onSortChange={contractList.onSortChange}
                        data={contracts ?? []}
                        getRowKey={(row) => row.id}
                        getRowLabel={(row) => row.kode}
                        onRowClick={(row) => void openContract(row)}
                        actions={
                            can('kontrak-servis.archive')
                                ? [
                                      {
                                          id: 'arsip',
                                          label: 'Delete',
                                          destructive: true,
                                      },
                                  ]
                                : []
                        }
                        onRowAction={(id, row) =>
                            id === 'arsip' &&
                            void archive(
                                `/kontrak-servis/${row.id}`,
                                row.version,
                                'Kontrak servis diarsipkan.',
                            )
                        }
                    />
                )}
            </TabsContent>

            <TabsContent value="berakhir">
                <div className="border-b px-5 py-3">
                    <div className="w-full sm:w-48">
                        <FormControl>
                            <Select
                                label="Berakhir dalam"
                                items={WINDOWS}
                                value={days}
                                ariaLabel="Rentang hari"
                                onValueChange={(value) =>
                                    setDays(value ?? '30')
                                }
                            />
                        </FormControl>
                    </div>
                </div>
                {expiring !== null &&
                expiringItems.length === 0 &&
                !expiryList.error ? (
                    <Empty>
                        <EmptyHeader>
                            <EmptyTitle>
                                Tidak ada yang berakhir dalam {days} hari
                            </EmptyTitle>
                            <EmptyDescription>
                                Garansi dan kontrak servis yang akan habis
                                tampil di sini supaya sempat diperpanjang.
                            </EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <DataTable
                        columns={expiringColumns}
                        incremental={expiryList}
                        sort={expiryList.sort}
                        onSortChange={expiryList.onSortChange}
                        data={expiringItems}
                        getRowKey={(row) => `${row.jenis}-${row.id}`}
                        getRowLabel={(row) => row.referensi ?? row.id}
                    />
                )}
            </TabsContent>

            <PageDialog
                open={warrantyDraft !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        closeWarranty();
                    }
                }}
            >
                <PageDialogContent
                    ref={warrantyRef}
                    title={'Garansi dan kontrak servis'}
                    busy={busy}
                    onNew={
                        warrantyDraft?.id && can('garansi-aset.create')
                            ? newWarranty
                            : undefined
                    }
                    navigation={
                        warrantyDraft?.id ? warrantyNavigation : undefined
                    }
                >
                    <RecordCardHeader
                        recordTitle={
                            warrantyDraft?.nomor_referensi ||
                            (openedWarranty?.id === warrantyDraft?.id
                                ? openedWarranty?.aset_kode
                                : assets.find(
                                      (a) => a.id === warrantyDraft?.aset_id,
                                  )?.kode) ||
                            'Baru'
                        }
                        editing={warrantyEditable}
                        recordUrl={
                            warrantyDraft?.id
                                ? recordQueryUrl(
                                      'garansi-kontrak-servis',
                                      warrantyDraft.id,
                                      'warranty',
                                  )
                                : undefined
                        }
                        onDelete={
                            warrantyDraft?.id && can('garansi-aset.archive')
                                ? async () => {
                                      if (
                                          await archive(
                                              '/garansi-aset/' +
                                                  warrantyDraft.id,
                                              warrantyDraft.version,
                                              'Garansi diarsipkan.',
                                          )
                                      ) {
                                          setWarrantyDraft(null);
                                      }
                                  }
                                : undefined
                        }
                    />
                    {warrantyEditable && (
                        <PageDialogToolbar>
                            <ActionButton
                                action="save"
                                type="button"
                                disabled={
                                    busy ||
                                    !warrantyDraft?.aset_id ||
                                    !warrantyDraft?.berlaku_mulai ||
                                    !warrantyDraft?.berlaku_sampai
                                }
                                onClick={() => void saveWarranty()}
                                pending={busy}
                            />
                            <PageDialogClose asChild>
                                <ActionButton action="cancel" type="button" />
                            </PageDialogClose>
                        </PageDialogToolbar>
                    )}
                    {warrantyFeedbackSummary}
                    {warrantyDraft && (
                        <PageDialogBody className="space-y-4">
                            <FormControl data-error-field="aset_id">
                                {warrantyEditable ? (
                                    <RecordLookup
                                        source={assetSource}
                                        label="Aset"
                                        required
                                        items={assetChoices}
                                        value={warrantyDraft.aset_id || null}
                                        placeholder="Pilih aset"
                                        searchPlaceholder="Cari kode atau nama aset"
                                        ariaLabel="Aset"
                                        portalContainer={warrantyRef}
                                        onValueChange={(value) =>
                                            !warrantyDraft.id &&
                                            setWarrantyDraft({
                                                ...warrantyDraft,
                                                aset_id: value ?? '',
                                                legal_entity_id: value
                                                    ? legalEntityOf(value)
                                                    : null,
                                                vendor_id: '',
                                            })
                                        }
                                    />
                                ) : (
                                    <Input
                                        label="Aset"
                                        readOnly
                                        value={
                                            openedWarranty?.aset_id ===
                                            warrantyDraft.aset_id
                                                ? [
                                                      openedWarranty.aset_kode,
                                                      openedWarranty.aset_nama,
                                                  ]
                                                      .filter(Boolean)
                                                      .join(' · ')
                                                : (assets.find(
                                                      (row) =>
                                                          row.id ===
                                                          warrantyDraft.aset_id,
                                                  )?.nama ?? '—')
                                        }
                                    />
                                )}
                                {message('aset_id') && (
                                    <FieldError>
                                        {message('aset_id')}
                                    </FieldError>
                                )}
                            </FormControl>
                            <FormControl
                                data-error-field="vendor_id"
                                error={message('vendor_id')}
                                hint={
                                    'Boleh kosong bila penjaminnya bukan vendor yang tercatat.'
                                }
                            >
                                {warrantyEditable ? (
                                    <RecordLookup
                                        source={warrantyVendorsSource}
                                        label="Penjamin"
                                        items={warrantyVendors.map(
                                            (vendor) => ({
                                                value: vendor.id,
                                                label: `${vendor.number} — ${vendor.name}`,
                                            }),
                                        )}
                                        value={warrantyDraft.vendor_id || null}
                                        placeholder={
                                            warrantyDraft.aset_id
                                                ? 'Pilih vendor'
                                                : 'Pilih aset lebih dahulu'
                                        }
                                        searchPlaceholder="Cari nomor atau nama vendor"
                                        ariaLabel="Penjamin"
                                        portalContainer={warrantyRef}
                                        onValueChange={(value) =>
                                            setWarrantyDraft({
                                                ...warrantyDraft,
                                                vendor_id: value ?? '',
                                            })
                                        }
                                    />
                                ) : (
                                    <Input
                                        label="Penjamin"
                                        readOnly
                                        value={
                                            warrantyVendors.find(
                                                (row) =>
                                                    row.id ===
                                                    warrantyDraft.vendor_id,
                                            )?.name ?? '—'
                                        }
                                    />
                                )}
                            </FormControl>
                            <FormControl
                                data-error-field="jenis_garansi"
                                hint={
                                    <>
                                        Sebagian berarti hanya bagian tertentu
                                        yang ditanggung, misalnya suku cadang
                                        tanpa jasa. Tulis rinciannya di catatan.
                                    </>
                                }
                            >
                                {warrantyEditable ? (
                                    <Select
                                        label="Cakupan garansi"
                                        required
                                        items={WARRANTY_TYPES}
                                        value={warrantyDraft.jenis_garansi}
                                        ariaLabel="Cakupan garansi"
                                        portalContainer={warrantyRef}
                                        onValueChange={(value) =>
                                            setWarrantyDraft({
                                                ...warrantyDraft,
                                                jenis_garansi: value ?? 'penuh',
                                            })
                                        }
                                    />
                                ) : (
                                    <Input
                                        label="Cakupan garansi"
                                        readOnly
                                        value={
                                            WARRANTY_TYPES.find(
                                                (row) =>
                                                    row.value ===
                                                    warrantyDraft.jenis_garansi,
                                            )?.label ?? '—'
                                        }
                                    />
                                )}
                            </FormControl>
                            <FormControl data-error-field="nomor_referensi">
                                <Input
                                    readOnly={!warrantyEditable}
                                    label="No. kartu garansi"
                                    maxLength={80}
                                    value={warrantyDraft.nomor_referensi}
                                    onChange={(event) =>
                                        setWarrantyDraft({
                                            ...warrantyDraft,
                                            nomor_referensi: event.target.value,
                                        })
                                    }
                                />
                            </FormControl>
                            <FormGrid>
                                <FormControl data-error-field="berlaku_mulai">
                                    <DatePicker
                                        portalContainer={warrantyRef}
                                        readOnly={!warrantyEditable}
                                        label="Berlaku mulai"

                                        required
                                        value={warrantyDraft.berlaku_mulai}
                                        onValueChange={(value) =>
                                            setWarrantyDraft({
                                                ...warrantyDraft,
                                                berlaku_mulai: value,
                                            })
                                        }
                                    />
                                </FormControl>
                                <FormControl data-error-field="berlaku_sampai">
                                    <DatePicker
                                        portalContainer={warrantyRef}
                                        readOnly={!warrantyEditable}
                                        label="Berlaku sampai"

                                        required
                                        value={warrantyDraft.berlaku_sampai}
                                        onValueChange={(value) =>
                                            setWarrantyDraft({
                                                ...warrantyDraft,
                                                berlaku_sampai: value,
                                            })
                                        }
                                    />
                                </FormControl>
                            </FormGrid>
                            {message('berlaku_sampai') && (
                                <p className="text-destructive text-sm">
                                    {message('berlaku_sampai')}
                                </p>
                            )}
                            <FormControl data-error-field="catatan">
                                <Textarea
                                    readOnly={!warrantyEditable}
                                    rows={2}
                                    maxLength={2000}
                                    placeholder="Catatan, misalnya syarat klaim"
                                    value={warrantyDraft.catatan}
                                    onChange={(event) =>
                                        setWarrantyDraft({
                                            ...warrantyDraft,
                                            catatan: event.target.value,
                                        })
                                    }
                                />
                            </FormControl>
                        </PageDialogBody>
                    )}
                </PageDialogContent>
            </PageDialog>

            <PageDialog
                open={contractDraft !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        closeContract();
                    }
                }}
            >
                <PageDialogContent
                    ref={contractRef}
                    title={'Garansi dan kontrak servis'}
                    busy={busy}
                    onNew={
                        contractDraft?.id &&
                        can('kontrak-servis.create') &&
                        context.legal_entity_id
                            ? newContract
                            : undefined
                    }
                    navigation={
                        contractDraft?.id ? contractNavigation : undefined
                    }
                >
                    <RecordCardHeader
                        recordTitle={contractDraft?.nomor_kontrak || 'Baru'}
                        editing={
                            contractDraft?.id
                                ? can('kontrak-servis.update')
                                : true
                        }
                        onDelete={
                            contractDraft?.id && can('kontrak-servis.archive')
                                ? async () => {
                                      if (
                                          await archive(
                                              '/kontrak-servis/' +
                                                  contractDraft.id,
                                              contractDraft.version,
                                              'Kontrak servis diarsipkan.',
                                          )
                                      ) {
                                          clearRecordTarget();
                                          setContractDraft(null);
                                      }
                                  }
                                : undefined
                        }
                        recordUrl={
                            contractDraft?.id
                                ? recordQueryUrl(
                                      'garansi-kontrak-servis',
                                      contractDraft.id,
                                      'contract',
                                  )
                                : undefined
                        }
                    />
                    {(contractDraft?.id
                        ? can('kontrak-servis.update')
                        : can('kontrak-servis.create')) && (
                        <PageDialogToolbar>
                            {(contractDraft?.id
                                ? can('kontrak-servis.update')
                                : can('kontrak-servis.create')) && (
                                <ActionButton
                                    action="save"
                                    type="button"
                                    disabled={
                                        busy ||
                                        !contractDraft?.nomor_kontrak ||
                                        !contractDraft?.vendor_id ||
                                        !contractDraft?.berlaku_sampai
                                    }
                                    onClick={() => void saveContract()}
                                    pending={busy}
                                />
                            )}
                            {(contractDraft?.id
                                ? can('kontrak-servis.update')
                                : can('kontrak-servis.create')) && (
                                <ActionButton
                                    action="cancel"
                                    onClick={closeContract}
                                    disabled={busy}
                                />
                            )}
                        </PageDialogToolbar>
                    )}
                    {contractFeedbackSummary}
                    {contractDraft && (
                        <PageDialogBody className="space-y-4">
                            <FormGrid>
                                <FormControl data-error-field="nomor_kontrak">
                                    <Input
                                        label="Nomor kontrak"
                                        required
                                        maxLength={80}
                                        readOnly={
                                            !can('kontrak-servis.update') &&
                                            contractDraft.id !== null
                                        }
                                        value={contractDraft.nomor_kontrak}
                                        onChange={(event) =>
                                            setContractDraft({
                                                ...contractDraft,
                                                nomor_kontrak:
                                                    event.target.value,
                                            })
                                        }
                                    />
                                </FormControl>
                                <FormControl data-error-field="vendor_id">
                                    <RecordLookup
                                        source={contractVendorsSource}
                                        label="Vendor servis"
                                        required
                                        items={contractVendors.map(
                                            (vendor) => ({
                                                value: vendor.id,
                                                label: `${vendor.number} — ${vendor.name}`,
                                            }),
                                        )}
                                        value={contractDraft.vendor_id || null}
                                        placeholder="Pilih vendor"
                                        searchPlaceholder="Cari nomor atau nama vendor"
                                        ariaLabel="Vendor servis"
                                        portalContainer={contractRef}
                                        onValueChange={(value) =>
                                            setContractDraft({
                                                ...contractDraft,
                                                vendor_id: value ?? '',
                                            })
                                        }
                                    />
                                    {message('vendor_id') && (
                                        <FieldError>
                                            {message('vendor_id')}
                                        </FieldError>
                                    )}
                                </FormControl>
                                <FormControl data-error-field="berlaku_mulai">
                                    <DatePicker
                                        portalContainer={contractRef}
                                        label="Berlaku mulai"

                                        required
                                        value={contractDraft.berlaku_mulai}
                                        onValueChange={(value) =>
                                            setContractDraft({
                                                ...contractDraft,
                                                berlaku_mulai: value,
                                            })
                                        }
                                    />
                                </FormControl>
                                <FormControl data-error-field="berlaku_sampai">
                                    <DatePicker
                                        portalContainer={contractRef}
                                        label="Berlaku sampai"

                                        required
                                        value={contractDraft.berlaku_sampai}
                                        onValueChange={(value) =>
                                            setContractDraft({
                                                ...contractDraft,
                                                berlaku_sampai: value,
                                            })
                                        }
                                    />
                                </FormControl>
                                <FormControl data-error-field="nilai_kontrak">
                                    <Input
                                        label="Nilai kontrak"
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        value={contractDraft.nilai_kontrak}
                                        onChange={(event) =>
                                            setContractDraft({
                                                ...contractDraft,
                                                nilai_kontrak:
                                                    event.target.value,
                                            })
                                        }
                                    />
                                </FormControl>
                            </FormGrid>
                            {message('berlaku_sampai') && (
                                <p className="text-destructive text-sm">
                                    {message('berlaku_sampai')}
                                </p>
                            )}
                            <FormControl data-error-field="aset_ids">
                                <RecordMultiLookup
                                    source={assetSource}
                                    label="Aset yang ditanggung"
                                    items={assetChoices}
                                    value={contractDraft.aset_ids}
                                    placeholder="Pilih aset"
                                    portalContainer={contractRef}
                                    onValueChange={(value) =>
                                        setContractDraft({
                                            ...contractDraft,
                                            aset_ids: value,
                                        })
                                    }
                                />
                                {message('aset_ids') && (
                                    <FieldError>
                                        {message('aset_ids')}
                                    </FieldError>
                                )}
                            </FormControl>
                            <FormControl data-error-field="cakupan">
                                <Textarea
                                    rows={3}
                                    maxLength={4000}
                                    placeholder="Cakupan, misalnya kunjungan bulanan, jasa, dan suku cadang yang ditanggung"
                                    value={contractDraft.cakupan}
                                    onChange={(event) =>
                                        setContractDraft({
                                            ...contractDraft,
                                            cakupan: event.target.value,
                                        })
                                    }
                                />
                            </FormControl>
                            <FormControl data-error-field="keterangan">
                                <Textarea
                                    rows={2}
                                    maxLength={2000}
                                    placeholder="Keterangan"
                                    value={contractDraft.keterangan}
                                    onChange={(event) =>
                                        setContractDraft({
                                            ...contractDraft,
                                            keterangan: event.target.value,
                                        })
                                    }
                                />
                            </FormControl>
                        </PageDialogBody>
                    )}
                </PageDialogContent>
            </PageDialog>
        </Tabs>
    );
}
