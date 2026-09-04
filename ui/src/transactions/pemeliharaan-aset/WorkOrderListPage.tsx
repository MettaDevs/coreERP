import { useEffect, useState } from 'react';
import { ActionButton } from '@apperp/ui/action-button';
import { DataTable, type DataTableColumn, type DataTableRowAction } from '@apperp/ui/data-table';
import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@apperp/ui/empty';
import { Input } from '@apperp/ui/input';
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { Switch } from '@apperp/ui/switch';
import { toast } from 'sonner';
import { api, errorMessage } from '../../api';
import {
    STATUS,
    StatusBadge,
    WorkOrder,
    bukaChecklistJob,
    bukaWorkOrder,
    bukaWorkOrderBaru,
    bukaWorkOrderUbah,
    izin,
} from './workOrder';

/**
 * Daftar work order. Ia hanya menampilkan dan memilih; menyusun, menjadwalkan, dan
 * mengisi hasil pekerjaan adalah urusan halaman rincian yang punya alamat sendiri.
 */
export default function WorkOrderListPage({ permissions }: { permissions: string[] }) {
    const can = izin(permissions);
    const [workOrders, setWorkOrders] = useState<WorkOrder[]>([]);
    const [hanyaPekerjaanSaya, setHanyaPekerjaanSaya] = useState(false);
    const [pekerjaanSaya, setPekerjaanSaya] = useState<Record<string, unknown>[]>([]);
    const [search, setSearch] = useState('');

    useEffect(() => {
        api<{ data: WorkOrder[] }>('/pemeliharaan-aset')
            .then((result) => setWorkOrders(result.data))
            .catch((caught) => toast.error(errorMessage(caught, 'Work order belum dapat dimuat.')));
    }, []);

    useEffect(() => {
        if (!hanyaPekerjaanSaya) return;
        api<{ data: Record<string, unknown>[] }>('/pemeliharaan-aset/saya')
            .then((result) => setPekerjaanSaya(result.data))
            .catch((caught) =>
                toast.error(errorMessage(caught, 'Daftar pekerjaan Anda belum dapat dimuat.')),
            );
    }, [hanyaPekerjaanSaya]);

    const query = search.trim().toLowerCase();
    const visibleWorkOrders =
        query === ''
            ? workOrders
            : workOrders.filter((workOrder) =>
                  [
                      workOrder.kode,
                      workOrder.tipe_work_order_nama,
                      workOrder.keterangan,
                      workOrder.tingkat_layanan_nama,
                      STATUS[workOrder.status]?.label,
                  ].some((value) =>
                      String(value ?? '')
                          .toLowerCase()
                          .includes(query),
                  ),
              );

    const workOrderColumns: DataTableColumn<WorkOrder>[] = [
        {
            id: 'kode',
            header: 'Work order',
            cell: (workOrder) => <span className="font-medium text-primary">{workOrder.kode}</span>,
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
            sortValue: (workOrder) => STATUS[workOrder.status]?.label ?? workOrder.status,
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

    const workOrderActions: DataTableRowAction[] = [{ id: 'detail', label: 'Buka rincian' }];
    if (can('update')) workOrderActions.push({ id: 'edit', label: 'Ubah' });

    const pekerjaanSayaColumns: DataTableColumn<Record<string, unknown>>[] = [
        {
            id: 'work-order',
            header: 'Work order',
            cell: (job) => (
                <span className="font-medium text-primary">
                    {String(job.work_order_kode ?? '—')}
                </span>
            ),
            sortValue: (job) => String(job.work_order_kode ?? ''),
            width: 150,
        },
        {
            id: 'aset',
            header: 'Aset',
            cell: (job) => String(job.asset_kode ?? '—'),
            sortValue: (job) => String(job.asset_kode ?? ''),
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

    return (
        <div className="flex h-full min-h-0 flex-col overflow-hidden">
            <RecordActionBar
                title="Work order"
                trailing={
                    can('execute') ? (
                        <label className="flex items-center gap-2 text-sm">
                            <Switch
                                checked={hanyaPekerjaanSaya}
                                onCheckedChange={setHanyaPekerjaanSaya}
                            />
                            Pekerjaan saya
                        </label>
                    ) : undefined
                }
            >
                {can('create') && !hanyaPekerjaanSaya && (
                    <ActionButton action="create" type="button" onClick={bukaWorkOrderBaru}>
                        Tambah work order
                    </ActionButton>
                )}
            </RecordActionBar>

            <div className="min-h-0 flex-1 overflow-auto">
                {hanyaPekerjaanSaya ? (
                    !pekerjaanSaya.length ? (
                        <Empty>
                            <EmptyHeader>
                                <EmptyTitle>Tidak ada pekerjaan untuk Anda</EmptyTitle>
                                <EmptyDescription>
                                    Pekerjaan muncul di sini setelah dijadwalkan dan ditugaskan
                                    kepada Anda.
                                </EmptyDescription>
                            </EmptyHeader>
                        </Empty>
                    ) : (
                        <DataTable
                            columns={pekerjaanSayaColumns}
                            data={pekerjaanSaya}
                            getRowKey={(job) => String(job.id)}
                            getRowLabel={(job) => String(job.work_order_kode ?? 'pekerjaan')}
                            actions={[
                                { id: 'detail', label: 'Buka rincian' },
                                { id: 'checklist', label: 'Isi checklist' },
                            ]}
                            onRowClick={(job) => {
                                if (can('read')) bukaWorkOrder(String(job.pemeliharaan_aset_id));
                            }}
                            onRowAction={(action, job) => {
                                if (action === 'detail' && can('read'))
                                    bukaWorkOrder(String(job.pemeliharaan_aset_id));
                                if (action === 'checklist')
                                    bukaChecklistJob(
                                        String(job.pemeliharaan_aset_id),
                                        String(job.id),
                                    );
                            }}
                        />
                    )
                ) : !workOrders.length ? (
                    <Empty>
                        <EmptyHeader>
                            <EmptyTitle>Belum ada work order</EmptyTitle>
                            <EmptyDescription>
                                Work order memuat baris pekerjaan per aset, sehingga satu perintah
                                kerja dapat mencakup beberapa aset.
                            </EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <div>
                        <div className="flex flex-col gap-3 border-b px-5 py-3 sm:flex-row sm:items-end sm:justify-between">
                            <div className="space-y-1">
                                <p className="font-semibold">Daftar work order</p>
                                <p className="text-sm text-muted-foreground">
                                    {visibleWorkOrders.length} work order ditampilkan
                                </p>
                            </div>
                            <Input
                                className="w-full sm:w-80"
                                type="search"
                                placeholder="Cari kode, jenis, atau keterangan"
                                aria-label="Cari work order"
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                            />
                        </div>
                        <DataTable
                            columns={workOrderColumns}
                            data={visibleWorkOrders}
                            getRowKey={(workOrder) => workOrder.id}
                            getRowLabel={(workOrder) => workOrder.kode}
                            actions={workOrderActions}
                            onRowClick={(workOrder) => {
                                if (can('read')) bukaWorkOrder(workOrder.id);
                            }}
                            onRowAction={(action, workOrder) => {
                                if (action === 'detail' && can('read')) bukaWorkOrder(workOrder.id);
                                if (action === 'edit' && can('update'))
                                    bukaWorkOrderUbah(workOrder.id);
                            }}
                            emptyMessage="Tidak ada work order yang cocok dengan pencarian."
                        />
                    </div>
                )}
            </div>
        </div>
    );
}
