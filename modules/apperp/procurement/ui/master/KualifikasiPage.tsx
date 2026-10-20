import { useCallback, useEffect, useState } from 'react';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import { Card, CardContent, CardHeader } from '@apperp/ui/card';
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
    Award,
    CheckCircle2,
    Eye,
    MoreHorizontal,
    Printer,
    RefreshCw,
    Search,
    ShieldCheck,
    X,
} from 'lucide-react';
import { toast } from 'sonner';
import { api, toastSaveError } from '../api';
import type { PrakualifikasiFormData } from './prakualifikasi/PrakualifikasiFormModal';
import { PrakualifikasiDetailModal } from './prakualifikasi/PrakualifikasiDetailModal';

type PageProps = {
    view: string;
    segments?: string[];
    permissions?: string[];
};

type KualifikasiRow = PrakualifikasiFormData & {
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

export default function KualifikasiPage({
    view: _view,
    segments: _segments,
    permissions: _permissions,
}: PageProps) {
    const [items, setItems] = useState<KualifikasiRow[]>([]);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');

    const [meta, setMeta] = useState<PaginationMeta>({
        current_page: 1,
        last_page: 1,
        per_page: 15,
        total: 0,
    });

    // Detail Modal
    const [detailOpen, setDetailOpen] = useState(false);
    const [viewingItem, setViewingItem] = useState<KualifikasiRow | null>(null);

    const fetchData = useCallback(
        async (page = 1) => {
            setLoading(true);
            try {
                const params = new URLSearchParams();
                params.set('page', String(page));
                if (search.trim()) params.set('search', search.trim());

                const res = await api<{
                    status: string;
                    data: KualifikasiRow[];
                    meta: PaginationMeta;
                }>(`/kualifikasi?${params.toString()}`);

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
                toastSaveError(err, 'Gagal memuat daftar kualifikasi rekanan.');
            } finally {
                setLoading(false);
            }
        },
        [search],
    );

    useEffect(() => {
        fetchData(1);
    }, [fetchData]);

    const handleView = (item: KualifikasiRow) => {
        setViewingItem(item);
        setDetailOpen(true);
    };

    const handleRefresh = () => {
        fetchData(meta.current_page);
        toast.info('Data kualifikasi vendor diperbarui.');
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
                            3. Kualifikasi
                        </span>
                    </div>
                    <div className="mt-1 flex items-center gap-2">
                        <Award className="text-primary h-6 w-6" />
                        <h1 className="text-foreground text-2xl font-bold tracking-tight">
                            Kualifikasi Vendor
                        </h1>
                    </div>
                    <p className="text-muted-foreground mt-0.5 text-xs">
                        Daftar rekanan/supplier resmi yang telah diverifikasi
                        dan dinyatakan memenuhi syarat kualifikasi.
                    </p>
                </div>

                {/* Tombol Aksi: Refresh */}
                <div className="flex flex-wrap items-center gap-2">
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

            {/* Container Card Tabel Kualifikasi */}
            <Card className="border shadow-sm">
                <CardHeader className="bg-card border-b p-4">
                    {/* Baris Pencarian Standar */}
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div className="relative min-w-[200px] max-w-xs">
                            <div className="text-muted-foreground pointer-events-none absolute inset-y-0 left-0 flex items-center pl-2.5">
                                <Search className="h-3.5 w-3.5" />
                            </div>
                            <Input
                                placeholder="Pencarian..."
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
                    {/* Header Strip: "Daftar Kualifikasi Vendor/Supplier" */}
                    <div className="bg-muted/40 flex items-center justify-between border-b px-4 py-2">
                        <span className="text-foreground text-xs font-semibold">
                            Daftar Kualifikasi Vendor/Supplier
                        </span>
                        <span className="text-muted-foreground text-xs font-medium">
                            Total: {meta.total} Rekanan Terkualifikasi
                        </span>
                    </div>

                    {/* Tabel Daftar Kualifikasi Vendor (9 Kolom) */}
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader className="bg-muted/40 border-b">
                                <TableRow className="hover:bg-transparent">
                                    <TableHead className="text-foreground w-12 border-r text-center text-xs font-bold">
                                        No.
                                    </TableHead>
                                    <TableHead className="text-foreground w-44 border-r text-center text-xs font-bold">
                                        Info Prakualifikasi
                                    </TableHead>
                                    <TableHead className="text-foreground w-36 border-r text-center text-xs font-bold">
                                        Kode Vendor/Supplier
                                    </TableHead>
                                    <TableHead className="text-foreground min-w-[180px] border-r text-center text-xs font-bold">
                                        Nama Vendor/Supplier
                                    </TableHead>
                                    <TableHead className="text-foreground w-36 border-r text-center text-xs font-bold">
                                        Contact Person
                                    </TableHead>
                                    <TableHead className="text-foreground w-36 border-r text-center text-xs font-bold">
                                        Telp/HP
                                    </TableHead>
                                    <TableHead className="text-foreground min-w-[200px] border-r text-center text-xs font-bold">
                                        Alamat Vendor/Supplier
                                    </TableHead>
                                    <TableHead className="text-foreground w-36 border-r text-center text-xs font-bold">
                                        Status Vendor/Supplier
                                    </TableHead>
                                    <TableHead className="text-foreground w-36 text-center text-xs font-bold">
                                        Status Kualifikasi
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {loading ? (
                                    <TableRow>
                                        <TableCell
                                            colSpan={9}
                                            className="text-muted-foreground py-12 text-center text-sm"
                                        >
                                            Memuat daftar kualifikasi rekanan...
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
                                                        Belum Ada Vendor Lolos
                                                        Kualifikasi
                                                    </EmptyTitle>
                                                    <EmptyDescription>
                                                        Rekanan akan otomatis
                                                        terdaftar di sini
                                                        setelah melalui proses
                                                        evaluasi pada menu{' '}
                                                        <strong>
                                                            2. Prakualifikasi
                                                        </strong>{' '}
                                                        dengan status
                                                        kualifikasi{' '}
                                                        <strong>Lolos</strong>.
                                                    </EmptyDescription>
                                                </EmptyHeader>
                                            </Empty>
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    items.map((row, idx) => (
                                        <TableRow
                                            key={row.id}
                                            className="hover:bg-muted/50 border-b text-xs transition-colors"
                                        >
                                            {/* 1. No */}
                                            <TableCell className="text-muted-foreground border-r text-center font-medium">
                                                {(meta.current_page - 1) *
                                                    meta.per_page +
                                                    idx +
                                                    1}
                                            </TableCell>

                                            {/* 2. Info Prakualifikasi (Tanggal & No Prakualifikasi) */}
                                            <TableCell className="border-r text-center font-mono">
                                                <div className="text-foreground font-medium">
                                                    {formatDisplayDate(
                                                        row.tanggal_prakualifikasi,
                                                    )}
                                                </div>
                                                <div
                                                    className="text-primary mt-0.5 cursor-pointer font-semibold hover:underline"
                                                    onClick={() =>
                                                        handleView(row)
                                                    }
                                                >
                                                    {row.nomor_prakualifikasi}
                                                </div>
                                            </TableCell>

                                            {/* 3. Kode Vendor/Supplier */}
                                            <TableCell className="border-r text-center font-mono font-medium">
                                                {row.kode_vendor}
                                            </TableCell>

                                            {/* 4. Nama Vendor/Supplier */}
                                            <TableCell className="text-foreground border-r font-bold">
                                                {row.nama_vendor}
                                            </TableCell>

                                            {/* 5. Contact Person */}
                                            <TableCell className="border-r text-center">
                                                {row.nama_pic || '-'}
                                            </TableCell>

                                            {/* 6. Telp/HP */}
                                            <TableCell className="border-r text-center font-mono">
                                                {row.telepon || '-'}
                                            </TableCell>

                                            {/* 7. Alamat Vendor/Supplier */}
                                            <TableCell className="max-w-xs border-r">
                                                {row.alamat || '-'}
                                            </TableCell>

                                            {/* 8. Status Vendor/Supplier */}
                                            <TableCell className="text-foreground border-r text-center font-medium">
                                                {row.status_vendor ||
                                                    'Vendor Resmi'}
                                            </TableCell>

                                            {/* 9. Status Kualifikasi */}
                                            <TableCell className="text-center">
                                                <span className="inline-flex items-center rounded border border-emerald-300 bg-emerald-100 px-2.5 py-0.5 text-xs font-semibold text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300">
                                                    <CheckCircle2 className="mr-1 h-3.5 w-3.5 text-emerald-600 dark:text-emerald-400" />
                                                    Lolos
                                                </span>
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </div>

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

            {/* Modal Detail Rekanan Kualifikasi */}
            <PrakualifikasiDetailModal
                open={detailOpen}
                onOpenChange={setDetailOpen}
                data={viewingItem}
            />
        </div>
    );
}
