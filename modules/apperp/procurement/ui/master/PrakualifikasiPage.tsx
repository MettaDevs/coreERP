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
    Building2,
    Calendar,
    CheckCircle2,
    ClipboardCheck,
    Clock,
    Filter,
    Plus,
    RefreshCw,
    Search,
    XCircle,
} from 'lucide-react';
import { toast } from 'sonner';
import { api, toastSaveError } from '../api';
import {
    PrakualifikasiFormModal,
    type PrakualifikasiFormData,
} from './prakualifikasi/PrakualifikasiFormModal';
import { PrakualifikasiDetailModal } from './prakualifikasi/PrakualifikasiDetailModal';

type PageProps = {
    view: string;
    segments?: string[];
    permissions?: string[];
};

type PrakualifikasiRow = PrakualifikasiFormData & {
    id: string;
    status_vendor?: string;
    created_at: string;
    updated_at: string;
};

type PaginationMeta = {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
};

function formatDisplayDate(dateStr: string): string {
    if (!dateStr) return '';
    if (dateStr.includes('/')) return dateStr;
    const parts = dateStr.split('-');
    if (parts.length === 3) {
        return `${parts[2]}/${parts[1]}/${parts[0]}`;
    }
    return dateStr;
}

export default function PrakualifikasiPage({
    view: _view,
    segments: _segments,
    permissions: _permissions,
}: PageProps) {
    const [items, setItems] = useState<PrakualifikasiRow[]>([]);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState('semua');
    const [startDate, setStartDate] = useState('');
    const [endDate, setEndDate] = useState('');

    const [meta, setMeta] = useState<PaginationMeta>({
        current_page: 1,
        last_page: 1,
        per_page: 15,
        total: 0,
    });

    // Modals
    const [formOpen, setFormOpen] = useState(false);
    const [editingItem, setEditingItem] =
        useState<PrakualifikasiFormData | null>(null);
    const [detailOpen, setDetailOpen] = useState(false);
    const [viewingItem, setViewingItem] =
        useState<PrakualifikasiFormData | null>(null);

    // Archive / delete dialog
    const [deleteId, setDeleteId] = useState<string | null>(null);
    const [deleting, setDeleting] = useState(false);

    const fetchData = useCallback(
        async (page = 1) => {
            setLoading(true);
            try {
                const params = new URLSearchParams();
                params.set('page', String(page));
                if (search.trim()) params.set('search', search.trim());
                if (statusFilter !== 'semua')
                    params.set('status_kualifikasi', statusFilter);
                if (startDate) params.set('start_date', startDate);
                if (endDate) params.set('end_date', endDate);

                const res = await api<{
                    status: string;
                    data: PrakualifikasiRow[];
                    meta: PaginationMeta;
                }>(`/prakualifikasi?${params.toString()}`);

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
                toastSaveError(err, 'Gagal memuat daftar prakualifikasi.');
            } finally {
                setLoading(false);
            }
        },
        [search, statusFilter, startDate, endDate],
    );

    useEffect(() => {
        fetchData(1);
    }, [fetchData]);

    const handleCreate = () => {
        setEditingItem(null);
        setFormOpen(true);
    };

    const handleEdit = (item: PrakualifikasiRow) => {
        setEditingItem(item);
        setFormOpen(true);
    };

    const handleView = (item: PrakualifikasiRow) => {
        setViewingItem(item);
        setDetailOpen(true);
    };

    const handleArchive = async () => {
        if (!deleteId) return;
        setDeleting(true);
        try {
            await api(`/prakualifikasi/${deleteId}`, {
                method: 'DELETE',
            });
            toast.success('Data prakualifikasi berhasil diarsipkan.');
            setDeleteId(null);
            fetchData(meta.current_page);
        } catch (err) {
            toastSaveError(err, 'Gagal mengarsipkan data prakualifikasi.');
        } finally {
            setDeleting(false);
        }
    };

    const getStatusBadge = (status: string) => {
        if (status === 'Lolos' || status === 'Memenuhi Syarat' || status === '1. Lolos') {
            return (
                <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300">
                    <CheckCircle2 className="mr-1 h-3 w-3" />
                    Lolos
                </span>
            );
        }
        if (status === 'Batal' || status === 'Tidak Memenuhi Syarat' || status === '2. Batal') {
            return (
                <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-300">
                    <XCircle className="mr-1 h-3 w-3" />
                    Batal
                </span>
            );
        }
        return (
            <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">
                <Clock className="mr-1 h-3 w-3" />
                {status || 'Dalam Proses'}
            </span>
        );
    };

    return (
        <div className="space-y-6 p-6">
            {/* Header & Action Bar Sesuai Acuan Mockup */}
            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <div className="text-muted-foreground flex items-center gap-2 text-sm font-medium">
                        <span>Pengadaan</span>
                        <span>/</span>
                        <span>Master Data</span>
                        <span>/</span>
                        <span className="text-foreground font-semibold">
                            Prakualifikasi
                        </span>
                    </div>
                    <h1 className="text-foreground mt-1 text-2xl font-bold tracking-tight">
                        Prakualifikasi Vendor/Supplier
                    </h1>
                </div>

                {/* Tombol Aksi: Proses Prakualifikasi & Refresh */}
                <div className="flex flex-wrap items-center gap-2">
                    <Button
                        size="sm"
                        onClick={handleCreate}
                        className="bg-primary hover:bg-primary/90 text-primary-foreground font-medium h-9 shadow-sm"
                    >
                        <ClipboardCheck className="mr-1.5 h-3.5 w-3.5" />
                        Proses Prakualifikasi
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => fetchData(meta.current_page)}
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

            {/* Kotak Filter & Pencarian */}
            <Card className="border shadow-sm">
                <CardHeader className="pb-3 pt-4 px-4 border-b bg-muted/20">
                    <div className="flex flex-col md:flex-row md:items-center justify-between gap-3">
                        <div className="flex items-center gap-2 flex-1 max-w-md">
                            <span className="text-xs font-semibold text-muted-foreground whitespace-nowrap">
                                Pencarian:
                            </span>
                            <div className="relative flex-1">
                                <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                                <Input
                                    placeholder="Cari nomor, kode, nama vendor, atau kontak..."
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    className="pl-8 h-9 text-xs"
                                />
                            </div>
                        </div>

                        <div className="flex flex-wrap items-center gap-2">
                            <div className="flex items-center gap-1.5 text-xs text-muted-foreground font-medium">
                                <Filter className="h-3.5 w-3.5" />
                                Status Kualifikasi:
                            </div>
                            <select
                                className="h-9 rounded-md border border-input bg-background px-3 py-1 text-xs shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                                value={statusFilter}
                                onChange={(e) =>
                                    setStatusFilter(e.target.value)
                                }
                            >
                                <option value="semua">Semua Status</option>
                                <option value="Lolos">Lolos</option>
                                <option value="Batal">Batal</option>
                            </select>

                            {(search || statusFilter !== 'semua') && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => {
                                        setSearch('');
                                        setStatusFilter('semua');
                                    }}
                                    className="h-9 text-xs"
                                >
                                    Reset
                                </Button>
                            )}
                        </div>
                    </div>
                </CardHeader>

                <CardContent className="p-0">
                    <div className="px-4 py-2.5 bg-muted/30 border-b flex items-center justify-between">
                        <span className="font-semibold text-xs text-foreground uppercase tracking-wide">
                            Daftar Prakualifikasi Vendor/Supplier
                        </span>
                        <span className="text-xs text-muted-foreground">
                            Total: {meta.total} Data
                        </span>
                    </div>

                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader className="bg-muted/40 border-b">
                                <TableRow>
                                    <TableHead className="w-12 text-center font-semibold text-foreground border-r">
                                        No.
                                    </TableHead>
                                    <TableHead className="w-44 text-center font-semibold text-foreground border-r">
                                        Info Prakualifikasi
                                    </TableHead>
                                    <TableHead className="w-36 text-center font-semibold text-foreground border-r">
                                        Kode Vendor/Supplier
                                    </TableHead>
                                    <TableHead className="min-w-[180px] text-center font-semibold text-foreground border-r">
                                        Nama Vendor/Supplier
                                    </TableHead>
                                    <TableHead className="w-36 text-center font-semibold text-foreground border-r">
                                        Contact Person
                                    </TableHead>
                                    <TableHead className="w-36 text-center font-semibold text-foreground border-r">
                                        Telp/HP
                                    </TableHead>
                                    <TableHead className="min-w-[200px] text-center font-semibold text-foreground border-r">
                                        Alamat Vendor/Supplier
                                    </TableHead>
                                    <TableHead className="w-36 text-center font-semibold text-foreground border-r">
                                        Status Vendor/Supplier
                                    </TableHead>
                                    <TableHead className="w-36 text-center font-semibold text-foreground">
                                        Status Kualifikasi
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {loading ? (
                                    <TableRow>
                                        <TableCell
                                            colSpan={9}
                                            className="py-12 text-center text-muted-foreground text-sm"
                                        >
                                            Memuat daftar prakualifikasi vendor/supplier...
                                        </TableCell>
                                    </TableRow>
                                ) : items.length === 0 ? (
                                    <TableRow>
                                        <TableCell
                                            colSpan={9}
                                            className="py-12"
                                        >
                                            <Empty>
                                                <EmptyHeader>
                                                    <EmptyTitle>
                                                        Belum Ada Data Prakualifikasi
                                                    </EmptyTitle>
                                                    <EmptyDescription>
                                                        Mulai masukkan data prakualifikasi vendor baru dengan menekan tombol <strong>Proses Prakualifikasi</strong> di atas.
                                                    </EmptyDescription>
                                                </EmptyHeader>
                                                <div className="mt-4 flex justify-center">
                                                    <Button
                                                        size="sm"
                                                        onClick={handleCreate}
                                                        className="bg-blue-600 hover:bg-blue-700 text-white"
                                                    >
                                                        <Plus className="mr-1.5 h-4 w-4" />
                                                        Proses Prakualifikasi
                                                    </Button>
                                                </div>
                                            </Empty>
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    items.map((row, idx) => (
                                        <TableRow
                                            key={row.id}
                                            onClick={() => handleView(row)}
                                            className="hover:bg-muted/50 cursor-pointer transition-colors border-b text-xs"
                                        >
                                            {/* 1. No */}
                                            <TableCell className="text-center font-medium text-muted-foreground border-r">
                                                {(meta.current_page - 1) *
                                                    meta.per_page +
                                                    idx +
                                                    1}
                                            </TableCell>

                                            {/* 2. Info Prakualifikasi (Tanggal baris 1, No Prakualifikasi baris 2) */}
                                            <TableCell className="text-center border-r font-mono">
                                                <div className="text-foreground font-medium">
                                                    {formatDisplayDate(
                                                        row.tanggal_prakualifikasi,
                                                    )}
                                                </div>
                                                <div
                                                    className="text-primary hover:underline cursor-pointer font-semibold mt-0.5"
                                                    onClick={() =>
                                                        handleView(row)
                                                    }
                                                >
                                                    {row.nomor_prakualifikasi}
                                                </div>
                                            </TableCell>

                                            {/* 3. Kode Vendor/Supplier */}
                                            <TableCell className="text-center font-mono font-medium border-r">
                                                {row.kode_vendor}
                                            </TableCell>

                                            {/* 4. Nama Vendor/Supplier */}
                                            <TableCell className="font-bold text-foreground border-r">
                                                {row.nama_vendor}
                                            </TableCell>

                                            {/* 5. Contact Person */}
                                            <TableCell className="text-center border-r">
                                                {row.nama_pic || '-'}
                                            </TableCell>

                                            {/* 6. Telp/HP */}
                                            <TableCell className="text-center font-mono border-r">
                                                {row.telepon || '-'}
                                            </TableCell>

                                            {/* 7. Alamat Vendor/Supplier */}
                                            <TableCell className="border-r max-w-xs">
                                                {row.alamat || '-'}
                                            </TableCell>

                                            {/* 8. Status Vendor/Supplier */}
                                            <TableCell className="text-center border-r font-medium">
                                                {row.status_vendor ||
                                                    'Vendor Resmi'}
                                            </TableCell>

                                            {/* 9. Status Kualifikasi */}
                                            <TableCell className="text-center">
                                                {getStatusBadge(
                                                    row.status_kualifikasi,
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </div>

                    {/* Pagination */}
                    {meta.total > 0 && (
                        <div className="flex items-center justify-between px-6 py-3 border-t text-xs text-muted-foreground">
                            <div>
                                Menampilkan{' '}
                                <strong className="text-foreground">
                                    {(meta.current_page - 1) * meta.per_page +
                                        1}
                                </strong>{' '}
                                -{' '}
                                <strong className="text-foreground">
                                    {Math.min(
                                        meta.current_page * meta.per_page,
                                        meta.total,
                                    )}
                                </strong>{' '}
                                dari{' '}
                                <strong className="text-foreground">
                                    {meta.total}
                                </strong>{' '}
                                vendor/supplier
                            </div>
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

            {/* Modal Form Prakualifikasi */}
            <PrakualifikasiFormModal
                open={formOpen}
                onOpenChange={setFormOpen}
                initialData={editingItem}
                onSuccess={() => fetchData(meta.current_page)}
            />

            {/* Modal Detail Prakualifikasi */}
            <PrakualifikasiDetailModal
                open={detailOpen}
                onOpenChange={setDetailOpen}
                data={viewingItem}
                onEdit={() => {
                    if (viewingItem) {
                        setEditingItem(viewingItem);
                        setFormOpen(true);
                    }
                }}
                onDelete={() => {
                    if (viewingItem?.id) {
                        setDeleteId(viewingItem.id);
                    }
                }}
            />

            {/* Alert Konfirmasi Arsip */}
            <AlertDialog
                open={Boolean(deleteId)}
                onOpenChange={(open) => !open && setDeleteId(null)}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Arsipkan Prakualifikasi Vendor?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            Tindakan ini akan mengarsipkan data evaluasi vendor/supplier bersangkutan. Rekam data tidak dihapus permanen demi kebutuhan riwayat audit.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={deleting}>
                            Batal
                        </AlertDialogCancel>
                        <AlertDialogAction
                            onClick={handleArchive}
                            disabled={deleting}
                            className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                        >
                            {deleting ? 'Mengarsipkan...' : 'Arsipkan'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
}
