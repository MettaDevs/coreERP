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
import { api, saveErrorMessage } from '../api';
import type { ModulePageProps } from '../App';
import { useRecordList } from '../use-record-list';
import { useSaveFeedback } from '../use-save-feedback';
import { PaymentMethodDetailDialog } from './metode-pembayaran/PaymentMethodDetailDialog';
import { PaymentMethodFormDialog } from './metode-pembayaran/PaymentMethodFormDialog';
import type { PaymentMethodRecord } from './metode-pembayaran/PaymentMethodFormDialog';

export default function PaymentMethodPage({
    permissions = [],
}: Partial<ModulePageProps>) {
    const { confirm: confirmAction, confirmation: actionConfirmation } =
        useConfirmation();

    const canCreate = permissions.includes(
        'procurement.metode-pembayaran.create',
    );
    const canUpdate = permissions.includes(
        'procurement.metode-pembayaran.update',
    );
    const canArchive = permissions.includes(
        'procurement.metode-pembayaran.archive',
    );

    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState<
        'semua' | 'aktif' | 'nonaktif'
    >('semua');
    const [pendingStatusId, setPendingStatusId] = useState<string | null>(null);

    const {
        scopeRef: mutationScopeRef,
        feedbackSummary: mutationFeedback,
        reportSaveError: reportMutationError,
        clearSaveFeedback: clearMutationFeedback,
    } = useSaveFeedback(undefined, 'metode-pembayaran');

    const query = useMemo(() => {
        const params = new URLSearchParams();

        if (statusFilter === 'aktif') {
            params.set('aktif', 'true');
        } else if (statusFilter === 'nonaktif') {
            params.set('aktif', 'false');
        }

        return params.toString();
    }, [statusFilter]);

    const list = useRecordList<PaymentMethodRecord>(
        `/metode-pembayaran?${query}`,
        search,
    );
    const { items, loading } = list;

    const [formDialogOpen, setFormDialogOpen] = useState(false);
    const [editingRecord, setEditingRecord] =
        useState<PaymentMethodRecord | null>(null);

    const [detailDialogOpen, setDetailDialogOpen] = useState(false);
    const [detailRecord, setDetailRecord] =
        useState<PaymentMethodRecord | null>(null);
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
        async (
            id: string,
        ): Promise<
            | { status: 'ok'; data: PaymentMethodRecord }
            | { status: 'aborted' }
            | { status: 'error'; message: string }
        > => {
            detailAbortRef.current?.abort();
            const controller = new AbortController();
            detailAbortRef.current = controller;

            setDetailLoading(true);
            setDetailError(null);

            try {
                const res = await api<{
                    data: PaymentMethodRecord;
                }>(`/metode-pembayaran/${encodeURIComponent(id)}`, {
                    signal: controller.signal,
                });

                if (controller.signal.aborted) {
                    return { status: 'aborted' };
                }

                return { status: 'ok', data: res.data };
            } catch (caught) {
                if (controller.signal.aborted) {
                    return { status: 'aborted' };
                }

                const message = saveErrorMessage(
                    caught,
                    'Rincian metode pembayaran belum dapat dimuat.',
                );
                setDetailError(message);

                return { status: 'error', message };
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

    const handleView = async (row: PaymentMethodRecord) => {
        clearMutationFeedback();
        setActiveDetailId(row.id);
        setDetailRecord(null);
        setDetailDialogOpen(true);

        const result = await fetchDetail(row.id);

        if (result.status === 'ok') {
            setDetailRecord(result.data);
        }
    };

    const handleRetryDetail = async () => {
        if (!activeDetailId) {
            return;
        }

        const result = await fetchDetail(activeDetailId);

        if (result.status === 'ok') {
            setDetailRecord(result.data);
        }
    };

    const handleEditFromRow = async (row: PaymentMethodRecord) => {
        if (!canUpdate) {
            return;
        }

        clearMutationFeedback();
        const result = await fetchDetail(row.id);

        if (result.status === 'ok') {
            setEditingRecord(result.data);
            setFormDialogOpen(true);
        } else if (result.status === 'error') {
            reportMutationError(
                new Error(result.message),
                'Rincian metode pembayaran belum dapat dibuka.',
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

    const handleToggleStatus = async (row: PaymentMethodRecord) => {
        if (!canUpdate || pendingStatusId === row.id) {
            return;
        }

        clearMutationFeedback();
        const targetAktif = !row.aktif;
        setPendingStatusId(row.id);

        try {
            await api(
                `/metode-pembayaran/${encodeURIComponent(row.id)}/status`,
                {
                    method: 'PATCH',
                    body: JSON.stringify({
                        aktif: targetAktif,
                        version: row.version,
                    }),
                },
            );

            toast.success(
                targetAktif
                    ? `Metode pembayaran ${row.kode_bayar} berhasil diaktifkan.`
                    : `Metode pembayaran ${row.kode_bayar} berhasil dinonaktifkan.`,
            );

            if (detailRecord?.id === row.id) {
                setDetailRecord((prev) =>
                    prev
                        ? {
                              ...prev,
                              aktif: targetAktif,
                              version: prev.version + 1,
                          }
                        : null,
                );
            }

            load();
        } catch (caught) {
            reportMutationError(
                caught,
                'Status metode pembayaran belum dapat diperbarui.',
            );
        } finally {
            setPendingStatusId(null);
        }
    };

    async function handleArchive(target: {
        id: string;
        kode_bayar: string;
        nama_metode_bayar: string;
        version: number;
    }) {
        if (!canArchive) {
            return;
        }

        clearMutationFeedback();

        const confirmed = await confirmAction({
            title: `Delete metode pembayaran ${target.kode_bayar} (${target.nama_metode_bayar})?`,
            description:
                'Data metode pembayaran yang dihapus akan diarsipkan dan tidak lagi tampil pada daftar aktif.',
            confirmLabel: 'Delete',
            destructive: true,
        });

        if (!confirmed) {
            return;
        }

        try {
            await api(`/metode-pembayaran/${encodeURIComponent(target.id)}`, {
                method: 'DELETE',
                body: JSON.stringify({ version: target.version }),
            });

            toast.success('Data metode pembayaran berhasil dihapus.');

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
                'Data metode pembayaran belum dapat dihapus.',
            );
        }
    }

    const columns: DataTableColumn<PaymentMethodRecord>[] = useMemo(
        () => [
            {
                id: 'kode_bayar',
                header: 'Kode Bayar',
                cell: (row) => (
                    <span className="font-mono text-xs font-semibold">
                        {row.kode_bayar}
                    </span>
                ),
                width: 170,
            },
            {
                id: 'nama_metode_bayar',
                header: 'Nama Metode Pembayaran',
                cell: (row) => (
                    <span className="text-xs font-semibold">
                        {row.nama_metode_bayar}
                    </span>
                ),
                width: 280,
            },
            {
                id: 'status',
                header: 'Status',
                cell: (row) => (
                    <Badge
                        variant={row.aktif ? 'default' : 'secondary'}
                        className="text-[11px]"
                    >
                        {row.aktif ? 'Aktif' : 'Nonaktif'}
                    </Badge>
                ),
                width: 130,
            },
            {
                id: 'keterangan',
                header: 'Keterangan',
                cell: (row) => (
                    <span className="text-muted-foreground line-clamp-1 text-xs">
                        {row.keterangan || '—'}
                    </span>
                ),
                width: 280,
            },
        ],
        [],
    );

    const rowActions: DataTableRowAction[] = useMemo(() => {
        const actions: DataTableRowAction[] = [];

        if (canUpdate) {
            actions.push({ id: 'edit', label: 'Ubah' });
            actions.push({
                id: 'toggle_status',
                label: 'Ubah Status',
            });
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
                    <CardTitle id="list-title">Metode Pembayaran</CardTitle>
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
                                Daftar Metode Pembayaran
                            </p>
                            <p className="text-muted-foreground text-xs">
                                {items.length} data dimuat
                            </p>
                        </div>
                        <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-center">
                            <Input
                                className="h-8 w-full text-xs sm:w-64"
                                type="search"
                                placeholder="Cari kode, nama, atau keterangan..."
                                aria-label="Cari metode pembayaran"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                            />
                            <div className="w-full sm:w-44">
                                <Select
                                    items={[
                                        'Semua status',
                                        'Aktif',
                                        'Nonaktif',
                                    ]}
                                    value={
                                        statusFilter === 'aktif'
                                            ? 'Aktif'
                                            : statusFilter === 'nonaktif'
                                              ? 'Nonaktif'
                                              : 'Semua status'
                                    }
                                    searchPlaceholder="Cari status"
                                    emptyMessage="Status tidak ditemukan."
                                    ariaLabel="Saring berdasarkan status"
                                    onValueChange={(val) => {
                                        if (val === 'Aktif') {
                                            setStatusFilter('aktif');
                                        } else if (val === 'Nonaktif') {
                                            setStatusFilter('nonaktif');
                                        } else {
                                            setStatusFilter('semua');
                                        }
                                    }}
                                />
                            </div>
                        </div>
                    </div>

                    {list.error ? (
                        <Empty>
                            <EmptyHeader>
                                <EmptyTitle>
                                    Data metode pembayaran belum dapat
                                    ditampilkan
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
                                Memuat data metode pembayaran…
                            </EmptyDescription>
                        </Empty>
                    ) : items.length === 0 ? (
                        <Empty>
                            <EmptyHeader>
                                <EmptyTitle>
                                    Belum ada data metode pembayaran
                                </EmptyTitle>
                                <EmptyDescription>
                                    {canCreate
                                        ? 'Pilih Baru untuk menambahkan metode pembayaran pertama.'
                                        : 'Belum ada metode pembayaran yang tercatat.'}
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
                            getRowLabel={(row) =>
                                `${row.kode_bayar} — ${row.nama_metode_bayar}`
                            }
                            actions={rowActions}
                            onRowAction={(action, row) => {
                                if (action === 'edit') {
                                    void handleEditFromRow(row);
                                }

                                if (action === 'toggle_status') {
                                    void handleToggleStatus(row);
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

            {formDialogOpen && (
                <PaymentMethodFormDialog
                    key={editingRecord?.id ?? 'new'}
                    open={formDialogOpen}
                    onClose={() => setFormDialogOpen(false)}
                    initialData={editingRecord}
                    onSaved={() => load()}
                    onDelete={
                        canArchive && editingRecord
                            ? () => void handleArchive(editingRecord)
                            : undefined
                    }
                />
            )}

            <PaymentMethodDetailDialog
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
