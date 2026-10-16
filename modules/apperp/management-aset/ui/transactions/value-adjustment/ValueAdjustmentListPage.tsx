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
import { ListPageLayout } from '@apperp/ui/list-page-layout';
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { Select } from '@apperp/ui/select';
import { useState } from 'react';
import { toast } from 'sonner';
import { useRecordList } from '../../use-record-list';
import type { Adjustment } from './valueAdjustment';
import {
    KINDS,
    STATUS,
    StatusBadge,
    bukaPenyesuaian,
    bukaPenyesuaianBaru,
    bukaPenyesuaianUbah,
    izin,
    kindLabel,
    tanggalTampil,
    uang,
} from './valueAdjustment';

const PILIHAN_STATUS = [
    { value: '', label: 'Semua status' },
    { value: 'draft', label: 'Draf' },
    { value: 'posted', label: 'Diposting' },
];

const PILIHAN_JENIS = [{ value: '', label: 'Semua jenis' }, ...KINDS];

/** Daftar penyesuaian nilai aset. Pratinjau dan posting ada di halaman rincian. */
export default function ValueAdjustmentListPage({
    permissions,
}: {
    permissions: string[];
}) {
    const can = izin(permissions);
    const [status, setStatus] = useState('');
    const [jenis, setJenis] = useState('');
    const [search, setSearch] = useState('');

    const list = useRecordList<Adjustment>(
        '/penyesuaian-nilai-aset',
        search,
        Object.fromEntries(
            Object.entries({ status, jenis }).filter(
                ([, value]) => value !== '',
            ),
        ),
    );
    const daftar = list.items;
    const terlihat = daftar;
    const memuat = list.loading;

    const columns: DataTableColumn<Adjustment>[] = [
        {
            id: 'kode',
            header: 'No. bukti',
            cell: (baris) => (
                <span className="text-primary font-medium">{baris.kode}</span>
            ),
            sortValue: (baris) => baris.kode,
            width: 170,
        },
        {
            id: 'tanggal',
            header: 'Tanggal',
            cell: (baris) => tanggalTampil(baris.tanggal),
            sortValue: (baris) => baris.tanggal ?? '',
            width: 130,
        },
        {
            id: 'jenis',
            header: 'Jenis',
            cell: (baris) => kindLabel(baris.jenis),
            sortValue: (baris) => baris.jenis,
            width: 220,
        },
        {
            id: 'buku',
            header: 'Buku penyusutan',
            cell: (baris) => baris.buku_nama ?? baris.buku_kode ?? '—',
            sortValue: (baris) => baris.buku_nama ?? '',
            minWidth: 160,
        },
        {
            id: 'jumlah',
            header: 'Aset',
            cell: (baris) => baris.jumlah_baris ?? 0,
            sortValue: (baris) => baris.jumlah_baris ?? 0,
            align: 'center',
            width: 90,
        },
        {
            id: 'total',
            header: 'Total nilai',
            cell: (baris) => uang(baris.total_nilai),
            sortValue: (baris) => Number(baris.total_nilai ?? 0),
            align: 'right',
            width: 150,
        },
        {
            id: 'status',
            header: 'Status',
            cell: (baris) => <StatusBadge status={baris.status} />,
            sortValue: (baris) => STATUS[baris.status]?.label ?? baris.status,
            width: 120,
        },
    ];

    const actions: DataTableRowAction[] = [
        { id: 'detail', label: 'Buka rincian' },
    ];

    if (can('update')) {
        actions.push({ id: 'edit', label: 'Ubah' });
    }

    return (
        <ListPageLayout
            header={
                <RecordActionBar title="Penyesuaian nilai aset">
                    {can('create') && (
                        <ActionButton
                            action="create"
                            type="button"
                            onClick={bukaPenyesuaianBaru}
                        >
                            Buat penyesuaian
                        </ActionButton>
                    )}
                </RecordActionBar>
            }
            filters={
                <div className="flex shrink-0 flex-col gap-3 border-b px-5 py-3 sm:flex-row sm:flex-wrap sm:items-end">
                    <div className="w-full sm:w-72">
                        <Input
                            label="Cari"
                            type="search"
                            placeholder="Nomor bukti, alasan, atau buku"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                        />
                    </div>
                    <div className="w-full sm:w-56">
                        <Select
                            label="Jenis"
                            items={PILIHAN_JENIS.map(
                                (pilihan) => pilihan.label,
                            )}
                            value={
                                PILIHAN_JENIS.find(
                                    (pilihan) => pilihan.value === jenis,
                                )?.label ?? null
                            }
                            ariaLabel="Saring berdasarkan jenis"
                            onValueChange={(value) =>
                                setJenis(
                                    PILIHAN_JENIS.find(
                                        (pilihan) => pilihan.label === value,
                                    )?.value ?? '',
                                )
                            }
                        />
                    </div>
                    <div className="w-full sm:w-48">
                        <Select
                            label="Status"
                            items={PILIHAN_STATUS.map(
                                (pilihan) => pilihan.label,
                            )}
                            value={
                                PILIHAN_STATUS.find(
                                    (pilihan) => pilihan.value === status,
                                )?.label ?? null
                            }
                            ariaLabel="Saring berdasarkan status"
                            onValueChange={(value) =>
                                setStatus(
                                    PILIHAN_STATUS.find(
                                        (pilihan) => pilihan.label === value,
                                    )?.value ?? '',
                                )
                            }
                        />
                    </div>
                </div>
            }
        >
            {!memuat && !terlihat.length ? (
                <Empty>
                    <EmptyHeader>
                        <EmptyTitle>Belum ada penyesuaian nilai</EmptyTitle>
                        <EmptyDescription>
                            Penyesuaian nilai menurunkan nilai buku aset yang
                            nilainya turun, atau menaikkannya saat revaluasi.
                            Penyusutan berikutnya dihitung dari nilai buku yang
                            baru.
                        </EmptyDescription>
                    </EmptyHeader>
                </Empty>
            ) : (
                <DataTable
                    columns={columns}
                    incremental={list}
                    sort={list.sort}
                    onSortChange={list.onSortChange}
                    data={terlihat}
                    getRowKey={(baris) => baris.id}
                    getRowLabel={(baris) => baris.kode}
                    actions={actions}
                    onRowClick={(baris) => bukaPenyesuaian(baris.id)}
                    onRowAction={(action, baris) => {
                        if (action === 'detail') {
                            bukaPenyesuaian(baris.id);
                        }

                        if (action === 'edit') {
                            if (baris.status !== 'draft') {
                                toast.error(
                                    'Penyesuaian yang sudah diposting tidak dapat diubah. Buat penyesuaian baru bila nilainya perlu dikoreksi.',
                                );

                                return;
                            }

                            bukaPenyesuaianUbah(baris.id);
                        }
                    }}
                />
            )}
        </ListPageLayout>
    );
}
