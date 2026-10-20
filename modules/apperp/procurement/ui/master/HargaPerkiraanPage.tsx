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
    Calculator,
    Edit,
    FileSpreadsheet,
    MoreHorizontal,
    Pencil,
    RefreshCw,
    Search,
    Tag,
    X,
} from 'lucide-react';
import { toast } from 'sonner';
import { api, toastSaveError } from '../api';
import {
    HargaPerkiraanEditModal,
    formatIndonesianDate,
    formatRupiah,
    type HargaPerkiraanItem,
} from './harga-perkiraan/HargaPerkiraanEditModal';

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

export default function HargaPerkiraanPage({
    view: _view,
    segments: _segments,
    permissions: _permissions,
}: PageProps) {
    const [items, setItems] = useState<HargaPerkiraanItem[]>([]);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');
    const [selectedGroup, setSelectedGroup] = useState('semua');

    const [meta, setMeta] = useState<PaginationMeta>({
        current_page: 1,
        last_page: 1,
        per_page: 15,
        total: 0,
    });

    // Edit Modal State
    const [editModalOpen, setEditModalOpen] = useState(false);
    const [editingItem, setEditingItem] = useState<HargaPerkiraanItem | null>(null);

    const fetchData = useCallback(
        async (page = 1) => {
            setLoading(true);
            try {
                const params = new URLSearchParams();
                params.set('page', String(page));
                if (search.trim()) params.set('search', search.trim());
                if (selectedGroup !== 'semua') params.set('group_item', selectedGroup);

                const res = await api<{
                    status: string;
                    data: HargaPerkiraanItem[];
                    meta: PaginationMeta;
                }>(`/harga-perkiraan?${params.toString()}`);

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
                toastSaveError(err, 'Gagal memuat data harga perkiraan.');
            } finally {
                setLoading(false);
            }
        },
        [search, selectedGroup],
    );

    useEffect(() => {
        fetchData(1);
    }, [fetchData]);

    const handleEdit = (item: HargaPerkiraanItem) => {
        setEditingItem(item);
        setEditModalOpen(true);
    };

    const handleUpdateSuccess = (updated: HargaPerkiraanItem) => {
        setItems((prev) =>
            prev.map((it) => (it.id === updated.id ? { ...it, ...updated } : it)),
        );
    };

    const handleRefresh = () => {
        fetchData(meta.current_page);
        toast.info('Data harga perkiraan diperbarui.');
    };

    return (
        <div className="space-y-6 p-6">
            {/* Breadcrumb & Header Title */}
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <div className="text-muted-foreground flex items-center gap-2 text-sm font-medium">
                        <span>Pengadaan</span>
                        <span>/</span>
                        <span>Master Data</span>
                        <span>/</span>
                        <span className="text-foreground font-semibold">
                            4. Harga Perkiraan (HPS)
                        </span>
                    </div>
                    <div className="mt-1 flex items-center gap-2">
                        <Calculator className="h-6 w-6 text-primary" />
                        <h1 className="text-2xl font-bold tracking-tight text-foreground">
                            Harga Perkiraan
                        </h1>
                    </div>
                    <p className="text-muted-foreground text-xs mt-0.5">
                        Daftar acuan estimasi harga satuan bahan, upah, asset, subkontraktor, dan biaya overhead.
                    </p>
                </div>

                <div className="flex items-center gap-2 self-start sm:self-auto">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={handleRefresh}
                        disabled={loading}
                        className="h-8 gap-1.5 text-xs font-medium"
                    >
                        <RefreshCw
                            className={`h-3.5 w-3.5 ${
                                loading ? 'animate-spin' : ''
                            }`}
                        />
                        Refresh
                    </Button>
                </div>
            </div>

            {/* Container Card Tabel Harga Perkiraan */}
            <Card className="border shadow-sm">
                <CardHeader className="p-4 border-b bg-card">
                    <div className="flex items-center justify-between gap-4">
                        <div className="relative flex-1 max-w-sm">
                            <div className="absolute inset-y-0 left-0 pl-2.5 flex items-center pointer-events-none text-muted-foreground">
                                <Search className="h-3.5 w-3.5" />
                            </div>
                            <Input
                                placeholder="Pencarian harga perkiraan..."
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
                    {/* Header Strip Hijau: "Daftar Harga Perkiraan" Sesuai Mockup */}
                    <div className="px-4 py-2 bg-emerald-50 dark:bg-emerald-950/30 border-b border-emerald-200/60 dark:border-emerald-900/40 flex items-center justify-between">
                        <div className="inline-flex items-center gap-1.5 px-3 py-1 rounded bg-emerald-100/90 dark:bg-emerald-900/50 text-emerald-900 dark:text-emerald-200 border border-emerald-300/80 dark:border-emerald-700/60 text-xs font-bold tracking-wide">
                            <span>Daftar Harga Perkiraan</span>
                        </div>
                        <span className="text-xs text-muted-foreground font-medium">
                            Total: {meta.total} Item Harga Perkiraan
                        </span>
                    </div>

                    {/* Tabel Daftar Harga Perkiraan (9 Kolom Persis Mockup Gambar) */}
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader className="bg-blue-50/70 dark:bg-slate-900/80 border-b">
                                <TableRow className="hover:bg-transparent">
                                    <TableHead className="w-12 text-center font-bold text-foreground border-r text-xs">
                                        No.
                                    </TableHead>
                                    <TableHead className="w-28 text-center font-bold text-foreground border-r text-xs">
                                        Group Item
                                    </TableHead>
                                    <TableHead className="w-32 text-center font-bold text-foreground border-r text-xs">
                                        Jenis Item
                                    </TableHead>
                                    <TableHead className="w-28 text-center font-bold text-foreground border-r text-xs">
                                        Kode Item
                                    </TableHead>
                                    <TableHead className="min-w-[160px] text-center font-bold text-foreground border-r text-xs">
                                        Nama Item
                                    </TableHead>
                                    <TableHead className="w-24 text-center font-bold text-foreground border-r text-xs">
                                        Satuan
                                    </TableHead>
                                    <TableHead className="w-36 text-center font-bold text-foreground border-r text-xs">
                                        Harga Per Satuan
                                    </TableHead>
                                    <TableHead className="w-36 text-center font-bold text-foreground border-r text-xs">
                                        Tgl Update
                                    </TableHead>
                                    <TableHead className="w-32 text-center font-bold text-foreground text-xs">
                                        Aksi
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
                                            Memuat daftar harga perkiraan...
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
                                                        Belum Ada Data Harga Perkiraan
                                                    </EmptyTitle>
                                                    <EmptyDescription>
                                                        Item barang dan jasa yang masuk pengadaan akan ditampilkan di sini beserta nilai estimasi HPS.
                                                    </EmptyDescription>
                                                </EmptyHeader>
                                            </Empty>
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    items.map((row, idx) => (
                                        <TableRow
                                            key={row.id}
                                            className="hover:bg-muted/50 cursor-pointer transition-colors border-b text-xs"
                                        >
                                            {/* 1. No */}
                                            <TableCell className="text-center font-medium text-muted-foreground border-r">
                                                {(meta.current_page - 1) *
                                                    meta.per_page +
                                                    idx +
                                                    1}
                                            </TableCell>

                                            {/* 2. Group Item */}
                                            <TableCell className="text-center font-medium text-foreground border-r">
                                                {row.group_item}
                                            </TableCell>

                                            {/* 3. Jenis Item */}
                                            <TableCell className="text-center text-muted-foreground border-r">
                                                {row.jenis_item}
                                            </TableCell>

                                            {/* 4. Kode Item */}
                                            <TableCell className="text-center font-mono font-medium text-foreground border-r">
                                                {row.kode_item}
                                            </TableCell>

                                            {/* 5. Nama Item */}
                                            <TableCell className="font-bold text-foreground border-r">
                                                {row.nama_item}
                                            </TableCell>

                                            {/* 6. Satuan */}
                                            <TableCell className="text-center text-muted-foreground border-r">
                                                {row.satuan}
                                            </TableCell>

                                            {/* 7. Harga Per Satuan */}
                                            <TableCell className="text-right font-mono font-bold text-foreground border-r px-4">
                                                {formatRupiah(row.harga_perkiraan)}
                                            </TableCell>

                                            {/* 8. Tgl Update */}
                                            <TableCell className="text-center text-muted-foreground border-r">
                                                {row.tanggal_update
                                                    ? formatIndonesianDate(row.tanggal_update)
                                                    : '-'}
                                            </TableCell>

                                            {/* 9. Aksi (Tombol Langsung) */}
                                            <TableCell className="text-center p-2 whitespace-nowrap">
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() => handleEdit(row)}
                                                    className="h-7 px-3 text-xs font-medium text-amber-700 bg-amber-50 hover:bg-amber-100 dark:bg-amber-950/50 dark:text-amber-300 border-amber-300 dark:border-amber-800"
                                                >
                                                    <Pencil className="mr-1 h-3.5 w-3.5" />
                                                    Edit
                                                </Button>
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

            {/* Modal Ubah Data Harga Perkiraan Sesuai Form Kanan Gambar */}
            <HargaPerkiraanEditModal
                open={editModalOpen}
                onOpenChange={setEditModalOpen}
                item={editingItem}
                onSuccess={handleUpdateSuccess}
            />
        </div>
    );
}
