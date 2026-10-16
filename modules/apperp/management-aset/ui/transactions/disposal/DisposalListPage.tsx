import { useState } from 'react';

import { ActionButton } from '@apperp/ui/action-button';
import { DataTable } from '@apperp/ui/data-table';
import type { DataTableColumn } from '@apperp/ui/data-table';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@apperp/ui/empty';
import { Input } from '@apperp/ui/input';
import { ListPageLayout } from '@apperp/ui/list-page-layout';
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { useRecordList } from '../../use-record-list';
import type { Disposal, DisposalResource } from './disposal';
import {
    DISPOSALS,
    STATUS,
    StatusBadge,
    bukaBaru,
    bukaDokumen,
    izin,
    tanggalTampil,
    uang,
} from './disposal';

/**
 * Daftar penjualan atau pemusnahan aset. Ia hanya menampilkan dan memilih; pratinjau dan posting ada di
 * halaman rincian.
 */
export default function DisposalListPage({
    resource,
    permissions,
}: {
    resource: DisposalResource;
    permissions: string[];
}) {
    const jenis = DISPOSALS[resource];
    const can = izin(resource, permissions);
    const [search, setSearch] = useState('');

    const list = useRecordList<Disposal>(`/${resource}`, search);
    const daftar = list.items;
    const terlihat = daftar;
    const memuat = list.loading;

    const columns: DataTableColumn<Disposal>[] = [
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
            id: 'aset',
            header: 'Aset',
            cell: (baris) =>
                [baris.aset_kode, baris.aset_nama]
                    .filter(Boolean)
                    .join(' · ') || '—',
            sortValue: (baris) => baris.aset_kode ?? '',
            minWidth: 200,
        },
        ...(jenis.hasProceeds
            ? [
                  {
                      id: 'nilai',
                      header: 'Nilai penjualan',
                      cell: (baris: Disposal) => uang(baris.nilai),
                      sortValue: (baris: Disposal) => Number(baris.nilai ?? 0),
                      align: 'right' as const,
                      width: 160,
                  },
              ]
            : []),
        {
            id: 'status',
            header: 'Status',
            cell: (baris) => <StatusBadge status={baris.status} />,
            sortValue: (baris) => STATUS[baris.status]?.label ?? baris.status,
            width: 130,
        },
    ];

    return (
        <ListPageLayout
            header={
                <RecordActionBar title={jenis.title}>
                    {can('create') && (
                        <ActionButton
                            action="create"
                            type="button"
                            onClick={() => bukaBaru(resource)}
                        >
                            {jenis.create}
                        </ActionButton>
                    )}
                </RecordActionBar>
            }
            filters={
                <div className="border-b px-5 py-3">
                    <div className="w-full sm:w-72">
                        <Input
                            label="Cari"
                            type="search"
                            placeholder="Nomor bukti, aset, atau keterangan"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                        />
                    </div>
                </div>
            }
        >
            {!memuat && !terlihat.length ? (
                <Empty>
                    <EmptyHeader>
                        <EmptyTitle>Belum ada dokumen {jenis.noun}</EmptyTitle>
                        <EmptyDescription>{jenis.empty}</EmptyDescription>
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
                    actions={[{ id: 'detail', label: 'Buka rincian' }]}
                    onRowClick={(baris) => bukaDokumen(resource, baris.id)}
                    onRowAction={(_, baris) => bukaDokumen(resource, baris.id)}
                />
            )}
        </ListPageLayout>
    );
}
