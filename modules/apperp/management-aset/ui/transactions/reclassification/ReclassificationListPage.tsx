import { useEffect, useState } from 'react';
import { toast } from 'sonner';
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
import type { Reclassification } from './reclassification';
import {
    KINDS,
    STATUS,
    StatusBadge,
    bukaReklasifikasi,
    bukaReklasifikasiBaru,
    bukaReklasifikasiUbah,
    izin,
    kindLabel,
    tanggalTampil,
} from './reclassification';

/** Nilai penanda "tanpa saringan"; bukan string kosong supaya label pilihan tidak bertumpuk. */
const SEMUA = '__all__';

const PILIHAN_STATUS = [
    { value: SEMUA, label: 'Semua status' },
    { value: 'draft', label: 'Draf' },
    { value: 'posted', label: 'Diposting' },
];

const PILIHAN_JENIS = [{ value: SEMUA, label: 'Semua jenis' }, ...KINDS];

/** Daftar reklasifikasi aset. Pratinjau dan posting ada di halaman rincian. */
export default function ReclassificationListPage({
    permissions,
}: {
    permissions: string[];
}) {
    const can = izin(permissions);
    const [daftar, setDaftar] = useState<Reclassification[]>([]);
    const [status, setStatus] = useState(SEMUA);
    const [jenis, setJenis] = useState(SEMUA);
    const [search, setSearch] = useState('');
    const [memuat, setMemuat] = useState(true);

    useEffect(() => {
        let dibatalkan = false;
        const query = new URLSearchParams(
            Object.entries({ status, jenis }).filter(
                ([, value]) => value !== SEMUA,
            ),
        ).toString();

        api<{ data: Reclassification[] }>(
            `/reklasifikasi-aset${query ? `?${query}` : ''}`,
        )
            .then((result) => {
                if (!dibatalkan) {
                    setDaftar(result.data);
                }
            })
            .catch((caught) => {
                if (!dibatalkan) {
                    toast.error(
                        errorMessage(
                            caught,
                            'Reklasifikasi aset belum dapat dimuat.',
                        ),
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
    }, [status, jenis]);

    const query = search.trim().toLowerCase();
    const terlihat =
        query === ''
            ? daftar
            : daftar.filter((baris) =>
                  [baris.kode, baris.keterangan].some((value) =>
                      String(value ?? '')
                          .toLowerCase()
                          .includes(query),
                  ),
              );

    const columns: DataTableColumn<Reclassification>[] = [
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
            width: 180,
        },
        {
            id: 'keterangan',
            header: 'Alasan',
            cell: (baris) => baris.keterangan || '—',
            minWidth: 200,
        },
        {
            id: 'jumlah',
            header: 'Baris',
            cell: (baris) => baris.jumlah_baris ?? 0,
            sortValue: (baris) => baris.jumlah_baris ?? 0,
            align: 'center',
            width: 90,
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
        <div className="flex h-full min-h-0 flex-col overflow-hidden">
            <RecordActionBar title="Reklasifikasi aset">
                {can('create') && (
                    <ActionButton
                        action="create"
                        type="button"
                        onClick={bukaReklasifikasiBaru}
                    >
                        Buat reklasifikasi
                    </ActionButton>
                )}
            </RecordActionBar>

            <div className="flex flex-col gap-3 border-b px-5 py-3 sm:flex-row sm:flex-wrap sm:items-end">
                <div className="w-full sm:w-72">
                    <Input
                        label="Cari"
                        type="search"
                        placeholder="Nomor bukti atau alasan"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                    />
                </div>
                <div className="w-full sm:w-56">
                    <Select
                        label="Jenis"
                        items={PILIHAN_JENIS.map((pilihan) => pilihan.label)}
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
                                )?.value ?? SEMUA,
                            )
                        }
                    />
                </div>
                <div className="w-full sm:w-48">
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
                                )?.value ?? SEMUA,
                            )
                        }
                    />
                </div>
            </div>

            <div className="min-h-0 flex-1 overflow-auto">
                {!memuat && !terlihat.length ? (
                    <Empty>
                        <EmptyHeader>
                            <EmptyTitle>Belum ada reklasifikasi</EmptyTitle>
                            <EmptyDescription>
                                Reklasifikasi memindah aset ke group lain, atau
                                memecah sebagian nilainya menjadi aset baru,
                                beserta harga perolehan, akumulasi penyusutan,
                                dan penyesuaian nilainya.
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
                        onRowClick={(baris) => bukaReklasifikasi(baris.id)}
                        onRowAction={(action, baris) => {
                            if (action === 'detail') {
                                bukaReklasifikasi(baris.id);
                            }

                            if (action === 'edit') {
                                if (baris.status !== 'draft') {
                                    toast.error(
                                        'Reklasifikasi yang sudah diposting tidak dapat diubah. Buat reklasifikasi baru bila perlu dikoreksi.',
                                    );

                                    return;
                                }

                                bukaReklasifikasiUbah(baris.id);
                            }
                        }}
                    />
                )}
            </div>
        </div>
    );
}
