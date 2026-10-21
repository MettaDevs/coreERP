import { useCallback, useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
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
import {
    Card,
    CardAction,
    CardContent,
    CardHeader,
    CardTitle,
} from '@apperp/ui/card';
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
import { api, toastSaveError } from '../api';
import { KategoriPengadaanModal } from './kategori-pengadaan/KategoriPengadaanModal';
import type { KategoriPengadaanItem } from './kategori-pengadaan/KategoriPengadaanModal';

type PageProps = {
    view?: string;
    segments?: string[];
    permissions?: string[];
};

type PaginationMeta = {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
};

export default function KategoriPengadaanPage(_props?: PageProps) {
    void _props;
    const [items, setItems] = useState<KategoriPengadaanItem[]>([]);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');

    const [meta, setMeta] = useState<PaginationMeta>({
        current_page: 1,
        last_page: 1,
        per_page: 15,
        total: 0,
    });

    // Form Modal State
    const [modalOpen, setModalOpen] = useState(false);
    const [editingItem, setEditingItem] =
        useState<KategoriPengadaanItem | null>(null);

    // Archive / Delete State
    const [deleteId, setDeleteId] = useState<string | null>(null);
    const [deleting, setDeleting] = useState(false);

    const fetchData = useCallback(
        async (page = 1) => {
            setLoading(true);

            try {
                const params = new URLSearchParams();
                params.set('page', String(page));

                if (search.trim()) {
                    params.set('search', search.trim());
                }

                const res = await api<{
                    status: string;
                    data: KategoriPengadaanItem[];
                    meta: PaginationMeta;
                }>(`/kategori-pengadaan?${params.toString()}`);

                setItems(res.data || []);
                setMeta(
                    res.meta || {
                        current_page: 1,
                        last_page: 1,
                        per_page: 15,
                        total: res.data?.length || 0,
                    },
                );
            } catch (err) {
                toastSaveError(err, 'Gagal memuat data kategori pengadaan.');
            } finally {
                setLoading(false);
            }
        },
        [search],
    );

    useEffect(() => {
        const timer = setTimeout(() => {
            void fetchData(1);
        }, 0);

        return () => clearTimeout(timer);
    }, [fetchData]);

    const handleCreate = () => {
        setEditingItem(null);
        setModalOpen(true);
    };

    const handleEdit = (item: KategoriPengadaanItem) => {
        setEditingItem(item);
        setModalOpen(true);
    };

    const handleSuccess = (saved: KategoriPengadaanItem, isNew: boolean) => {
        if (isNew) {
            setItems((prev) => [saved, ...prev]);
            setMeta((prev) => ({ ...prev, total: prev.total + 1 }));
        } else {
            setItems((prev) =>
                prev.map((it) =>
                    it.id === saved.id ? { ...it, ...saved } : it,
                ),
            );
        }
    };

    const handleToggleStatus = async (item: KategoriPengadaanItem) => {
        try {
            const res = await api<{
                status: string;
                message: string;
                data: KategoriPengadaanItem;
            }>(`/kategori-pengadaan/${item.id}/toggle-status`, {
                method: 'PATCH',
            });

            toast.success(res.message);
            setItems((prev) =>
                prev.map((it) =>
                    it.id === item.id ? { ...it, aktif: res.data.aktif } : it,
                ),
            );
        } catch (err) {
            toastSaveError(err, 'Gagal mengubah status kategori pengadaan.');
        }
    };

    const handleArchive = async () => {
        if (!deleteId) {
            return;
        }

        setDeleting(true);

        try {
            await api(`/kategori-pengadaan/${deleteId}`, {
                method: 'DELETE',
            });
            toast.success('Kategori pengadaan berhasil dihapus.');
            setItems((prev) => prev.filter((it) => it.id !== deleteId));
            setMeta((prev) => ({
                ...prev,
                total: Math.max(0, prev.total - 1),
            }));
            setDeleteId(null);
        } catch (err) {
            toastSaveError(err, 'Gagal menghapus kategori pengadaan.');
        } finally {
            setDeleting(false);
        }
    };

    const columns: DataTableColumn<KategoriPengadaanItem>[] = useMemo(
        () => [
            {
                id: 'kode_pengadaan',
                header: 'Kode Pengadaan',
                cell: (row) => (
                    <span className="font-mono text-xs font-semibold">
                        {row.kode_pengadaan}
                    </span>
                ),
                width: 160,
            },
            {
                id: 'kategori_pengadaan',
                header: 'Kategori Pengadaan',
                cell: (row) => (
                    <span className="text-xs font-medium">
                        {row.kategori_pengadaan}
                    </span>
                ),
                width: 260,
            },
            {
                id: 'keterangan',
                header: 'Keterangan',
                cell: (row) => (
                    <span className="text-muted-foreground text-xs">
                        {row.keterangan || '—'}
                    </span>
                ),
                width: 260,
            },
            {
                id: 'status',
                header: 'Status',
                cell: (row) => (
                    <Badge
                        variant={row.aktif ? 'default' : 'secondary'}
                        className="text-[11px]"
                    >
                        {row.aktif ? 'Aktif' : 'Tidak aktif'}
                    </Badge>
                ),
                width: 120,
            },
        ],
        [],
    );

    const rowActions: DataTableRowAction[] = useMemo(
        () => [
            { id: 'edit', label: 'Ubah' },
            { id: 'toggle', label: 'Ubah status' },
            {
                id: 'archive',
                label: 'Delete',
                destructive: true,
                separatorBefore: true,
            },
        ],
        [],
    );

    return (
        <div className="h-full min-h-0 overflow-hidden">
            <Card
                aria-labelledby="list-title"
                className="flex h-full min-h-0 flex-col overflow-hidden rounded-none border-0 shadow-none"
            >
                <CardHeader className="min-h-0 shrink-0 border-b">
                    <CardTitle id="list-title">Kategori Pengadaan</CardTitle>
                    <CardAction>
                        <ActionButton action="create" onClick={handleCreate} />
                    </CardAction>
                </CardHeader>

                <CardContent className="flex min-h-0 flex-1 flex-col px-0">
                    <div className="flex shrink-0 flex-col gap-3 border-b px-5 py-3 sm:flex-row sm:items-end sm:justify-between">
                        <div className="space-y-1">
                            <p className="text-sm font-semibold">
                                Daftar Kategori Pengadaan
                            </p>
                            <p className="text-muted-foreground text-xs">
                                {meta.total} data dimuat
                            </p>
                        </div>
                        <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-center">
                            <Input
                                className="h-8 w-full text-xs sm:w-64"
                                type="search"
                                placeholder="Cari kode atau kategori..."
                                aria-label="Cari kategori pengadaan"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                            />
                        </div>
                    </div>

                    {loading && !items.length ? (
                        <Empty>
                            <EmptyDescription>
                                Memuat data kategori pengadaan…
                            </EmptyDescription>
                        </Empty>
                    ) : items.length === 0 ? (
                        <Empty>
                            <EmptyHeader>
                                <EmptyTitle>
                                    Belum ada kategori pengadaan
                                </EmptyTitle>
                                <EmptyDescription>
                                    Pilih Baru untuk menambahkan data kategori
                                    pengadaan pertama.
                                </EmptyDescription>
                            </EmptyHeader>
                        </Empty>
                    ) : (
                        <DataTable
                            scrollMode="viewport"
                            className="min-h-0 flex-1"
                            columns={columns}
                            data={items}
                            getRowKey={(row) => row.id}
                            getRowLabel={(row) => row.kategori_pengadaan}
                            actions={rowActions}
                            onRowAction={(actionId, row) => {
                                if (actionId === 'edit') {
                                    handleEdit(row);
                                }

                                if (actionId === 'toggle') {
                                    void handleToggleStatus(row);
                                }

                                if (actionId === 'archive') {
                                    setDeleteId(row.id);
                                }
                            }}
                        />
                    )}
                </CardContent>
            </Card>

            {/* Modal Tambah/Ubah Kategori */}
            <KategoriPengadaanModal
                open={modalOpen}
                onOpenChange={setModalOpen}
                editingItem={editingItem}
                onSuccess={handleSuccess}
            />

            {/* Dialog Konfirmasi Hapus */}
            <AlertDialog
                open={!!deleteId}
                onOpenChange={(open) => !open && setDeleteId(null)}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Hapus Kategori Pengadaan?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            Apakah Anda yakin ingin menghapus kategori pengadaan
                            ini? Data yang dihapus tidak akan tampil dalam
                            pilihan transaksi baru.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={deleting}>
                            Batal
                        </AlertDialogCancel>
                        <AlertDialogAction
                            onClick={handleArchive}
                            disabled={deleting}
                            className="bg-rose-600 text-white hover:bg-rose-700"
                        >
                            {deleting ? 'Menghapus...' : 'Hapus'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
}
