import { useEffect, useState } from 'react';
import { ActionButton } from '@apperp/ui/action-button';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@apperp/ui/alert-dialog';
import { Badge } from '@apperp/ui/badge';
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
import { api, errorMessage } from '../../api';
import type { MasterRecord, ParentSummary } from '../masters';
import ModelAsetFormSheet from './ModelAsetFormSheet';
import type { PabrikanModelRecord } from './pabrikanAsetDetail';

type ListMeta = { current_page: number; last_page: number; total: number };

export default function PabrikanModels({
    manufacturer,
    canReadModels,
    canReadJenis,
    canCreate,
    canUpdate,
    canArchive,
    canReadAssets,
    onChanged,
}: {
    manufacturer: MasterRecord;
    canReadModels: boolean;
    canReadJenis: boolean;
    canCreate: boolean;
    canUpdate: boolean;
    canArchive: boolean;
    canReadAssets: boolean;
    onChanged: () => void;
}) {
    const [items, setItems] = useState<PabrikanModelRecord[]>([]);
    const [meta, setMeta] = useState<ListMeta>({
        current_page: 1,
        last_page: 1,
        total: 0,
    });
    const [page, setPage] = useState(1);
    const [error, setError] = useState('');
    // Effect adalah satu-satunya pemilik pengambilan data; pemuatan ulang setelah
    // simpan atau arsip dinyatakan dengan menaikkan penanda ini.
    const [versiMuat, setVersiMuat] = useState(0);
    const muatUlang = () => setVersiMuat((versi) => versi + 1);
    const [editing, setEditing] = useState<
        PabrikanModelRecord | null | undefined
    >(undefined);
    const [archiving, setArchiving] = useState<PabrikanModelRecord | null>(
        null,
    );

    // Isi tabel selalu berpasangan dengan parameter yang menghasilkannya. Selama
    // pasangan itu belum sama dengan yang sedang diminta, layar masih memuat. Nilai ini
    // dihitung saat render, jadi pindah halaman langsung menampilkan keadaan memuat
    // tanpa effect yang perlu menyetel penanda lebih dulu. Nomor halaman sendiri tidak
    // perlu disetel ulang saat ganti pabrikan: RecordDetailPane memasang `key`, jadi
    // panel ini dipasang ulang dengan halaman pertama.
    const kunciMuat = `${manufacturer.id}|${page}|${versiMuat}`;
    const [kunciTermuat, setKunciTermuat] = useState<string | null>(null);
    const loading = canReadModels && kunciTermuat !== kunciMuat;

    useEffect(() => {
        if (!canReadModels) {
            return;
        }

        let dilepas = false;
        const params = new URLSearchParams({
            pabrikan_aset_id: manufacturer.id,
            per_page: '20',
            page: String(page),
        });

        // Pengambilan data lahir di dalam effect: state baru disetel setelah jawaban
        // server tiba, bukan pada commit render yang sama, dan jawaban yang telat
        // datang setelah panel dilepas dibuang lewat `dilepas`.
        const muat = async () => {
            try {
                const result = await api<{
                    data: PabrikanModelRecord[];
                    meta: ListMeta;
                }>(`/model-aset?${params}`);

                if (dilepas) {
                    return;
                }

                setItems(result.data);
                setMeta(result.meta);
                setError('');
            } catch (caught) {
                if (dilepas) {
                    return;
                }

                setError(errorMessage(caught, 'Model belum dapat dimuat.'));
            } finally {
                if (!dilepas) {
                    setKunciTermuat(kunciMuat);
                }
            }
        };

        void muat();

        return () => {
            dilepas = true;
        };
    }, [canReadModels, kunciMuat, manufacturer.id, page]);

    async function archive() {
        if (!archiving) {
            return;
        }

        try {
            await api(`/model-aset/${archiving.id}`, { method: 'DELETE' });
            setArchiving(null);
            muatUlang();
            onChanged();
        } catch (caught) {
            setError(errorMessage(caught, 'Model belum dapat diarsipkan.'));
        }
    }

    if (!canReadModels) {
        return (
            <Empty>
                <EmptyDescription>
                    Daftar model belum tersedia karena Anda belum memiliki akses
                    untuk membacanya.
                </EmptyDescription>
            </Empty>
        );
    }

    const optionLabel = (option: ParentSummary) =>
        `${option.kode} — ${option.nama}`;
    const columns: DataTableColumn<PabrikanModelRecord>[] = [
        {
            id: 'model',
            header: 'Model',
            cell: (item) => (
                <div>
                    <span className="font-medium">{item.nama}</span>
                    {!item.aktif && (
                        <Badge className="ml-2" variant="secondary">
                            Tidak aktif
                        </Badge>
                    )}
                </div>
            ),
            sortValue: (item) => item.nama,
            width: 220,
        },
        {
            id: 'description',
            header: 'Keterangan',
            cell: (item) => (
                <span className="text-muted-foreground">
                    {item.keterangan || '–'}
                </span>
            ),
            width: 260,
        },
        {
            id: 'asset-type',
            header: 'Jenis aset',
            cell: (item) => (
                <span className="text-muted-foreground">
                    {item.jenis_aset ? optionLabel(item.jenis_aset) : '–'}
                </span>
            ),
            width: 220,
        },
        {
            id: 'assets',
            header: 'Aset',
            cell: (item) => (canReadAssets ? (item.asset_count ?? '–') : '–'),
            width: 90,
        },
    ];
    const actions: DataTableRowAction[] = [];

    if (canUpdate) {
        actions.push({ id: 'edit', label: 'Ubah' });
    }

    if (canArchive) {
        actions.push({
            id: 'archive',
            label: 'Arsipkan',
            destructive: true,
            separatorBefore: actions.length > 0,
        });
    }

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <p className="font-semibold">Model</p>
                    <p className="text-muted-foreground text-sm">
                        {meta.total} model ditemukan untuk pabrikan ini.
                    </p>
                </div>
                {canCreate && (
                    <ActionButton
                        action="create"
                        type="button"
                        onClick={() => setEditing(null)}
                    >
                        Tambah model
                    </ActionButton>
                )}
            </div>
            {/* Keadaan memuat diperiksa lebih dulu daripada pesan kesalahan supaya
                tombol "Coba lagi" langsung terlihat bekerja: pesan lama tidak lagi
                menutupi permintaan yang sedang berjalan. */}
            {loading ? (
                <Empty>
                    <EmptyDescription>Memuat model…</EmptyDescription>
                </Empty>
            ) : error ? (
                <Empty>
                    <EmptyHeader>
                        <EmptyTitle>Model belum dapat ditampilkan</EmptyTitle>
                        <EmptyDescription>{error}</EmptyDescription>
                    </EmptyHeader>
                    <Button variant="outline" onClick={muatUlang}>
                        Coba lagi
                    </Button>
                </Empty>
            ) : items.length === 0 ? (
                <Empty>
                    <EmptyHeader>
                        <EmptyTitle>Belum ada model</EmptyTitle>
                        <EmptyDescription>
                            Tambahkan model pertama untuk pabrikan ini.
                        </EmptyDescription>
                    </EmptyHeader>
                </Empty>
            ) : (
                <DataTable
                    columns={columns}
                    data={items}
                    getRowKey={(item) => item.id}
                    getRowLabel={(item) => item.nama}
                    actions={actions}
                    onRowAction={(action, item) => {
                        if (action === 'edit') {
                            setEditing(item);
                        }

                        if (action === 'archive') {
                            setArchiving(item);
                        }
                    }}
                />
            )}
            {meta.last_page > 1 && (
                <div className="text-muted-foreground flex items-center justify-end gap-3 text-sm">
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={meta.current_page <= 1}
                        onClick={() => setPage((current) => current - 1)}
                    >
                        Sebelumnya
                    </Button>
                    <span>
                        Halaman {meta.current_page} dari {meta.last_page}
                    </span>
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={meta.current_page >= meta.last_page}
                        onClick={() => setPage((current) => current + 1)}
                    >
                        Berikutnya
                    </Button>
                </div>
            )}

            {editing !== undefined && (
                <ModelAsetFormSheet
                    key={editing?.id ?? 'baru'}
                    manufacturer={manufacturer}
                    value={editing}
                    canReadJenis={canReadJenis}
                    onClose={() => setEditing(undefined)}
                    onSaved={() => {
                        setEditing(undefined);
                        muatUlang();
                        onChanged();
                    }}
                />
            )}

            <AlertDialog
                open={archiving !== null}
                onOpenChange={(open) => !open && setArchiving(null)}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Arsipkan {archiving?.nama}?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            Model ini tidak lagi tampil pada daftar pilihan.
                            Aset yang sudah memakainya tidak berubah.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Batal</AlertDialogCancel>
                        <AlertDialogAction onClick={() => void archive()}>
                            Arsipkan
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
}
