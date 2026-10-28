import { CheckCircle2, Clock, XCircle } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
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
import { DataTable } from '@apperp/ui/data-table';
import type {
    DataTableColumn,
    DataTableRowAction,
} from '@apperp/ui/data-table';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@apperp/ui/empty';
import { Input } from '@apperp/ui/input';
import { Select } from '@apperp/ui/select';
import { useConfirmation } from '@apperp/ui/use-confirmation';
import type { ModulePageProps } from '../App';
import { api, saveErrorMessage } from '../api';
import { useRecordList } from '../use-record-list';
import { useSaveFeedback } from '../use-save-feedback';
import { VendorPrequalificationDetailDialog } from './prakualifikasi/VendorPrequalificationDetailDialog';
import { VendorPrequalificationFormDialog } from './prakualifikasi/VendorPrequalificationFormDialog';
import type { VendorPrequalificationDetail } from './prakualifikasi/VendorPrequalificationFormDialog';

export type VendorPrequalificationSummaryRow = {
    id: string;
    nomor_prakualifikasi: string;
    tanggal_prakualifikasi: string;
    vendor_id: string;
    kode_vendor: string;
    nama_vendor: string;
    jenis_vendor?: string | null;
    nama_pic?: string | null;
    npwp_ktp?: string | null;
    telepon?: string | null;
    email?: string | null;
    media_sosial?: string | null;
    alamat?: string | null;
    status_vendor?: string | null;
    status_badan_hukum: string;
    status_kualifikasi: string;
    waktu_kesediaan_item?: string | null;
    jumlah_karyawan?: number | null;
    catatan?: string | null;
    version: number;
    created_at?: string;
    updated_at?: string;
};

function formatDisplayDate(dateStr: string): string {
    if (!dateStr) {
        return '—';
    }

    if (dateStr.includes('/')) {
        return dateStr;
    }

    const parts = dateStr.split('-');

    if (parts.length === 3) {
        return `${parts[2]}/${parts[1]}/${parts[0]}`;
    }

    return dateStr;
}

export default function VendorPrequalificationPage({
    permissions = [],
    context,
}: Partial<ModulePageProps>) {
    const { confirm: confirmAction, confirmation: actionConfirmation } =
        useConfirmation();

    const canCreate = permissions.includes('procurement.prakualifikasi.create');
    const canUpdate = permissions.includes('procurement.prakualifikasi.update');
    const canArchive = permissions.includes(
        'procurement.prakualifikasi.archive',
    );
    const canCreateCoreVendor =
        Boolean(context?.legal_entity_id) &&
        permissions.includes('core.vendor.read') &&
        permissions.includes('core.vendor.update');

    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState('semua');

    const {
        scopeRef: mutationScopeRef,
        feedbackSummary: mutationFeedback,
        reportSaveError: reportMutationError,
        clearSaveFeedback: clearMutationFeedback,
    } = useSaveFeedback(undefined, 'prakualifikasi');

    const query = useMemo(() => {
        const params = new URLSearchParams();

        if (statusFilter !== 'semua') {
            params.set('status_kualifikasi', statusFilter);
        }

        return params.toString();
    }, [statusFilter]);

    const list = useRecordList<VendorPrequalificationSummaryRow>(
        `/prakualifikasi?${query}`,
        search,
    );
    const { items, loading } = list;

    const [formDialogOpen, setFormDialogOpen] = useState(false);
    const [editingRecord, setEditingRecord] =
        useState<VendorPrequalificationDetail | null>(null);

    const [detailDialogOpen, setDetailDialogOpen] = useState(false);
    const [detailRecord, setDetailRecord] =
        useState<VendorPrequalificationDetail | null>(null);
    const [detailLoading, setDetailLoading] = useState(false);
    const [detailError, setDetailError] = useState<string | null>(null);
    const [activeDetailId, setActiveDetailId] = useState<string | null>(null);
    const detailAbortRef = useRef<AbortController | null>(null);

    useEffect(() => {
        return () => {
            detailAbortRef.current?.abort();
        };
    }, []);

    const load = useCallback(() => {
        clearMutationFeedback();
        list.reload();
    }, [clearMutationFeedback, list]);

    const fetchDetail = useCallback(
        async (id: string): Promise<VendorPrequalificationDetail | null> => {
            detailAbortRef.current?.abort();
            const controller = new AbortController();
            detailAbortRef.current = controller;

            setDetailLoading(true);
            setDetailError(null);

            try {
                const res = await api<{
                    status: string;
                    data: VendorPrequalificationDetail;
                }>(`/prakualifikasi/${encodeURIComponent(id)}`, {
                    signal: controller.signal,
                });

                if (controller.signal.aborted) {
                    return null;
                }

                return res.data;
            } catch (caught) {
                if (controller.signal.aborted) {
                    return null;
                }

                setDetailError(
                    saveErrorMessage(
                        caught,
                        'Rincian prakualifikasi vendor belum dapat dimuat.',
                    ),
                );
                return null;
            } finally {
                if (!controller.signal.aborted) {
                    setDetailLoading(false);
                }
            }
        },
        [],
    );

    const handleCreate = () => {
        if (!canCreate) {
            return;
        }

        clearMutationFeedback();
        setEditingRecord(null);
        setFormDialogOpen(true);
    };

    const handleView = async (row: VendorPrequalificationSummaryRow) => {
        clearMutationFeedback();
        setActiveDetailId(row.id);
        setDetailRecord(null);
        setDetailDialogOpen(true);

        const loaded = await fetchDetail(row.id);

        if (loaded) {
            setDetailRecord(loaded);
        }
    };

    const handleRetryDetail = async () => {
        if (!activeDetailId) {
            return;
        }

        const loaded = await fetchDetail(activeDetailId);

        if (loaded) {
            setDetailRecord(loaded);
        }
    };

    const handleEditFromRow = async (row: VendorPrequalificationSummaryRow) => {
        if (!canUpdate) {
            return;
        }

        clearMutationFeedback();
        const loaded = await fetchDetail(row.id);

        if (loaded) {
            setEditingRecord(loaded);
            setFormDialogOpen(true);
        } else {
            reportMutationError(
                new Error(
                    'Rincian prakualifikasi vendor belum dapat dibuka untuk diubah.',
                ),
                'Rincian prakualifikasi vendor belum dapat dibuka.',
            );
        }
    };

    const handleEditFromDetail = () => {
        if (!canUpdate || !detailRecord) {
            return;
        }

        setDetailDialogOpen(false);
        setEditingRecord(detailRecord);
        setFormDialogOpen(true);
    };

    const handleArchive = useCallback(
        async (target: {
            id: string;
            nomor_prakualifikasi: string;
            nama_vendor: string;
            version: number;
        }) => {
            if (!canArchive) {
                return;
            }

            clearMutationFeedback();

            const confirmed = await confirmAction({
                title: `Delete prakualifikasi ${target.nomor_prakualifikasi} (${target.nama_vendor})?`,
                description:
                    'Data prakualifikasi yang dihapus akan diarsipkan dan tidak lagi tampil pada daftar aktif.',
                confirmLabel: 'Delete',
                destructive: true,
            });

            if (!confirmed) {
                return;
            }

            try {
                await api(`/prakualifikasi/${encodeURIComponent(target.id)}`, {
                    method: 'DELETE',
                    body: JSON.stringify({ version: target.version }),
                });

                toast.success('Data prakualifikasi vendor berhasil dihapus.');

                if (detailRecord?.id === target.id) {
                    setDetailDialogOpen(false);
                    setDetailRecord(null);
                }

                if (editingRecord?.id === target.id) {
                    setFormDialogOpen(false);
                    setEditingRecord(null);
                }

                load();
            } catch (caught) {
                reportMutationError(
                    caught,
                    'Data prakualifikasi vendor belum dapat dihapus.',
                );
            }
        },
        [
            canArchive,
            clearMutationFeedback,
            confirmAction,
            detailRecord?.id,
            editingRecord?.id,
            load,
            reportMutationError,
        ],
    );

    const columns: DataTableColumn<VendorPrequalificationSummaryRow>[] =
        useMemo(
            () => [
                {
                    id: 'info_prakualifikasi',
                    header: 'Info Prakualifikasi',
                    cell: (row) => (
                        <div className="space-y-0.5">
                            <div className="text-muted-foreground text-xs font-medium">
                                {formatDisplayDate(row.tanggal_prakualifikasi)}
                            </div>
                            <span className="font-mono text-xs font-semibold">
                                {row.nomor_prakualifikasi}
                            </span>
                        </div>
                    ),
                    width: 180,
                },
                {
                    id: 'kode_vendor',
                    header: 'Kode Vendor/Supplier',
                    cell: (row) => (
                        <span className="font-mono text-xs font-semibold">
                            {row.kode_vendor}
                        </span>
                    ),
                    width: 160,
                },
                {
                    id: 'nama_vendor',
                    header: 'Nama Vendor/Supplier',
                    cell: (row) => (
                        <span className="text-xs font-semibold">
                            {row.nama_vendor}
                        </span>
                    ),
                    width: 220,
                },
                {
                    id: 'nama_pic',
                    header: 'Contact Person',
                    cell: (row) => (
                        <span className="text-xs">{row.nama_pic || '—'}</span>
                    ),
                    width: 150,
                },
                {
                    id: 'telepon',
                    header: 'Telp/HP',
                    cell: (row) => (
                        <span className="font-mono text-xs">
                            {row.telepon || '—'}
                        </span>
                    ),
                    width: 150,
                },
                {
                    id: 'alamat',
                    header: 'Alamat Vendor/Supplier',
                    cell: (row) => (
                        <span className="text-muted-foreground line-clamp-2 text-xs">
                            {row.alamat || '—'}
                        </span>
                    ),
                    width: 240,
                },
                {
                    id: 'status_vendor',
                    header: 'Status Vendor/Supplier',
                    cell: (row) => (
                        <Badge variant="secondary" className="text-[11px]">
                            {row.status_vendor || 'Vendor Resmi'}
                        </Badge>
                    ),
                    width: 160,
                },
                {
                    id: 'status_kualifikasi',
                    header: 'Status Kualifikasi',
                    cell: (row) => {
                        const status = row.status_kualifikasi || 'Dalam Proses';

                        if (status === 'Lolos') {
                            return (
                                <Badge className="bg-emerald-600 text-[11px] font-medium text-white hover:bg-emerald-600/90">
                                    <CheckCircle2 className="mr-1 h-3 w-3" />
                                    Lolos
                                </Badge>
                            );
                        }

                        if (status === 'Tidak Lolos') {
                            return (
                                <Badge
                                    variant="destructive"
                                    className="text-[11px]"
                                >
                                    <XCircle className="mr-1 h-3 w-3" />
                                    Tidak Lolos
                                </Badge>
                            );
                        }

                        return (
                            <Badge
                                variant="secondary"
                                className="border-amber-500/30 bg-amber-500/15 text-[11px] font-medium text-amber-700 dark:text-amber-400"
                            >
                                <Clock className="mr-1 h-3 w-3" />
                                {status}
                            </Badge>
                        );
                    },
                    width: 150,
                },
            ],
            [],
        );

    const rowActions: DataTableRowAction[] = useMemo(() => {
        const actions: DataTableRowAction[] = [];

        if (canUpdate) {
            actions.push({ id: 'edit', label: 'Ubah' });
        }

        if (canArchive) {
            actions.push({
                id: 'archive',
                label: 'Delete',
                destructive: true,
                separatorBefore: actions.length > 0,
            });
        }

        return actions;
    }, [canArchive, canUpdate]);

    return (
        <div ref={mutationScopeRef} className="h-full min-h-0 overflow-hidden">
            {actionConfirmation}

            <Card
                aria-labelledby="list-title"
                className="flex h-full min-h-0 flex-col overflow-hidden rounded-none border-0 shadow-none"
            >
                <CardHeader className="min-h-0 shrink-0 border-b">
                    <CardTitle id="list-title">Prakualifikasi Vendor</CardTitle>
                    {mutationFeedback}
                    {canCreate && (
                        <CardAction>
                            <ActionButton
                                action="create"
                                onClick={handleCreate}
                            />
                        </CardAction>
                    )}
                </CardHeader>

                <CardContent className="flex min-h-0 flex-1 flex-col px-0">
                    <div className="flex shrink-0 flex-col gap-3 border-b px-5 py-3 sm:flex-row sm:items-end sm:justify-between">
                        <div className="space-y-1">
                            <p className="text-sm font-semibold">
                                Daftar Prakualifikasi
                            </p>
                            <p className="text-muted-foreground text-xs">
                                {items.length} data dimuat
                            </p>
                        </div>
                        <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-center">
                            <Input
                                className="h-8 w-full text-xs sm:w-64"
                                type="search"
                                placeholder="Cari nomor, kode, atau vendor..."
                                aria-label="Cari prakualifikasi"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                            />
                            <div className="w-full sm:w-48">
                                <Select
                                    items={[
                                        'Semua status',
                                        'Dalam Proses',
                                        'Lolos',
                                        'Tidak Lolos',
                                    ]}
                                    value={
                                        statusFilter === 'semua'
                                            ? 'Semua status'
                                            : statusFilter
                                    }
                                    searchPlaceholder="Cari status"
                                    emptyMessage="Status tidak ditemukan."
                                    ariaLabel="Saring berdasarkan status kualifikasi"
                                    onValueChange={(val) => {
                                        setStatusFilter(
                                            !val || val === 'Semua status'
                                                ? 'semua'
                                                : val,
                                        );
                                    }}
                                />
                            </div>
                        </div>
                    </div>

                    {list.error ? (
                        <Empty>
                            <EmptyHeader>
                                <EmptyTitle>
                                    Data prakualifikasi belum dapat ditampilkan
                                </EmptyTitle>
                                <EmptyDescription>
                                    {list.error}
                                </EmptyDescription>
                            </EmptyHeader>
                            <Button variant="outline" onClick={load}>
                                Coba lagi
                            </Button>
                        </Empty>
                    ) : loading && !items.length ? (
                        <Empty>
                            <EmptyDescription>
                                Memuat data prakualifikasi…
                            </EmptyDescription>
                        </Empty>
                    ) : items.length === 0 ? (
                        <Empty>
                            <EmptyHeader>
                                <EmptyTitle>
                                    Belum ada data prakualifikasi
                                </EmptyTitle>
                                <EmptyDescription>
                                    {canCreate
                                        ? 'Pilih Baru untuk memulai proses prakualifikasi vendor pertama.'
                                        : 'Belum ada catatan prakualifikasi vendor yang tercatat.'}
                                </EmptyDescription>
                            </EmptyHeader>
                        </Empty>
                    ) : (
                        <DataTable
                            scrollMode="viewport"
                            className="min-h-0 flex-1"
                            columns={columns}
                            incremental={list}
                            sort={list.sort}
                            onSortChange={list.onSortChange}
                            data={items}
                            getRowKey={(row) => row.id}
                            getRowLabel={(row) => row.nomor_prakualifikasi}
                            actions={rowActions}
                            onRowAction={(action, row) => {
                                if (action === 'edit') {
                                    void handleEditFromRow(row);
                                }

                                if (action === 'archive') {
                                    void handleArchive(row);
                                }
                            }}
                            onRowClick={(row) => void handleView(row)}
                        />
                    )}
                </CardContent>
            </Card>

            <VendorPrequalificationFormDialog
                open={formDialogOpen}
                onClose={() => setFormDialogOpen(false)}
                initialData={editingRecord}
                legalEntityId={context?.legal_entity_id ?? null}
                canCreateCoreVendor={canCreateCoreVendor}
                onSaved={() => load()}
                onDelete={
                    canArchive && editingRecord
                        ? () => void handleArchive(editingRecord)
                        : undefined
                }
            />

            <VendorPrequalificationDetailDialog
                open={detailDialogOpen}
                onClose={() => {
                    detailAbortRef.current?.abort();
                    setDetailDialogOpen(false);
                }}
                data={detailRecord}
                loading={detailLoading}
                error={detailError}
                onRetry={() => void handleRetryDetail()}
                onEdit={canUpdate ? handleEditFromDetail : undefined}
                onDelete={
                    canArchive && detailRecord
                        ? () => void handleArchive(detailRecord)
                        : undefined
                }
            />
        </div>
    );
}
