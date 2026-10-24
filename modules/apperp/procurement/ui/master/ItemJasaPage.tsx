import { Eye } from 'lucide-react';
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
import { ItemJasaDetailModal } from './item-jasa/ItemJasaDetailModal';
import { ItemJasaModal } from './item-jasa/ItemJasaModal';
import type { ItemJasaRecord } from './item-jasa/ItemJasaModal';

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

export default function ItemJasaPage(_props?: PageProps) {
    void _props;
    const [items, setItems] = useState<ItemJasaRecord[]>([]);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');

    const [meta, setMeta] = useState<PaginationMeta>({
        current_page: 1,
        last_page: 1,
        per_page: 15,
        total: 0,
    });

    // Form Modal State (Tambah / Edit)
    const [formModalOpen, setFormModalOpen] = useState(false);
    const [editingItem, setEditingItem] = useState<ItemJasaRecord | null>(null);

    // Detail Modal State
    const [detailModalOpen, setDetailModalOpen] = useState(false);
    const [viewingItem, setViewingItem] = useState<ItemJasaRecord | null>(null);

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
                    data: ItemJasaRecord[];
                    meta: PaginationMeta;
                }>(`/item-jasa?${params.toString()}`);

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
                toastSaveError(err, 'Gagal memuat data item jasa/upah.');
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
        setFormModalOpen(true);
    };

    const handleEdit = (item: ItemJasaRecord) => {
        setEditingItem(item);
        setFormModalOpen(true);
    };

    const handleView = (item: ItemJasaRecord) => {
        setViewingItem(item);
        setDetailModalOpen(true);
    };

    const handleSuccess = (saved: ItemJasaRecord, isNew: boolean) => {
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

    const handleToggleStatus = async (item: ItemJasaRecord) => {
        try {
            const res = await api<{
                status: string;
                message: string;
                data: ItemJasaRecord;
            }>(`/item-jasa/${item.id}/toggle-status`, {
                method: 'PATCH',
            });

            toast.success(res.message);
            setItems((prev) =>
                prev.map((it) =>
                    it.id === item.id ? { ...it, aktif: res.data.aktif } : it,
                ),
            );
        } catch (err) {
            toastSaveError(err, 'Gagal mengubah status item upah.');
        }
    };

    const handleArchive = async () => {
        if (!deleteId) {
            return;
        }

        setDeleting(true);

        try {
            await api(`/item-jasa/${deleteId}`, {
                method: 'DELETE',
            });
            toast.success('Item upah berhasil dihapus.');
            setItems((prev) => prev.filter((it) => it.id !== deleteId));
            setMeta((prev) => ({
                ...prev,
                total: Math.max(0, prev.total - 1),
            }));
            setDeleteId(null);
        } catch (err) {
            toastSaveError(err, 'Gagal menghapus item upah.');
        } finally {
            setDeleting(false);
        }
    };

    const columns: DataTableColumn<ItemJasaRecord>[] = useMemo(
        () => [
            {
                id: 'kode_upah',
                header: 'Kode Upah',
                cell: (row) => (
                    <span className="font-mono text-xs font-semibold">
                        {row.kode_upah}
                    </span>
                ),
                width: 180,
            },
            {
                id: 'nama_upah',
                header: 'Nama Upah',
                cell: (row) => (
                    <span className="text-xs font-medium">{row.nama_upah}</span>
                ),
                width: 380,
            },
            {
                id: 'satuan',
                header: 'Satuan',
                cell: (row) => (
                    <span className="text-muted-foreground text-xs font-medium">
                        {row.satuan}
                    </span>
                ),
                width: 160,
            },
        ],
        [],
    );

    const rowActions: DataTableRowAction[] = useMemo(
        () => [
            {
                id: 'view',
                label: 'View',
                icon: <Eye className="mr-2 h-4 w-4" />,
            },
            { id: 'edit', label: 'Edit' },
            { id: 'toggle', label: 'Aktif/Non Aktif' },
            {
                id: 'archive',
                label: 'Hapus',
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
                    <CardTitle id="list-title">Item Upah</CardTitle>
                    <CardAction>
                        <ActionButton action="create" onClick={handleCreate} />
                    </CardAction>
                </CardHeader>

                <CardContent className="flex min-h-0 flex-1 flex-col px-0">
                    <div className="flex shrink-0 flex-col gap-3 border-b px-5 py-3 sm:flex-row sm:items-end sm:justify-between">
                        <div className="space-y-1">
                            <p className="text-sm font-semibold">
                                Daftar Item Upah
                            </p>
                            <p className="text-muted-foreground text-xs">
                                {meta.total} data dimuat
                            </p>
                        </div>
                        <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-center">
                            <Input
                                className="h-8 w-full text-xs sm:w-64"
                                type="search"
                                placeholder="Cari kode atau nama upah..."
                                aria-label="Cari item upah"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                            />
                        </div>
                    </div>

                    {loading && !items.length ? (
                        <Empty>
                            <EmptyDescription>
                                Memuat data item upah…
                            </EmptyDescription>
                        </Empty>
                    ) : items.length === 0 ? (
                        <Empty>
                            <EmptyHeader>
                                <EmptyTitle>Belum ada item upah</EmptyTitle>
                                <EmptyDescription>
                                    Pilih Baru untuk menambahkan data item upah
                                    pertama.
                                </EmptyDescription>
                            </EmptyHeader>
                        </Empty>
                    ) : (
                        <DataTable
                            scrollMode="viewport"
                            className="min-h-0 flex-1"
                            showRowNumbers
                            columns={columns}
                            data={items}
                            getRowKey={(row) => row.id}
                            getRowLabel={(row) => row.nama_upah}
                            actionsPlacement="trailing"
                            actions={rowActions}
                            onRowAction={(actionId, row) => {
                                if (actionId === 'view') {
                                    handleView(row);
                                }

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
                            onRowClick={(row) => handleView(row)}
                        />
                    )}
                </CardContent>
            </Card>

            {/* Modal Tambah/Ubah Form Item Upah */}
            <ItemJasaModal
                open={formModalOpen}
                onOpenChange={setFormModalOpen}
                editingItem={editingItem}
                onSuccess={handleSuccess}
            />

            {/* Modal Detail Item Upah */}
            <ItemJasaDetailModal
                open={detailModalOpen}
                onOpenChange={setDetailModalOpen}
                item={viewingItem}
                onEdit={handleEdit}
            />

            {/* Dialog Konfirmasi Hapus */}
            <AlertDialog
                open={!!deleteId}
                onOpenChange={(open) => !open && setDeleteId(null)}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Hapus Item Upah?</AlertDialogTitle>
                        <AlertDialogDescription>
                            Apakah Anda yakin ingin menghapus data item
                            upah/jasa ini? Data yang dihapus tidak akan tampil
                            dalam pilihan transaksi pengadaan baru.
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
