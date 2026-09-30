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
import type { Monitoring } from './monitoring';
import {
    STATUS,
    StatusBadge,
    bukaMonitoring,
    bukaMonitoringBaru,
    bukaMonitoringUbah,
    izin,
    tanggalTampil,
} from './monitoring';

/** Nilai filter status; `''` berarti semua. */
const PILIHAN_STATUS = [
    { value: '', label: 'Semua status' },
    { value: 'draft', label: 'Draf' },
    { value: 'selesai', label: 'Selesai' },
];

/**
 * Daftar pemeriksaan fisik aset. Ia hanya menampilkan dan memilih; mengisi baris dan
 * menyelesaikan pemeriksaan adalah urusan halaman rincian yang punya alamat sendiri.
 */
export default function MonitoringListPage({
    permissions,
}: {
    permissions: string[];
}) {
    const can = izin(permissions);
    const [daftar, setDaftar] = useState<Monitoring[]>([]);
    const [status, setStatus] = useState('');
    const [dari, setDari] = useState('');
    const [sampai, setSampai] = useState('');
    const [search, setSearch] = useState('');
    const [memuat, setMemuat] = useState(true);

    useEffect(() => {
        let dibatalkan = false;
        const query = new URLSearchParams(
            Object.entries({ status, dari, sampai }).filter(([, value]) =>
                Boolean(value),
            ),
        ).toString();

        api<{ data: Monitoring[] }>(
            `/monitoring-aset${query ? `?${query}` : ''}`,
        )
            .then((result) => {
                if (!dibatalkan) {
                    setDaftar(result.data);
                }
            })
            .catch((caught) => {
                if (!dibatalkan) {
                    toast.error(
                        errorMessage(caught, 'Monitoring belum dapat dimuat.'),
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
    }, [status, dari, sampai]);

    const query = search.trim().toLowerCase();
    const terlihat =
        query === ''
            ? daftar
            : daftar.filter((baris) =>
                  [
                      baris.kode,
                      baris.keterangan,
                      baris.lokasi_aset_nama,
                      baris.responsible_org_unit_nama,
                      baris.penanggung_jawab_nama,
                      STATUS[baris.status]?.label,
                  ].some((value) =>
                      String(value ?? '')
                          .toLowerCase()
                          .includes(query),
                  ),
              );

    const columns: DataTableColumn<Monitoring>[] = [
        {
            id: 'kode',
            header: 'No. bukti',
            cell: (baris) => (
                <span className="text-primary font-medium">{baris.kode}</span>
            ),
            sortValue: (baris) => baris.kode,
            width: 160,
        },
        {
            id: 'tanggal',
            header: 'Tanggal monitoring',
            cell: (baris) => tanggalTampil(baris.tanggal),
            sortValue: (baris) => baris.tanggal ?? '',
            width: 160,
        },
        {
            id: 'lokasi',
            header: 'Lokasi aset',
            cell: (baris) => baris.lokasi_aset_nama ?? '—',
            sortValue: (baris) => baris.lokasi_aset_nama ?? '',
            minWidth: 160,
            width: 200,
        },
        {
            id: 'unit',
            header: 'Unit organisasi',
            cell: (baris) => baris.responsible_org_unit_nama ?? 'Semua unit',
            sortValue: (baris) => baris.responsible_org_unit_nama ?? '',
            minWidth: 150,
            width: 180,
        },
        {
            id: 'pic',
            header: 'Penanggung jawab',
            cell: (baris) => baris.penanggung_jawab_nama ?? '—',
            sortValue: (baris) => baris.penanggung_jawab_nama ?? '',
            minWidth: 150,
            width: 180,
        },
        {
            id: 'jumlah',
            header: 'Aset diperiksa',
            cell: (baris) => {
                const belum = baris.jumlah_belum_diperiksa ?? 0;

                return belum > 0
                    ? `${baris.jumlah_baris ?? 0} (${belum} belum)`
                    : (baris.jumlah_baris ?? 0);
            },
            sortValue: (baris) => baris.jumlah_baris ?? 0,
            align: 'center',
            width: 140,
        },
        {
            id: 'tidak_sesuai',
            header: 'Tidak sesuai',
            cell: (baris) => baris.jumlah_tidak_sesuai ?? 0,
            sortValue: (baris) => baris.jumlah_tidak_sesuai ?? 0,
            align: 'center',
            width: 130,
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
            <RecordActionBar title="Monitoring aset">
                {can('create') && (
                    <ActionButton
                        action="create"
                        type="button"
                        onClick={bukaMonitoringBaru}
                    >
                        Buat monitoring
                    </ActionButton>
                )}
            </RecordActionBar>

            <div className="flex flex-col gap-3 border-b px-5 py-3 sm:flex-row sm:flex-wrap sm:items-end">
                <div className="w-full sm:w-72">
                    <Input
                        label="Cari"
                        type="search"
                        placeholder="Nomor bukti, lokasi, atau orang"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                    />
                </div>
                <div className="w-full sm:w-44">
                    <Input
                        label="Dari tanggal"
                        type="date"
                        value={dari}
                        onChange={(event) => setDari(event.target.value)}
                    />
                </div>
                <div className="w-full sm:w-44">
                    <Input
                        label="Sampai tanggal"
                        type="date"
                        value={sampai}
                        onChange={(event) => setSampai(event.target.value)}
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
                            <EmptyTitle>Belum ada monitoring aset</EmptyTitle>
                            <EmptyDescription>
                                Monitoring mencatat hasil pemeriksaan fisik aset
                                di satu lokasi: mana yang ada, mana yang tidak,
                                dan mana yang tidak cocok dengan catatan aset.
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
                        onRowClick={(baris) => bukaMonitoring(baris.id)}
                        onRowAction={(action, baris) => {
                            if (action === 'detail') {
                                bukaMonitoring(baris.id);
                            }

                            if (action === 'edit') {
                                if (baris.status !== 'draft') {
                                    toast.error(
                                        'Monitoring yang sudah selesai tidak dapat diubah. Buat monitoring baru bila temuannya perlu dikoreksi.',
                                    );

                                    return;
                                }

                                bukaMonitoringUbah(baris.id);
                            }
                        }}
                    />
                )}
            </div>
        </div>
    );
}
