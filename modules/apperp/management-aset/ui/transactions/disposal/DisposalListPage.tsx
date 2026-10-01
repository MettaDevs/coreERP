import { useEffect, useState } from 'react';
import { toast } from 'sonner';
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
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { api, errorMessage } from '../../api';
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
    const [daftar, setDaftar] = useState<Disposal[]>([]);
    const [search, setSearch] = useState('');
    const [memuat, setMemuat] = useState(true);

    useEffect(() => {
        let dibatalkan = false;
        api<{ data: Disposal[] }>(`/${resource}`)
            .then((result) => {
                if (!dibatalkan) {
                    setDaftar(result.data);
                }
            })
            .catch((caught) => {
                if (!dibatalkan) {
                    toast.error(
                        errorMessage(caught, 'Dokumen belum dapat dimuat.'),
                    );
                }
            })
            .finally(() => {
                if (!dibatalkan) {
                    setMemuat(false);
                }
            });

        return () => {
            dibatalkan = true;
        };
    }, [resource]);

    const query = search.trim().toLowerCase();
    const terlihat =
        query === ''
            ? daftar
            : daftar.filter((baris) =>
                  [
                      baris.kode,
                      baris.aset_kode,
                      baris.aset_nama,
                      baris.keterangan,
                      STATUS[baris.status]?.label,
                  ].some((value) =>
                      String(value ?? '')
                          .toLowerCase()
                          .includes(query),
                  ),
              );

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
        <div className="flex h-full min-h-0 flex-col overflow-hidden">
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

            <div className="min-h-0 flex-1 overflow-auto">
                {!memuat && !terlihat.length ? (
                    <Empty>
                        <EmptyHeader>
                            <EmptyTitle>
                                Belum ada dokumen {jenis.noun}
                            </EmptyTitle>
                            <EmptyDescription>{jenis.empty}</EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <DataTable
                        columns={columns}
                        data={terlihat}
                        getRowKey={(baris) => baris.id}
                        getRowLabel={(baris) => baris.kode}
                        actions={[{ id: 'detail', label: 'Buka rincian' }]}
                        onRowClick={(baris) => bukaDokumen(resource, baris.id)}
                        onRowAction={(_, baris) =>
                            bukaDokumen(resource, baris.id)
                        }
                    />
                )}
            </div>
        </div>
    );
}
