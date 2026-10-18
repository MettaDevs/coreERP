import { FileOutput } from 'lucide-react';
import { useRef, useState } from 'react';
import { ActionButton } from '@apperp/ui/action-button';
import { Button } from '@apperp/ui/button';
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
import { ListPageLayout } from '@apperp/ui/list-page-layout';
import { PageDialog, PageDialogContent } from '@apperp/ui/page-dialog';
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { Switch } from '@apperp/ui/switch';
import { useRecordNavigation } from '@apperp/ui/use-record-navigation';
import { requestPrint } from '../../print';
import { useRecordList } from '../../use-record-list';
import type { RecordCardState } from '../_shared/record-card';
import type { Context } from './workOrder';
import type { WorkOrder } from './workOrder';
import { STATUS, StatusBadge, bukaChecklistJob, izin } from './workOrder';
import WorkOrderDetailPage from './WorkOrderDetailPage';

/**
 * Daftar work order. Ia hanya menampilkan dan memilih; menyusun, menjadwalkan, dan
 * mengisi hasil pekerjaan adalah urusan halaman rincian yang punya alamat sendiri.
 */
export default function WorkOrderListPage({
    context,
    permissions,
}: {
    context: Context;
    permissions: string[];
}) {
    const [card, setCard] = useState<RecordCardState>(null);
    const [cardBusy, setCardBusy] = useState(false);
    const [cardDirty, setCardDirty] = useState(false);
    const discardChanges = () =>
        !cardDirty || window.confirm('Buang perubahan yang belum disimpan?');
    const openNewCard = () => {
        if (!cardBusy && discardChanges()) {
            setCardDirty(false);
            setCard({ mode: 'create' });
        }
    };
    const closeCard = () => {
        if (!cardBusy && discardChanges()) {
            setCard(null);
            setCardDirty(false);
            workOrderList.reload();
        }
    };
    const [cardRevision, setCardRevision] = useState(0);
    const cardContentRef = useRef<HTMLDivElement>(null);
    const bukaWorkOrderBaru = () => setCard({ mode: 'create' });
    const bukaWorkOrder = (id: string) => setCard({ mode: 'view', id });
    const bukaWorkOrderUbah = (id: string) => setCard({ mode: 'edit', id });

    const can = izin(permissions);
    const [hanyaPekerjaanSaya, setHanyaPekerjaanSaya] = useState(false);
    const [search, setSearch] = useState('');
    const workOrderList = useRecordList<WorkOrder>(
        '/pemeliharaan-aset',
        search,
        {},
        !hanyaPekerjaanSaya,
    );
    const jobList = useRecordList<Record<string, unknown> & { id: string }>(
        '/pemeliharaan-aset/saya',
        search,
        {},
        hanyaPekerjaanSaya,
    );
    const visibleWorkOrders = workOrderList.items;
    const pekerjaanSaya = jobList.items;

    const workOrderColumns: DataTableColumn<WorkOrder>[] = [
        {
            id: 'kode',
            header: 'Work order',
            cell: (workOrder) => (
                <span className="text-primary font-medium">
                    {workOrder.kode}
                </span>
            ),
            sortValue: (workOrder) => workOrder.kode,
            width: 150,
        },
        {
            id: 'jenis',
            header: 'Jenis pekerjaan',
            cell: (workOrder) => workOrder.tipe_work_order_nama ?? '—',
            sortValue: (workOrder) => workOrder.tipe_work_order_nama ?? '',
            width: 170,
        },
        {
            id: 'keterangan',
            header: 'Keterangan',
            cell: (workOrder) => (
                <span className="text-muted-foreground">
                    {workOrder.keterangan ?? 'Tanpa keterangan'}
                </span>
            ),
            sortValue: (workOrder) => workOrder.keterangan ?? '',
            minWidth: 240,
            width: 300,
        },
        {
            id: 'layanan',
            header: 'Tingkat layanan',
            cell: (workOrder) => workOrder.tingkat_layanan_nama ?? '—',
            sortValue: (workOrder) => workOrder.tingkat_layanan_nama ?? '',
            width: 150,
        },
        {
            id: 'status',
            header: 'Status',
            cell: (workOrder) => <StatusBadge status={workOrder.status} />,
            sortValue: (workOrder) =>
                STATUS[workOrder.status]?.label ?? workOrder.status,
            width: 140,
        },
        {
            id: 'jadwal',
            header: 'Mulai terjadwal',
            cell: (workOrder) => workOrder.dijadwalkan_mulai ?? '—',
            sortValue: (workOrder) => workOrder.dijadwalkan_mulai ?? '',
            width: 180,
        },
        {
            id: 'baris',
            header: 'Baris pekerjaan',
            cell: (workOrder) => workOrder.jumlah_baris ?? 0,
            sortValue: (workOrder) => workOrder.jumlah_baris ?? 0,
            align: 'center',
            width: 130,
        },
    ];

    const workOrderActions: DataTableRowAction[] = [
        { id: 'detail', label: 'Buka rincian' },
    ];

    if (can('update')) {
        workOrderActions.push({ id: 'edit', label: 'Ubah' });
    }

    const pekerjaanSayaColumns: DataTableColumn<Record<string, unknown>>[] = [
        {
            id: 'work-order',
            header: 'Work order',
            cell: (job) => (
                <span className="text-primary font-medium">
                    {String(job.work_order_kode ?? '—')}
                </span>
            ),
            sortValue: (job) => String(job.work_order_kode ?? ''),
            width: 150,
        },
        {
            id: 'aset',
            header: 'Aset',
            cell: (job) => String(job.aset_kode ?? '—'),
            sortValue: (job) => String(job.aset_kode ?? ''),
            width: 150,
        },
        {
            id: 'pekerjaan',
            header: 'Jenis pekerjaan',
            cell: (job) => String(job.job_type_nama ?? '—'),
            sortValue: (job) => String(job.job_type_nama ?? ''),
            width: 190,
        },
        {
            id: 'lokasi',
            header: 'Lokasi',
            cell: (job) => String(job.lokasi_nama ?? '—'),
            sortValue: (job) => String(job.lokasi_nama ?? ''),
            width: 180,
        },
        {
            id: 'jadwal',
            header: 'Mulai terjadwal',
            cell: (job) => String(job.dijadwalkan_mulai ?? '—'),
            sortValue: (job) => String(job.dijadwalkan_mulai ?? ''),
            width: 180,
        },
        {
            id: 'status',
            header: 'Status',
            cell: (job) => <StatusBadge status={String(job.status ?? '')} />,
            sortValue: (job) => String(job.status ?? ''),
            width: 140,
        },
    ];

    const activeNavigationList = hanyaPekerjaanSaya ? jobList : workOrderList;
    const navigationRows = hanyaPekerjaanSaya
        ? Array.from(
              new Set(
                  pekerjaanSaya.flatMap((job) =>
                      typeof job.pemeliharaan_aset_id === 'string'
                          ? [job.pemeliharaan_aset_id]
                          : [],
                  ),
              ),
              (id) => ({ id }),
          )
        : visibleWorkOrders.map(({ id }) => ({ id }));
    const currentCardId = card && 'id' in card ? card.id : null;
    const cardNavigation = useRecordNavigation({
        items: navigationRows,
        currentId: currentCardId,
        getId: (item) => item.id,
        onNavigate: (id) => setCard({ mode: 'view', id }),
        scopeKey: JSON.stringify([
            hanyaPekerjaanSaya,
            search,
            activeNavigationList.sort,
        ]),
        hasMore: activeNavigationList.hasMore,
        loading: activeNavigationList.loading,
        loadMore: activeNavigationList.loadMore,
        disabled: cardBusy || cardDirty,
    });

    return (
        <>
            <ListPageLayout
                header={
                    <RecordActionBar title="Work order">
                        {can('create') && !hanyaPekerjaanSaya && (
                            <ActionButton
                                action="create"
                                type="button"
                                onClick={bukaWorkOrderBaru}
                            />
                        )}
                        {!hanyaPekerjaanSaya && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() =>
                                    requestPrint({
                                        report: 'daftar-work-order',
                                        title: 'Ekspor daftar work order',
                                        parameters: {},
                                    })
                                }
                            >
                                <FileOutput />
                                Ekspor daftar
                            </Button>
                        )}
                        {can('execute') ? (
                            <label className="flex items-center gap-2 text-sm">
                                <Switch
                                    checked={hanyaPekerjaanSaya}
                                    onCheckedChange={setHanyaPekerjaanSaya}
                                />
                                Pekerjaan saya
                            </label>
                        ) : undefined}
                    </RecordActionBar>
                }
            >
                {hanyaPekerjaanSaya ? (
                    !pekerjaanSaya.length &&
                    !jobList.loading &&
                    !jobList.error ? (
                        <Empty>
                            <EmptyHeader>
                                <EmptyTitle>
                                    Tidak ada pekerjaan untuk Anda
                                </EmptyTitle>
                                <EmptyDescription>
                                    Pekerjaan muncul di sini setelah dijadwalkan
                                    dan ditugaskan kepada Anda.
                                </EmptyDescription>
                            </EmptyHeader>
                        </Empty>
                    ) : (
                        <DataTable
                            columns={pekerjaanSayaColumns}
                            incremental={jobList}
                            sort={jobList.sort}
                            onSortChange={jobList.onSortChange}
                            data={pekerjaanSaya}
                            getRowKey={(job) => String(job.id)}
                            getRowLabel={(job) =>
                                String(job.work_order_kode ?? 'pekerjaan')
                            }
                            actions={[
                                { id: 'detail', label: 'Buka rincian' },
                                { id: 'checklist', label: 'Isi checklist' },
                            ]}
                            onRowClick={(job) => {
                                if (can('read')) {
                                    bukaWorkOrder(
                                        String(job.pemeliharaan_aset_id),
                                    );
                                }
                            }}
                            onRowAction={(action, job) => {
                                if (action === 'detail' && can('read')) {
                                    bukaWorkOrder(
                                        String(job.pemeliharaan_aset_id),
                                    );
                                }

                                if (action === 'checklist') {
                                    bukaChecklistJob(
                                        String(job.pemeliharaan_aset_id),
                                        String(job.id),
                                    );
                                }
                            }}
                        />
                    )
                ) : (
                    <div className="flex h-full min-h-0 flex-col">
                        <div className="flex shrink-0 flex-col gap-3 border-b px-5 py-3 sm:flex-row sm:items-end sm:justify-between">
                            <div className="space-y-1">
                                <p className="font-semibold">
                                    Daftar work order
                                </p>
                                <p className="text-muted-foreground text-sm">
                                    {visibleWorkOrders.length} work order
                                    ditampilkan
                                </p>
                            </div>
                            <Input
                                className="w-full sm:w-80"
                                type="search"
                                placeholder="Cari kode, jenis, atau keterangan"
                                aria-label="Cari work order"
                                value={search}
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
                            />
                        </div>
                        <DataTable
                            className="min-h-0 flex-1"
                            columns={workOrderColumns}
                            incremental={workOrderList}
                            sort={workOrderList.sort}
                            onSortChange={workOrderList.onSortChange}
                            data={visibleWorkOrders}
                            getRowKey={(workOrder) => workOrder.id}
                            getRowLabel={(workOrder) => workOrder.kode}
                            actions={workOrderActions}
                            onRowClick={(workOrder) => {
                                if (can('read')) {
                                    bukaWorkOrder(workOrder.id);
                                }
                            }}
                            onRowAction={(action, workOrder) => {
                                if (action === 'detail' && can('read')) {
                                    bukaWorkOrder(workOrder.id);
                                }

                                if (action === 'edit' && can('update')) {
                                    bukaWorkOrderUbah(workOrder.id);
                                }
                            }}
                            emptyMessage="Tidak ada work order yang cocok dengan pencarian."
                        />
                    </div>
                )}
            </ListPageLayout>
            {card && (
                <PageDialog
                    open
                    onOpenChange={(open) => {
                        if (!open) {
                            closeCard();
                        }
                    }}
                >
                    <PageDialogContent
                        ref={cardContentRef}
                        onEscapeKeyDown={(event) => {
                            if (cardBusy) {
                                event.preventDefault();
                            }
                        }}
                        title={'Work order'}
                        busy={cardBusy}
                        onNew={
                            !(card?.mode === 'create') && can('create')
                                ? openNewCard
                                : undefined
                        }
                        navigation={currentCardId ? cardNavigation : undefined}
                    >
                        <WorkOrderDetailPage
                            key={`${card.mode}-${'id' in card ? card.id : 'new'}-${cardRevision}`}
                            context={context}
                            permissions={permissions}
                            workOrderId={'id' in card ? card.id : undefined}
                            mode={card.mode}
                            portalContainer={cardContentRef}
                            onBusyChange={setCardBusy}
                            onClose={closeCard}
                            onSaved={(id) => {
                                setCard({ mode: 'view', id });
                                setCardRevision((version) => version + 1);
                                workOrderList.reload();
                                jobList.reload();
                            }}
                            onRequestView={(id) => {
                                if (!cardBusy && discardChanges()) {
                                    setCard({ mode: 'view', id });
                                }
                            }}
                            onRequestEdit={(id) => {
                                if (!cardBusy && discardChanges()) {
                                    setCard({ mode: 'edit', id });
                                }
                            }}
                            onDirtyChange={setCardDirty}
                        />
                    </PageDialogContent>
                </PageDialog>
            )}
        </>
    );
}
