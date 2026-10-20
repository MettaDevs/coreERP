import { useCallback, useEffect, useState } from 'react';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import {
    Card,
    CardContent,
    CardHeader,
} from '@apperp/ui/card';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@apperp/ui/empty';
import { Input } from '@apperp/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@apperp/ui/table';
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
    Eye,
    HardHat,
    MoreHorizontal,
    Pencil,
    Plus,
    Power,
    RefreshCw,
    Search,
    Trash2,
    X,
} from 'lucide-react';
import { toast } from 'sonner';
import { api, toastSaveError } from '../api';
import {
    ItemSubkontModal,
    type ItemSubkontRecord,
} from './item-subkont/ItemSubkontModal';
import { ItemSubkontDetailModal } from './item-subkont/ItemSubkontDetailModal';

type PageProps = {
    view: string;
    segments?: string[];
    permissions?: string[];
};

type PaginationMeta = {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
};

export default function ItemSubkontPage({
    view: _view,
    segments: _segments,
    permissions: _permissions,
}: PageProps) {
    const [items, setItems] = useState<ItemSubkontRecord[]>([]);
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
    const [editingItem, setEditingItem] = useState<ItemSubkontRecord | null>(null);

    // Detail Modal State (View 1. View icon mata)
    const [detailModalOpen, setDetailModalOpen] = useState(false);
    const [viewingItem, setViewingItem] = useState<ItemSubkontRecord | null>(null);

    // Archive / Delete State
    const [deleteId, setDeleteId] = useState<string | null>(null);
    const [deleting, setDeleting] = useState(false);

    const fetchData = useCallback(
        async (page = 1) => {
            setLoading(true);
            try {
                const params = new URLSearchParams();
                params.set('page', String(page));
                if (search.trim()) params.set('search', search.trim());

                const res = await api<{
                    status: string;
                    data: ItemSubkontRecord[];
                    meta: PaginationMeta;
                }>(`/item-subkont?${params.toString()}`);

                setItems(res.data || []);
                if (res.meta) {
                    setMeta(res.meta);
                }
            } catch (err) {
                toastSaveError(err, 'Gagal memuat daftar item subkontraktor.');
            } finally {
                setLoading(false);
            }
        },
        [search]
    );

    useEffect(() => {
        const timer = setTimeout(() => {
            fetchData(1);
        }, 250);
        return () => clearTimeout(timer);
    }, [fetchData]);

    const handleCreate = () => {
        setEditingItem(null);
        setFormModalOpen(true);
    };

    const handleEdit = (item: ItemSubkontRecord) => {
        setEditingItem(item);
        setFormModalOpen(true);
    };

    const handleView = (item: ItemSubkontRecord) => {
        setViewingItem(item);
        setDetailModalOpen(true);
    };

    const handleSuccess = (saved: ItemSubkontRecord, isNew: boolean) => {
        if (isNew) {
            setItems((prev) => [saved, ...prev]);
            setMeta((prev) => ({ ...prev, total: prev.total + 1 }));
        } else {
            setItems((prev) =>
                prev.map((it) => (it.id === saved.id ? saved : it))
            );
        }
    };

    const handleToggleStatus = async (item: ItemSubkontRecord) => {
        try {
            const res = await api<{
                status: string;
                message: string;
                data: ItemSubkontRecord;
            }>(`/item-subkont/${item.id}/toggle-status`, {
                method: 'PATCH',
            });
            toast.success(res.message);
            setItems((prev) =>
                prev.map((it) => (it.id === item.id ? res.data : it))
            );
        } catch (err) {
            toastSaveError(err, 'Gagal mengubah status item subkontraktor.');
        }
    };

    const handleArchive = async () => {
        if (!deleteId) return;
        setDeleting(true);
        try {
            await api(`/item-subkont/${deleteId}`, {
                method: 'DELETE',
            });
            toast.success('Item subkontraktor berhasil dihapus.');
            setItems((prev) => prev.filter((it) => it.id !== deleteId));
            setMeta((prev) => ({ ...prev, total: Math.max(0, prev.total - 1) }));
            setDeleteId(null);
        } catch (err) {
            toastSaveError(err, 'Gagal menghapus item subkontraktor.');
        } finally {
            setDeleting(false);
        }
    };

    const handleRefresh = () => {
        fetchData(meta.current_page);
        toast.info('Data item subkontraktor diperbarui.');
    };

    return (
        <div className="space-y-6 p-6">
            {/* Breadcrumb & Header Title */}
            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <div className="text-muted-foreground flex items-center gap-2 text-sm font-medium">
                        <span>Pengadaan</span>
                        <span>/</span>
                        <span>Master Data</span>
                        <span>/</span>
                        <span className="text-foreground font-semibold">
                            10. Item Subkont
                        </span>
                    </div>
                    <div className="mt-1 flex items-center gap-2">
                        <HardHat className="h-6 w-6 text-primary" />
                        <h1 className="text-2xl font-bold tracking-tight text-foreground">
                            Item Subkontraktor
                        </h1>
                    </div>
                    <p className="text-muted-foreground text-xs mt-0.5">
                        Katalog pekerjaan pihak ketiga, subkontraktor borongan, dan pekerjaan konstruksi/instalasi fisik.
                    </p>
                </div>

                {/* Tombol Aksi: Tambah & Refresh */}
                <div className="flex flex-wrap items-center gap-2">
                    <Button
                        size="sm"
                        onClick={handleCreate}
                        className="bg-primary hover:bg-primary/90 text-primary-foreground font-medium h-9 shadow-sm"
                    >
                        <Plus className="mr-1.5 h-3.5 w-3.5" />
                        Tambah Item Subkont
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={handleRefresh}
                        disabled={loading}
                        className="h-9 font-medium"
                    >
                        <RefreshCw
                            className={`mr-1.5 h-3.5 w-3.5 ${
                                loading ? 'animate-spin' : ''
                            }`}
                        />
                        Refresh
                    </Button>
                </div>
            </div>

            {/* Container Card Tabel Item Subkont */}
            <Card className="border shadow-sm">
                <CardHeader className="p-4 border-b bg-card">
                    {/* Baris Pencarian Standar */}
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div className="relative min-w-[200px] max-w-xs">
                            <div className="absolute inset-y-0 left-0 pl-2.5 flex items-center pointer-events-none text-muted-foreground">
                                <Search className="h-3.5 w-3.5" />
                            </div>
                            <Input
                                placeholder="Pencarian..."
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                className="h-8 pl-8 pr-7 text-xs bg-background border-input"
                            />
                            {search && (
                                <button
                                    type="button"
                                    onClick={() => setSearch('')}
                                    className="absolute inset-y-0 right-0 pr-2 flex items-center text-muted-foreground hover:text-foreground"
                                >
                                    <X className="h-3.5 w-3.5" />
                                </button>
                            )}
                        </div>
                    </div>
                </CardHeader>

                <CardContent className="p-0">
                    {/* Header Strip: "Daftar Item Subkont" */}
                    <div className="px-4 py-2 bg-muted/40 border-b flex items-center justify-between">
                        <span className="text-xs font-semibold text-foreground">
                            Daftar Item Subkont
                        </span>
                        <span className="text-xs text-muted-foreground font-medium">
                            Total: {meta.total} Item Subkontraktor
                        </span>
                    </div>

                    {/* Tabel Daftar Item Subkont (5 Kolom) */}
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader className="bg-muted/40 border-b">
                                <TableRow className="hover:bg-transparent">
                                    <TableHead className="w-12 text-center font-bold text-foreground border-r text-xs">
                                        No.
                                    </TableHead>
                                    <TableHead className="w-40 text-center font-bold text-foreground border-r text-xs">
                                        Kode Subkont
                                    </TableHead>
                                    <TableHead className="min-w-[240px] text-center font-bold text-foreground border-r text-xs">
                                        Nama Subkont
                                    </TableHead>
                                    <TableHead className="w-28 text-center font-bold text-foreground border-r text-xs">
                                        Satuan
                                    </TableHead>
                                    <TableHead className="w-72 text-center font-bold text-foreground text-xs">
                                        Aksi
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {loading ? (
                                    <TableRow>
                                        <TableCell
                                            colSpan={5}
                                            className="py-12 text-center text-muted-foreground text-sm"
                                        >
                                            Memuat daftar item subkontraktor...
                                        </TableCell>
                                    </TableRow>
                                ) : items.length === 0 ? (
                                    <TableRow>
                                        <TableCell
                                            colSpan={5}
                                            className="py-12"
                                        >
                                            <Empty>
                                                <EmptyHeader>
                                                    <EmptyTitle>
                                                        Belum Ada Item Subkontraktor
                                                    </EmptyTitle>
                                                    <EmptyDescription>
                                                        Tambahkan item subkontraktor baru dengan menekan tombol <strong>Tambah</strong> di atas.
                                                    </EmptyDescription>
                                                </EmptyHeader>
                                                <div className="mt-4 flex justify-center">
                                                    <Button
                                                        size="sm"
                                                        onClick={handleCreate}
                                                        className="bg-blue-600 hover:bg-blue-700 text-white text-xs"
                                                    >
                                                        <Plus className="mr-1.5 h-3.5 w-3.5" />
                                                        Tambah Item Subkont
                                                    </Button>
                                                </div>
                                            </Empty>
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    items.map((row, idx) => (
                                        <TableRow
                                            key={row.id}
                                            className="hover:bg-muted/50 transition-colors border-b text-xs"
                                        >
                                            {/* 1. No */}
                                            <TableCell className="text-center font-medium text-muted-foreground border-r">
                                                {(meta.current_page - 1) *
                                                    meta.per_page +
                                                    idx +
                                                    1}
                                            </TableCell>

                                            {/* 2. Kode Subkont */}
                                            <TableCell className="text-center font-mono font-medium text-foreground border-r">
                                                {row.kode_subkont}
                                            </TableCell>

                                            {/* 3. Nama Subkont */}
                                            <TableCell className="font-bold text-foreground border-r px-4">
                                                {row.nama_subkont}
                                            </TableCell>

                                            {/* 4. Satuan */}
                                            <TableCell className="text-center text-muted-foreground border-r font-medium">
                                                {row.satuan}
                                            </TableCell>

                                            {/* 5. Aksi (Edit, Hapus, Aktif/Non Aktif) */}
                                            <TableCell className="text-center p-2 whitespace-nowrap">
                                                <div className="flex items-center justify-center gap-1.5">
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() => handleEdit(row)}
                                                        className="h-7 px-2.5 text-xs font-medium text-amber-600 border-amber-200 hover:bg-amber-50 hover:text-amber-700 dark:border-amber-900 dark:hover:bg-amber-950"
                                                    >
                                                        <Pencil className="mr-1 h-3.5 w-3.5" />
                                                        Edit
                                                    </Button>
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() => setDeleteId(row.id)}
                                                        className="h-7 px-2.5 text-xs font-medium text-rose-600 border-rose-200 hover:bg-rose-50 hover:text-rose-700 dark:border-rose-900 dark:hover:bg-rose-950"
                                                    >
                                                        <Trash2 className="mr-1 h-3.5 w-3.5" />
                                                        Hapus
                                                    </Button>
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() => handleToggleStatus(row)}
                                                        title={`Status: ${row.aktif ? 'Aktif' : 'Non Aktif'} (Klik untuk ubah status)`}
                                                        className={`h-7 px-2.5 text-xs font-medium transition-colors ${
                                                            row.aktif
                                                                ? 'text-emerald-700 bg-emerald-50 border-emerald-300 hover:bg-emerald-100 dark:bg-emerald-950/40 dark:text-emerald-400 dark:border-emerald-800'
                                                                : 'text-slate-600 bg-slate-50 border-slate-300 hover:bg-slate-100 dark:bg-slate-900/40 dark:text-slate-400 dark:border-slate-700'
                                                        }`}
                                                    >
                                                        <Power className="mr-1 h-3.5 w-3.5" />
                                                        {row.aktif ? 'Aktif' : 'Non Aktif'}
                                                    </Button>
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </div>

                    {/* Pagination Bar */}
                    {meta.last_page > 1 && (
                        <div className="px-4 py-3 border-t flex items-center justify-between">
                            <span className="text-xs text-muted-foreground">
                                Halaman {meta.current_page} dari {meta.last_page} (Total: {meta.total})
                            </span>
                            <div className="flex gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={meta.current_page <= 1}
                                    onClick={() =>
                                        fetchData(meta.current_page - 1)
                                    }
                                    className="h-8 text-xs"
                                >
                                    Sebelumnya
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={
                                        meta.current_page >= meta.last_page
                                    }
                                    onClick={() =>
                                        fetchData(meta.current_page + 1)
                                    }
                                    className="h-8 text-xs"
                                >
                                    Berikutnya
                                </Button>
                            </div>
                        </div>
                    )}
                </CardContent>
            </Card>

            {/* Modal Tambah/Ubah Item Subkont Sesuai Form Kanan Gambar */}
            <ItemSubkontModal
                open={formModalOpen}
                onOpenChange={setFormModalOpen}
                editingItem={editingItem}
                onSuccess={handleSuccess}
            />

            {/* Modal Detail Item Subkont (1. View icon mata) */}
            <ItemSubkontDetailModal
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
                        <AlertDialogTitle>
                            Hapus Item Subkontraktor?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            Apakah Anda yakin ingin menghapus item subkontraktor ini? Data yang dihapus tidak akan tampil dalam pilihan transaksi belanja pengadaan baru.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={deleting}>
                            Batal
                        </AlertDialogCancel>
                        <AlertDialogAction
                            onClick={handleArchive}
                            disabled={deleting}
                            className="bg-rose-600 hover:bg-rose-700 text-white"
                        >
                            {deleting ? 'Menghapus...' : 'Hapus'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
}
