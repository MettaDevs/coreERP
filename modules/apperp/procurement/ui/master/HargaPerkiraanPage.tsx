import { useCallback, useEffect, useMemo, useState } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '@apperp/ui/card';
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
import {
    HargaPerkiraanEditModal,
    formatIndonesianDate,
    formatRupiah,
} from './harga-perkiraan/HargaPerkiraanEditModal';
import type { HargaPerkiraanItem } from './harga-perkiraan/HargaPerkiraanEditModal';

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
        per_page: 20,
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
                        per_page: 20,
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

    const handleUpdateSuccess = (updatedItem: HargaPerkiraanItem) => {
        setItems((prev) =>
            prev.map((it) => (it.id === updatedItem.id ? updatedItem : it)),
        );
    };

    const columns: DataTableColumn<HargaPerkiraanItem>[] = useMemo(
        () => [
            {
                id: 'item',
                header: 'Item Barang / Jasa',
                cell: (row) => (
                    <div className="space-y-0.5">
                        <div className="text-xs font-semibold">
                            {row.nama_item}
                        </div>
                        <div className="text-muted-foreground font-mono text-[11px]">
                            {row.kode_item}
                        </div>
                    </div>
                ),
                width: 250,
            },
            {
                id: 'group_item',
                header: 'Group & Jenis Item',
                cell: (row) => (
                    <div className="space-y-0.5 text-xs">
                        <div className="font-medium">{row.group_item}</div>
                        <div className="text-muted-foreground text-[11px]">
                            {row.jenis_item}
                        </div>
                    </div>
                ),
                width: 180,
            },
            {
                id: 'satuan',
                header: 'Satuan',
                cell: (row) => (
                    <span className="text-muted-foreground text-xs">
                        {row.satuan}
                    </span>
                ),
                width: 100,
            },
            {
                id: 'harga_perkiraan',
                header: 'Harga Estimasi (HPS)',
                cell: (row) => (
                    <span className="font-mono text-xs font-bold">
                        {formatRupiah(row.harga_perkiraan)}
                    </span>
                ),
                width: 180,
            },
            {
                id: 'tanggal_update',
                header: 'Tgl Pembaruan',
                cell: (row) => (
                    <span className="text-muted-foreground text-xs">
                        {row.tanggal_update
                            ? formatIndonesianDate(row.tanggal_update)
                            : '—'}
                    </span>
                ),
                width: 150,
            },
        ],
        [],
    );

    const rowActions: DataTableRowAction[] = useMemo(
        () => [{ id: 'edit', label: 'Ubah' }],
        [],
    );

    return (
        <div className="h-full min-h-0 overflow-hidden">
            <Card
                aria-labelledby="list-title"
                className="flex h-full min-h-0 flex-col overflow-hidden rounded-none border-0 shadow-none"
            >
                <CardHeader className="min-h-0 shrink-0 border-b">
                    <CardTitle id="list-title">Harga Perkiraan</CardTitle>
                </CardHeader>

                <CardContent className="flex min-h-0 flex-1 flex-col px-0">
                    <div className="flex shrink-0 flex-col gap-3 border-b px-5 py-3 sm:flex-row sm:items-end sm:justify-between">
                        <div className="space-y-1">
                            <p className="text-sm font-semibold">
                                Daftar Harga Perkiraan Sendiri (HPS)
                            </p>
                            <p className="text-muted-foreground text-xs">
                                {meta.total} data dimuat
                            </p>
                        </div>
                        <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-center">
                            <Input
                                className="h-8 w-full text-xs sm:w-64"
                                type="search"
                                placeholder="Cari item, kode, atau group..."
                                aria-label="Cari harga perkiraan"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                            />
                        </div>
                    </div>

                    {loading && !items.length ? (
                        <Empty>
                            <EmptyDescription>
                                Memuat data harga perkiraan…
                            </EmptyDescription>
                        </Empty>
                    ) : items.length === 0 ? (
                        <Empty>
                            <EmptyHeader>
                                <EmptyTitle>
                                    Belum ada data harga perkiraan
                                </EmptyTitle>
                                <EmptyDescription>
                                    Item barang dan jasa yang masuk pengadaan
                                    akan ditampilkan di sini beserta nilai
                                    estimasi HPS.
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
                            getRowLabel={(row) => row.nama_item}
                            actions={rowActions}
                            onRowAction={(actionId, row) => {
                                if (actionId === 'edit') {
                                    handleEdit(row);
                                }
                            }}
                        />
                    )}
                </CardContent>
            </Card>

            {/* Modal Ubah Data Harga Perkiraan */}
            <HargaPerkiraanEditModal
                open={editModalOpen}
                onOpenChange={setEditModalOpen}
                item={editingItem}
                onSuccess={handleUpdateSuccess}
            />
        </div>
    );
}
