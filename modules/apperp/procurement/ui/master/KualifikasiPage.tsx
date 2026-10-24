import { useCallback, useEffect, useMemo, useState } from 'react';
import { Badge } from '@apperp/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@apperp/ui/card';
import { DataTable } from '@apperp/ui/data-table';
import type { DataTableColumn } from '@apperp/ui/data-table';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@apperp/ui/empty';
import { Input } from '@apperp/ui/input';
import { api, toastSaveError } from '../api';
import { PrakualifikasiDetailModal } from './prakualifikasi/PrakualifikasiDetailModal';
import type { PrakualifikasiFormData } from './prakualifikasi/PrakualifikasiFormModal';

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
    if (!dateStr) {
        return '—';
    }

    if (dateStr.includes('/')) {
        return dateStr;
    }

    const parts = dateStr.split('-');

    if (parts.length === 3) {
        return `${parts[2]}/${parts[1]}/${parts[0]}`;
    }

    return dateStr;
}

type PageProps = {
    view?: string;
    segments?: string[];
    permissions?: string[];
};

export default function KualifikasiPage(_props?: PageProps) {
    void _props;
    const [items, setItems] = useState<KualifikasiRow[]>([]);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');

    const [meta, setMeta] = useState<PaginationMeta>({
        current_page: 1,
        last_page: 1,
        per_page: 20,
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

                if (search.trim()) {
                    params.set('search', search.trim());
                }

                params.set('status_kualifikasi', 'Lolos');

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
                        per_page: 20,
                        total: res.data?.length || 0,
                    },
                );
            } catch (err) {
                toastSaveError(
                    err,
                    'Gagal memuat daftar rekanan lolos kualifikasi.',
                );
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

    const handleView = (item: KualifikasiRow) => {
        setViewingItem(item);
        setDetailOpen(true);
    };

    const columns: DataTableColumn<KualifikasiRow>[] = useMemo(
        () => [
            {
                id: 'info_prakualifikasi',
                header: 'Info Prakualifikasi',
                cell: (row) => (
                    <div className="space-y-0.5">
                        <div className="text-muted-foreground text-xs font-medium">
                            {formatDisplayDate(row.tanggal_prakualifikasi)}
                        </div>
                        <span className="font-mono text-xs font-semibold">
                            {row.nomor_prakualifikasi}
                        </span>
                    </div>
                ),
                width: 180,
            },
            {
                id: 'kode_vendor',
                header: 'Kode Vendor/Supplier',
                cell: (row) => (
                    <span className="font-mono text-xs font-semibold">
                        {row.kode_vendor}
                    </span>
                ),
                width: 160,
            },
            {
                id: 'nama_vendor',
                header: 'Nama Vendor/Supplier',
                cell: (row) => (
                    <span className="text-xs font-semibold">
                        {row.nama_vendor}
                    </span>
                ),
                width: 220,
            },
            {
                id: 'nama_pic',
                header: 'Contact Person',
                cell: (row) => (
                    <span className="text-xs">{row.nama_pic || '—'}</span>
                ),
                width: 150,
            },
            {
                id: 'telepon',
                header: 'Telp/HP',
                cell: (row) => (
                    <span className="font-mono text-xs">
                        {row.telepon || '—'}
                    </span>
                ),
                width: 150,
            },
            {
                id: 'alamat',
                header: 'Alamat Vendor/Supplier',
                cell: (row) => (
                    <span className="text-muted-foreground line-clamp-2 text-xs">
                        {row.alamat || '—'}
                    </span>
                ),
                width: 240,
            },
            {
                id: 'status_vendor',
                header: 'Status Vendor/Supplier',
                cell: (row) => (
                    <Badge variant="secondary" className="text-[11px]">
                        {row.status_vendor || 'Vendor Resmi'}
                    </Badge>
                ),
                width: 160,
            },
            {
                id: 'status_kualifikasi',
                header: 'Status Kualifikasi',
                cell: (row) => (
                    <Badge variant="default" className="text-[11px]">
                        {row.status_kualifikasi || 'Lolos'}
                    </Badge>
                ),
                width: 140,
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
                    <CardTitle id="list-title">Kualifikasi Vendor</CardTitle>
                </CardHeader>

                <CardContent className="flex min-h-0 flex-1 flex-col px-0">
                    <div className="flex shrink-0 flex-col gap-3 border-b px-5 py-3 sm:flex-row sm:items-end sm:justify-between">
                        <div className="space-y-1">
                            <p className="text-sm font-semibold">
                                Daftar Rekanan Terkualifikasi
                            </p>
                            <p className="text-muted-foreground text-xs">
                                {meta.total} rekanan lolos
                            </p>
                        </div>
                        <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-center">
                            <Input
                                className="h-8 w-full text-xs sm:w-64"
                                type="search"
                                placeholder="Cari nomor, kode, atau vendor..."
                                aria-label="Cari rekanan terkualifikasi"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                            />
                        </div>
                    </div>

                    {loading && !items.length ? (
                        <Empty>
                            <EmptyDescription>
                                Memuat daftar rekanan terkualifikasi…
                            </EmptyDescription>
                        </Empty>
                    ) : items.length === 0 ? (
                        <Empty>
                            <EmptyHeader>
                                <EmptyTitle>
                                    Belum ada rekanan lolos kualifikasi
                                </EmptyTitle>
                                <EmptyDescription>
                                    Rekanan akan otomatis terdaftar di sini
                                    setelah dievaluasi pada menu Prakualifikasi
                                    dengan status Lolos.
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
                            getRowLabel={(row) => row.nomor_prakualifikasi}
                            onRowClick={(row) => handleView(row)}
                        />
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
