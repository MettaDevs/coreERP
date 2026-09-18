import { useEffect, useState } from 'react';
import { toast } from 'sonner';
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
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { Select } from '@apperp/ui/select';
import { api, errorMessage } from '../../api';
import { bukaDaftar } from './aset';
import type { Penerimaan } from './penerimaan';
import {
    STATUS,
    StatusBadge,
    bukaPenerimaan,
    bukaPenerimaanBaru,
    bukaPenerimaanUbah,
    izin,
    tanggalTampil,
} from './penerimaan';

/** Nilai filter status; `''` berarti semua. */
const PILIHAN_STATUS = [
    { value: '', label: 'Semua status' },
    { value: 'draft', label: 'Draf' },
    { value: 'selesai', label: 'Selesai' },
];

/**
 * Daftar dokumen penerimaan aset.
 *
 * Kolom "Jumlah aset" bukan hiasan: ia yang membedakan dokumen ini dari berkas lain —
 * satu baris dokumen bisa berarti dua puluh baris register, dan angka itulah yang
 * dicocokkan dengan surat jalan.
 */
export default function PenerimaanListPage({
    permissions,
}: {
    permissions: string[];
}) {
    const can = izin(permissions);
    const [penerimaan, setPenerimaan] = useState<Penerimaan[]>([]);
    const [status, setStatus] = useState('');
    const [search, setSearch] = useState('');
    const [memuat, setMemuat] = useState(true);

    useEffect(() => {
        let dibatalkan = false;
        api<{ data: Penerimaan[] }>(
            `/penerimaan-aset${status ? `?status=${status}` : ''}`,
        )
            .then((result) => {
                if (!dibatalkan) {
                    setPenerimaan(result.data);
                }
            })
            .catch((caught) => {
                if (!dibatalkan) {
                    toast.error(
                        errorMessage(caught, 'Penerimaan belum dapat dimuat.'),
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
    }, [status]);

    const query = search.trim().toLowerCase();
    const terlihat =
        query === ''
            ? penerimaan
            : penerimaan.filter((baris) =>
                  [
                      baris.kode,
                      baris.keterangan,
                      baris.lokasi_aset_nama,
                      baris.responsible_org_unit_nama,
                      STATUS[baris.status]?.label,
                  ].some((value) =>
                      String(value ?? '')
                          .toLowerCase()
                          .includes(query),
                  ),
              );

    const columns: DataTableColumn<Penerimaan>[] = [
        {
            id: 'kode',
            header: 'No. penerimaan',
            cell: (baris) => (
                <span className="text-primary font-medium">{baris.kode}</span>
            ),
            sortValue: (baris) => baris.kode,
            width: 170,
        },
        {
            id: 'tanggal',
            header: 'Tanggal terima',
            cell: (baris) => tanggalTampil(baris.tanggal),
            sortValue: (baris) => baris.tanggal ?? '',
            width: 150,
        },
        {
            id: 'siap',
            header: 'Siap dipakai',
            cell: (baris) => tanggalTampil(baris.tanggal_siap_pakai),
            sortValue: (baris) => baris.tanggal_siap_pakai ?? '',
            width: 150,
        },
        {
            id: 'unit',
            header: 'Unit pengguna',
            // Nama, bukan ULID: daftar yang menampilkan id tidak dapat dibaca sekilas
            // maupun diurutkan dengan cara yang berarti.
            cell: (baris) =>
                baris.responsible_org_unit_nama ?? 'Unit tidak dikenal',
            sortValue: (baris) => baris.responsible_org_unit_nama ?? '',
            minWidth: 150,
            width: 200,
        },
        {
            id: 'lokasi',
            header: 'Lokasi awal',
            cell: (baris) => baris.lokasi_aset_nama ?? 'Tanpa lokasi',
            sortValue: (baris) => baris.lokasi_aset_nama ?? '',
            minWidth: 150,
            width: 190,
        },
        {
            id: 'baris',
            header: 'Baris',
            cell: (baris) => baris.jumlah_baris ?? 0,
            sortValue: (baris) => baris.jumlah_baris ?? 0,
            align: 'center',
            width: 100,
        },
        {
            id: 'jumlah',
            header: 'Jumlah aset',
            cell: (baris) => baris.jumlah_aset ?? 0,
            sortValue: (baris) => baris.jumlah_aset ?? 0,
            align: 'center',
            width: 130,
        },
        {
            id: 'status',
            header: 'Status',
            cell: (baris) => <StatusBadge status={baris.status} />,
            sortValue: (baris) => STATUS[baris.status]?.label ?? baris.status,
            width: 130,
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
            <RecordActionBar title="Penerimaan aset">
                {can('create') && (
                    <ActionButton
                        action="create"
                        type="button"
                        onClick={bukaPenerimaanBaru}
                    >
                        Catat penerimaan
                    </ActionButton>
                )}
                <Button type="button" variant="outline" onClick={bukaDaftar}>
                    Register aset
                </Button>
            </RecordActionBar>

            <div className="flex flex-col gap-3 border-b px-5 py-3 sm:flex-row sm:items-end">
                <div className="w-full sm:w-72">
                    <Input
                        label="Cari"
                        type="search"
                        placeholder="Nomor penerimaan, lokasi, atau unit"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                    />
                </div>
                <div className="w-full sm:w-52">
                    <Select
                        label="Status"
                        items={PILIHAN_STATUS.map((pilihan) => pilihan.label)}
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

            <div className="min-h-0 flex-1 overflow-auto">
                {!memuat && !terlihat.length ? (
                    <Empty>
                        <EmptyHeader>
                            <EmptyTitle>Belum ada penerimaan aset</EmptyTitle>
                            <EmptyDescription>
                                Satu dokumen penerimaan mencatat satu kedatangan
                                barang, dan melahirkan satu aset bernomor untuk
                                tiap unit yang datang.
                            </EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <DataTable
                        columns={columns}
                        data={terlihat}
                        getRowKey={(baris) => baris.id}
                        getRowLabel={(baris) => baris.kode}
                        actions={actions}
                        onRowClick={(baris) => bukaPenerimaan(baris.id)}
                        onRowAction={(action, baris) => {
                            if (action === 'detail') {
                                bukaPenerimaan(baris.id);
                            }

                            if (action === 'edit') {
                                // Dokumen selesai ditolak server; mengatakannya di sini
                                // membuat orangnya tahu sebelum mengetik ulang isinya.
                                if (baris.status !== 'draft') {
                                    toast.error(
                                        'Penerimaan yang sudah selesai tidak dapat diubah; asetnya sudah terdaftar dan bernomor.',
                                    );

                                    return;
                                }

                                bukaPenerimaanUbah(baris.id);
                            }
                        }}
                    />
                )}
            </div>
        </div>
    );
}
