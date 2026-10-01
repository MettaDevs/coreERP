import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { useDateTimeFormat } from '@/hooks/use-date-time';
import { ActionButton } from '@apperp/ui/action-button';
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
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { Select } from '@apperp/ui/select';
import { api, errorMessage } from '../../api';
import type { MaintenanceRequest } from './maintenanceRequest';
import {
    STATUS,
    StatusBadge,
    codeName,
    openNewRequest,
    openRequest,
    openRequestEdit,
    permissionCheck,
} from './maintenanceRequest';

const STATUS_FILTER = [
    { value: '', label: 'Semua status' },
    ...Object.entries(STATUS).map(([value, { label }]) => ({ value, label })),
];

/**
 * Daftar permintaan pemeliharaan. Hanya menampilkan dan memilih; mengajukan, memutuskan, dan
 * membuat work order dilakukan di halaman rincian.
 */
export default function MaintenanceRequestListPage({
    permissions,
}: {
    permissions: string[];
}) {
    const can = permissionCheck(permissions);
    const formatDateTime = useDateTimeFormat();
    const [rows, setRows] = useState<MaintenanceRequest[]>([]);
    const [status, setStatus] = useState('');
    const [search, setSearch] = useState('');
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        let cancelled = false;

        api<{ data: MaintenanceRequest[] }>(
            `/permintaan-pemeliharaan${status ? `?status=${status}` : ''}`,
        )
            .then((result) => {
                if (!cancelled) {
                    setRows(result.data);
                }
            })
            .catch((caught) => {
                if (!cancelled) {
                    toast.error(
                        errorMessage(
                            caught,
                            'Permintaan pemeliharaan belum dapat dimuat.',
                        ),
                    );
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setLoading(false);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [status]);

    const query = search.trim().toLowerCase();
    const visible =
        query === ''
            ? rows
            : rows.filter((row) =>
                  [
                      row.kode,
                      row.deskripsi,
                      row.aset_kode,
                      row.aset_nama,
                      row.lokasi_nama,
                      row.jenis_permintaan_nama,
                      row.work_order_kode,
                  ].some((value) =>
                      String(value ?? '')
                          .toLowerCase()
                          .includes(query),
                  ),
              );

    const columns: DataTableColumn<MaintenanceRequest>[] = [
        {
            id: 'kode',
            header: 'No. permintaan',
            cell: (row) => (
                <span className="text-primary font-medium">{row.kode}</span>
            ),
            sortValue: (row) => row.kode,
            width: 160,
        },
        {
            id: 'dibuat',
            header: 'Dilaporkan',
            cell: (row) => formatDateTime(row.created_at),
            sortValue: (row) => row.created_at,
            width: 170,
        },
        {
            id: 'jenis',
            header: 'Jenis permintaan',
            cell: (row) => row.jenis_permintaan_nama ?? '—',
            sortValue: (row) => row.jenis_permintaan_nama ?? '',
            width: 180,
        },
        {
            id: 'objek',
            header: 'Aset atau lokasi',
            cell: (row) =>
                row.aset_id
                    ? codeName(row.aset_kode, row.aset_nama)
                    : (row.lokasi_nama ?? '—'),
            sortValue: (row) => row.aset_kode ?? row.lokasi_nama ?? '',
            minWidth: 180,
            width: 240,
        },
        {
            id: 'deskripsi',
            header: 'Deskripsi',
            cell: (row) => (
                <span className="line-clamp-1">{row.deskripsi}</span>
            ),
            minWidth: 200,
            width: 300,
        },
        {
            id: 'layanan',
            header: 'Tingkat layanan',
            cell: (row) => row.tingkat_layanan_nama ?? '—',
            sortValue: (row) => row.tingkat_layanan_nama ?? '',
            width: 150,
        },
        {
            id: 'status',
            header: 'Status',
            cell: (row) => <StatusBadge status={row.status} />,
            sortValue: (row) => STATUS[row.status]?.label ?? row.status,
            width: 160,
        },
        {
            id: 'wo',
            header: 'Work order',
            cell: (row) => row.work_order_kode ?? '—',
            sortValue: (row) => row.work_order_kode ?? '',
            width: 150,
        },
    ];

    const actions: DataTableRowAction[] = [
        { id: 'detail', label: 'Buka rincian' },
    ];

    if (can('update')) {
        actions.push({ id: 'edit', label: 'Ubah' });
    }

    return (
        <div className="flex h-full min-h-0 flex-col overflow-hidden">
            <RecordActionBar title="Permintaan pemeliharaan">
                {can('create') && (
                    <ActionButton
                        action="create"
                        type="button"
                        onClick={openNewRequest}
                    >
                        Buat permintaan
                    </ActionButton>
                )}
            </RecordActionBar>

            <div className="flex flex-col gap-3 border-b px-5 py-3 sm:flex-row sm:flex-wrap sm:items-end">
                <div className="w-full sm:w-72">
                    <Input
                        label="Cari"
                        type="search"
                        placeholder="Nomor, aset, lokasi, atau deskripsi"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                    />
                </div>
                <div className="w-full sm:w-52">
                    <Select
                        label="Status"
                        items={STATUS_FILTER}
                        value={status}
                        placeholder="Semua status"
                        ariaLabel="Saring berdasarkan status"
                        onValueChange={(value) => {
                            setLoading(true);
                            setStatus(value ?? '');
                        }}
                    />
                </div>
            </div>

            <div className="min-h-0 flex-1 overflow-auto">
                {!loading && !visible.length ? (
                    <Empty>
                        <EmptyHeader>
                            <EmptyTitle>
                                Belum ada permintaan pemeliharaan
                            </EmptyTitle>
                            <EmptyDescription>
                                Unit melaporkan kerusakan atau kebutuhan
                                perbaikan aset di sini. Perencana meninjaunya
                                lalu membuatkan work order.
                            </EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <DataTable
                        columns={columns}
                        data={visible}
                        getRowKey={(row) => row.id}
                        getRowLabel={(row) => row.kode}
                        actions={actions}
                        onRowClick={(row) => openRequest(row.id)}
                        onRowAction={(action, row) => {
                            if (action === 'detail') {
                                openRequest(row.id);
                            }

                            if (action === 'edit') {
                                if (row.status !== 'draft') {
                                    toast.error(
                                        'Hanya permintaan draf yang dapat diubah.',
                                    );

                                    return;
                                }

                                openRequestEdit(row.id);
                            }
                        }}
                    />
                )}
            </div>
        </div>
    );
}
