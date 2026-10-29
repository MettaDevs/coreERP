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
import { useConfirmation } from '@apperp/ui/use-confirmation';
import { api, saveErrorMessage } from '../api';
import type { ModulePageProps } from '../App';
import { useRecordList } from '../use-record-list';
import { useSaveFeedback } from '../use-save-feedback';
import { OwnerEstimateDetailDialog } from './harga-perkiraan/OwnerEstimateDetailDialog';
import {
    OwnerEstimateFormDialog,
    formatIdrCurrency,
} from './harga-perkiraan/OwnerEstimateFormDialog';
import type { OwnerEstimateRecord } from './harga-perkiraan/OwnerEstimateFormDialog';

function formatDisplayDate(dateStr: string | null | undefined): string {
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

export default function OwnerEstimatePage({
    permissions = [],
}: Partial<ModulePageProps>) {
    const { confirm: confirmAction, confirmation: actionConfirmation } =
        useConfirmation();

    const canCreate = permissions.includes(
        'procurement.harga-perkiraan.create',
    );
    const canUpdate = permissions.includes(
        'procurement.harga-perkiraan.update',
    );
    const canArchive = permissions.includes(
        'procurement.harga-perkiraan.archive',
    );

    const [search, setSearch] = useState('');

    const {
        scopeRef: mutationScopeRef,
        feedbackSummary: mutationFeedback,
        reportSaveError: reportMutationError,
        clearSaveFeedback: clearMutationFeedback,
    } = useSaveFeedback(undefined, 'harga-perkiraan');

    const list = useRecordList<OwnerEstimateRecord>('/harga-perkiraan', search);
    const { items, loading } = list;

    const [formDialogOpen, setFormDialogOpen] = useState(false);
    const [editingRecord, setEditingRecord] =
        useState<OwnerEstimateRecord | null>(null);

    const [detailDialogOpen, setDetailDialogOpen] = useState(false);
    const [detailRecord, setDetailRecord] =
        useState<OwnerEstimateRecord | null>(null);
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
            | { status: 'ok'; data: OwnerEstimateRecord }
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
                    data: OwnerEstimateRecord;
                }>(`/harga-perkiraan/${encodeURIComponent(id)}`, {
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
                    'Rincian harga perkiraan belum dapat dimuat.',
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

    const handleView = async (row: OwnerEstimateRecord) => {
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

    const handleEditFromRow = async (row: OwnerEstimateRecord) => {
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
                'Rincian harga perkiraan belum dapat dibuka.',
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

    async function handleArchive(target: {
        id: string;
        kode_item: string;
        nama_item: string;
        version: number;
    }) {
        if (!canArchive) {
            return;
        }

        clearMutationFeedback();

        const confirmed = await confirmAction({
            title: `Delete harga perkiraan ${target.kode_item} (${target.nama_item})?`,
            description:
                'Data harga perkiraan yang dihapus akan diarsipkan dan tidak lagi tampil pada daftar aktif.',
            confirmLabel: 'Delete',
            destructive: true,
        });

        if (!confirmed) {
            return;
        }

        try {
            await api(`/harga-perkiraan/${encodeURIComponent(target.id)}`, {
                method: 'DELETE',
                body: JSON.stringify({ version: target.version }),
            });

            toast.success('Data harga perkiraan berhasil dihapus.');

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
                'Data harga perkiraan belum dapat dihapus.',
            );
        }
    }

    const columns: DataTableColumn<OwnerEstimateRecord>[] = useMemo(
        () => [
            {
                id: 'group_item',
                header: 'Group Item',
                cell: (row) => (
                    <Badge variant="secondary" className="text-[11px]">
                        {row.group_item}
                    </Badge>
                ),
                width: 140,
            },
            {
                id: 'jenis_item',
                header: 'Jenis Item',
                cell: (row) => (
                    <span className="text-xs font-medium">
                        {row.jenis_item}
                    </span>
                ),
                width: 170,
            },
            {
                id: 'kode_item',
                header: 'Kode Item',
                cell: (row) => (
                    <span className="font-mono text-xs font-semibold">
                        {row.kode_item}
                    </span>
                ),
                width: 150,
            },
            {
                id: 'nama_item',
                header: 'Nama Item',
                cell: (row) => (
                    <span className="text-xs font-semibold">
                        {row.nama_item}
                    </span>
                ),
                width: 240,
            },
            {
                id: 'satuan',
                header: 'Satuan',
                cell: (row) => (
                    <span className="text-muted-foreground text-xs">
                        {row.satuan}
                    </span>
                ),
                width: 100,
            },
            {
                id: 'harga_perkiraan',
                header: 'Harga Perkiraan',
                align: 'right',
                cell: (row) => (
                    <span className="font-mono text-xs font-semibold">
                        {formatIdrCurrency(row.harga_perkiraan)}
                    </span>
                ),
                width: 180,
            },
            {
                id: 'tanggal_update',
                header: 'Tgl. Update',
                cell: (row) => (
                    <span className="text-muted-foreground font-mono text-xs">
                        {formatDisplayDate(row.tanggal_update)}
                    </span>
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
                width: 220,
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
                    <CardTitle id="list-title">
                        Harga Perkiraan Sendiri (HPS)
                    </CardTitle>
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
                                Daftar Harga Perkiraan
                            </p>
                            <p className="text-muted-foreground text-xs">
                                {items.length} data dimuat
                            </p>
                        </div>
                        <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-center">
                            <Input
                                className="h-8 w-full text-xs sm:w-64"
                                type="search"
                                placeholder="Cari group, jenis, kode, atau nama item..."
                                aria-label="Cari harga perkiraan"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                            />
                        </div>
                    </div>

                    {list.error ? (
                        <Empty>
                            <EmptyHeader>
                                <EmptyTitle>
                                    Data harga perkiraan belum dapat ditampilkan
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
                                Memuat data harga perkiraan…
                            </EmptyDescription>
                        </Empty>
                    ) : items.length === 0 ? (
                        <Empty>
                            <EmptyHeader>
                                <EmptyTitle>
                                    Belum ada data harga perkiraan
                                </EmptyTitle>
                                <EmptyDescription>
                                    {canCreate
                                        ? 'Pilih Baru untuk menambahkan item harga perkiraan pertama.'
                                        : 'Belum ada item harga perkiraan yang tercatat.'}
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
                                `${row.kode_item} — ${row.nama_item}`
                            }
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

            {formDialogOpen && (
                <OwnerEstimateFormDialog
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

            <OwnerEstimateDetailDialog
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
