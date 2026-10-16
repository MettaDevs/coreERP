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
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { Select } from '@apperp/ui/select';
import { FileOutput } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { requestPrint } from '../../print';
import { useRecordList } from '../../use-record-list';
import type { Mutasi } from './mutasi';
import {
    STATUS,
    StatusBadge,
    bukaMutasi,
    bukaMutasiBaru,
    bukaMutasiUbah,
    izin,
    tanggalTampil,
} from './mutasi';

/** Nilai filter status; `''` berarti semua. */
const PILIHAN_STATUS = [
    { value: '', label: 'Semua status' },
    { value: 'draft', label: 'Draf' },
    { value: 'selesai', label: 'Selesai' },
];

/**
 * Daftar berita acara serah terima aset.
 *
 * Ia hanya menampilkan dan memilih; menyusun barisnya dan menyelesaikan serah terimanya
 * adalah urusan halaman rincian yang punya alamat sendiri.
 */
export default function MutasiListPage({
    permissions,
}: {
    permissions: string[];
}) {
    const can = izin(permissions);
    const [status, setStatus] = useState('');
    const [search, setSearch] = useState('');

    const list = useRecordList<Mutasi>(
        '/mutasi-aset',
        search,
        Object.fromEntries(
            Object.entries({ status }).filter(([, value]) => value !== ''),
        ),
    );
    const mutasi = list.items;
    const terlihat = mutasi;
    const memuat = list.loading;

    const columns: DataTableColumn<Mutasi>[] = [
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
            header: 'Tanggal mutasi',
            cell: (baris) => tanggalTampil(baris.tanggal),
            sortValue: (baris) => baris.tanggal ?? '',
            width: 150,
        },
        {
            id: 'tujuan',
            header: 'Lokasi tujuan',
            cell: (baris) => baris.tujuan_lokasi_nama ?? 'Tanpa lokasi',
            sortValue: (baris) => baris.tujuan_lokasi_nama ?? '',
            minWidth: 160,
            width: 200,
        },
        {
            id: 'unit',
            header: 'Unit tujuan',
            // Nama, bukan ULID: daftar yang menampilkan id tidak dapat dibaca sekilas
            // maupun diurutkan dengan cara yang berarti.
            cell: (baris) => baris.tujuan_org_unit_nama ?? 'Unit tidak dikenal',
            sortValue: (baris) => baris.tujuan_org_unit_nama ?? '',
            minWidth: 150,
            width: 190,
        },
        {
            id: 'alasan',
            header: 'Alasan',
            cell: (baris) => (
                <span className="text-muted-foreground">{baris.alasan}</span>
            ),
            sortValue: (baris) => baris.alasan,
            minWidth: 220,
            width: 300,
        },
        {
            id: 'jumlah',
            header: 'Jumlah aset',
            cell: (baris) => baris.jumlah_baris ?? 0,
            sortValue: (baris) => baris.jumlah_baris ?? 0,
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

    actions.push({ id: 'cetak', label: 'Cetak berita acara' });

    return (
        <ListPageLayout
            header={
                <RecordActionBar title="Mutasi aset">
                    {can('create') && (
                        <ActionButton
                            action="create"
                            type="button"
                            onClick={bukaMutasiBaru}
                        >
                            Catat mutasi
                        </ActionButton>
                    )}
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() =>
                            requestPrint({
                                report: 'daftar-mutasi-aset',
                                title: 'Ekspor daftar mutasi aset',
                                parameters: status ? { status } : {},
                            })
                        }
                    >
                        <FileOutput />
                        Ekspor daftar
                    </Button>
                </RecordActionBar>
            }
            filters={
                <div className="flex shrink-0 flex-col gap-3 border-b px-5 py-3 sm:flex-row sm:items-end">
                    <div className="w-full sm:w-72">
                        <Input
                            label="Cari"
                            type="search"
                            placeholder="Nomor bukti, alasan, atau lokasi"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                        />
                    </div>
                    <div className="w-full sm:w-52">
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
                        <EmptyTitle>Belum ada mutasi aset</EmptyTitle>
                        <EmptyDescription>
                            Mutasi mencatat perpindahan aset beserta berita
                            acara serah terimanya. Buat satu untuk memulai.
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
                    onRowClick={(baris) => bukaMutasi(baris.id)}
                    onRowAction={(action, baris) => {
                        if (action === 'detail') {
                            bukaMutasi(baris.id);
                        }

                        if (action === 'edit') {
                            bukaMutasiUbah(baris.id);
                        }

                        if (action === 'cetak') {
                            // Berita acara adalah bukti bahwa sesuatu sudah
                            // terjadi; server menolak mencetak draf, dan
                            // layar mengatakannya lebih dahulu agar orang
                            // tidak menunggu dialog cetak untuk kabar itu.
                            if (baris.status !== 'selesai') {
                                toast.error(
                                    'Berita acara hanya dapat dicetak setelah mutasi diselesaikan.',
                                );

                                return;
                            }

                            requestPrint({
                                report: 'berita-acara-serah-terima',
                                title: `Cetak berita acara ${baris.kode}`,
                                parameters: { id: baris.id },
                            });
                        }
                    }}
                />
            )}
        </ListPageLayout>
    );
}
