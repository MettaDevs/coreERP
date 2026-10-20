import { Calculator, Pencil, RefreshCw, Search, X } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@apperp/ui/button';
import { Card, CardContent, CardHeader } from '@apperp/ui/card';
import { DataTable } from '@apperp/ui/data-table';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@apperp/ui/empty';
import { Input } from '@apperp/ui/input';
import { TableCell, TableRow } from '@apperp/ui/table';
import { api, toastSaveError } from '../api';
import {
    HargaPerkiraanEditModal,
    formatIndonesianDate,
    formatRupiah,
} from './harga-perkiraan/HargaPerkiraanEditModal';
import type { HargaPerkiraanItem } from './harga-perkiraan/HargaPerkiraanEditModal';

const hargaPerkiraanColumns = [
    { id: 'no', header: 'No.', cell: () => null },
    { id: 'group_item', header: 'Group Item', cell: () => null },
    { id: 'jenis_item', header: 'Jenis Item', cell: () => null },
    { id: 'kode_item', header: 'Kode Item', cell: () => null },
    { id: 'nama_item', header: 'Nama Item', cell: () => null },
    { id: 'satuan', header: 'Satuan', cell: () => null },
    { id: 'harga_perkiraan', header: 'Harga Per Satuan', cell: () => null },
    { id: 'tanggal_update', header: 'Tgl Update', cell: () => null },
    { id: 'aksi', header: 'Aksi', cell: () => null },
];

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

export default function HargaPerkiraanPage(_props?: PageProps) {
    void _props;
    const [items, setItems] = useState<HargaPerkiraanItem[]>([]);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');

    const [meta, setMeta] = useState<PaginationMeta>({
        current_page: 1,
        last_page: 1,
        per_page: 15,
        total: 0,
    });

    // Edit Modal State
    const [editModalOpen, setEditModalOpen] = useState(false);
    const [editingItem, setEditingItem] = useState<HargaPerkiraanItem | null>(
        null,
    );

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
        [search],
    );

    useEffect(() => {
        const timer = setTimeout(() => {
            void fetchData(1);
        }, 0);

        return () => clearTimeout(timer);
    }, [fetchData]);

    const handleEdit = (item: HargaPerkiraanItem) => {
        setEditingItem(item);
        setEditModalOpen(true);
    };

    const handleUpdateSuccess = (updated: HargaPerkiraanItem) => {
        setItems((prev) =>
            prev.map((it) =>
                it.id === updated.id ? { ...it, ...updated } : it,
            ),
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
                        <Calculator className="text-primary h-6 w-6" />
                        <h1 className="text-foreground text-2xl font-bold tracking-tight">
                            Harga Perkiraan
                        </h1>
                    </div>
                    <p className="text-muted-foreground mt-0.5 text-xs">
                        Daftar acuan estimasi harga satuan bahan, upah, asset,
                        subkontraktor, dan biaya overhead.
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
                <CardHeader className="bg-card border-b p-4">
                    <div className="flex items-center justify-between gap-4">
                        <div className="relative max-w-sm flex-1">
                            <div className="text-muted-foreground pointer-events-none absolute inset-y-0 left-0 flex items-center pl-2.5">
                                <Search className="h-3.5 w-3.5" />
                            </div>
                            <Input
                                placeholder="Pencarian harga perkiraan..."
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                className="bg-background border-input h-8 pl-8 pr-7 text-xs"
                            />
                            {search && (
                                <button
                                    type="button"
                                    onClick={() => setSearch('')}
                                    className="text-muted-foreground hover:text-foreground absolute inset-y-0 right-0 flex items-center pr-2"
                                >
                                    <X className="h-3.5 w-3.5" />
                                </button>
                            )}
                        </div>
                    </div>
                </CardHeader>

                <CardContent className="p-0">
                    {/* Header Strip Hijau: "Daftar Harga Perkiraan" Sesuai Mockup */}
                    <div className="flex items-center justify-between border-b border-emerald-200/60 bg-emerald-50 px-4 py-2 dark:border-emerald-900/40 dark:bg-emerald-950/30">
                        <div className="inline-flex items-center gap-1.5 rounded border border-emerald-300/80 bg-emerald-100/90 px-3 py-1 text-xs font-bold tracking-wide text-emerald-900 dark:border-emerald-700/60 dark:bg-emerald-900/50 dark:text-emerald-200">
                            <span>Daftar Harga Perkiraan</span>
                        </div>
                        <span className="text-muted-foreground text-xs font-medium">
                            Total: {meta.total} Item Harga Perkiraan
                        </span>
                    </div>

                    {/* Tabel Daftar Harga Perkiraan (9 Kolom Persis Mockup Gambar) */}
                    <DataTable
                        scrollMode="inline"
                        showRowNumbers={false}
                        columns={hargaPerkiraanColumns}
                        data={items}
                        getRowKey={(row) => row.id}
                        renderRows={() => (
                            <>
                                {loading ? (
                                    <TableRow>
                                        <TableCell
                                            colSpan={9}
                                            className="text-muted-foreground py-12 text-center text-sm"
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
                                                        Belum Ada Data Harga
                                                        Perkiraan
                                                    </EmptyTitle>
                                                    <EmptyDescription>
                                                        Item barang dan jasa
                                                        yang masuk pengadaan
                                                        akan ditampilkan di sini
                                                        beserta nilai estimasi
                                                        HPS.
                                                    </EmptyDescription>
                                                </EmptyHeader>
                                            </Empty>
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    items.map((row, idx) => (
                                        <TableRow
                                            key={row.id}
                                            className="hover:bg-muted/50 cursor-pointer border-b text-xs transition-colors"
                                        >
                                            {/* 1. No */}
                                            <TableCell className="text-muted-foreground border-r text-center font-medium">
                                                {(meta.current_page - 1) *
                                                    meta.per_page +
                                                    idx +
                                                    1}
                                            </TableCell>

                                            {/* 2. Group Item */}
                                            <TableCell className="text-foreground border-r text-center font-medium">
                                                {row.group_item}
                                            </TableCell>

                                            {/* 3. Jenis Item */}
                                            <TableCell className="text-muted-foreground border-r text-center">
                                                {row.jenis_item}
                                            </TableCell>

                                            {/* 4. Kode Item */}
                                            <TableCell className="text-foreground border-r text-center font-mono font-medium">
                                                {row.kode_item}
                                            </TableCell>

                                            {/* 5. Nama Item */}
                                            <TableCell className="text-foreground border-r font-bold">
                                                {row.nama_item}
                                            </TableCell>

                                            {/* 6. Satuan */}
                                            <TableCell className="text-muted-foreground border-r text-center">
                                                {row.satuan}
                                            </TableCell>

                                            {/* 7. Harga Per Satuan */}
                                            <TableCell className="text-foreground border-r px-4 text-right font-mono font-bold">
                                                {formatRupiah(
                                                    row.harga_perkiraan,
                                                )}
                                            </TableCell>

                                            {/* 8. Tgl Update */}
                                            <TableCell className="text-muted-foreground border-r text-center">
                                                {row.tanggal_update
                                                    ? formatIndonesianDate(
                                                          row.tanggal_update,
                                                      )
                                                    : '-'}
                                            </TableCell>

                                            {/* 9. Aksi (Tombol Langsung) */}
                                            <TableCell className="whitespace-nowrap p-2 text-center">
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() =>
                                                        handleEdit(row)
                                                    }
                                                    className="h-7 border-amber-300 bg-amber-50 px-3 text-xs font-medium text-amber-700 hover:bg-amber-100 dark:border-amber-800 dark:bg-amber-950/50 dark:text-amber-300"
                                                >
                                                    <Pencil className="mr-1 h-3.5 w-3.5" />
                                                    Edit
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </>
                        )}
                    />

                    {/* Pagination Bar */}
                    {meta.last_page > 1 && (
                        <div className="flex items-center justify-between border-t px-4 py-3">
                            <span className="text-muted-foreground text-xs">
                                Halaman {meta.current_page} dari{' '}
                                {meta.last_page} (Total: {meta.total})
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
