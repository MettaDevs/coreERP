import { useCallback, useEffect, useMemo, useState } from "react";
import { Button } from "@apperp/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@apperp/ui/card";
import { DataTable } from "@apperp/ui/data-table";
import type {
    DataTableColumn,
    DataTableRowAction,
} from "@apperp/ui/data-table";
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from "@apperp/ui/empty";
import { Input } from "@apperp/ui/input";
import { Pencil, RefreshCw } from "lucide-react";
import { api, toastSaveError } from "../api";
import {
    HargaPerkiraanEditModal,
    formatIndonesianDate,
    formatRupiah,
} from "./harga-perkiraan/HargaPerkiraanEditModal";
import type { HargaPerkiraanItem } from "./harga-perkiraan/HargaPerkiraanEditModal";

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
    const [search, setSearch] = useState("");

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
                params.set("page", String(page));

                if (search.trim()) {
                    params.set("search", search.trim());
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
                toastSaveError(err, "Gagal memuat data harga perkiraan.");
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
                id: "group_item",
                header: "Group Item",
                cell: (row) => (
                    <span className="text-xs font-medium">
                        {row.group_item}
                    </span>
                ),
                width: 140,
            },
            {
                id: "jenis_item",
                header: "Jenis Item",
                cell: (row) => (
                    <span className="text-xs">{row.jenis_item}</span>
                ),
                width: 140,
            },
            {
                id: "kode_item",
                header: "Kode Item",
                cell: (row) => (
                    <span className="font-mono text-xs font-semibold">
                        {row.kode_item}
                    </span>
                ),
                width: 140,
            },
            {
                id: "nama_item",
                header: "Nama Item",
                cell: (row) => (
                    <span className="text-xs font-semibold">
                        {row.nama_item}
                    </span>
                ),
                width: 180,
            },
            {
                id: "satuan",
                header: "Satuan",
                cell: (row) => <span className="text-xs">{row.satuan}</span>,
                width: 100,
            },
            {
                id: "harga_perkiraan",
                header: "Harga Per Satuan",
                cell: (row) => (
                    <span className="font-mono text-xs font-semibold">
                        {formatRupiah(row.harga_perkiraan)}
                    </span>
                ),
                width: 160,
            },
            {
                id: "tanggal_update",
                header: "Tgl Update",
                cell: (row) => (
                    <span className="text-muted-foreground text-xs">
                        {row.tanggal_update
                            ? formatIndonesianDate(row.tanggal_update)
                            : "—"}
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
                id: "edit",
                label: "Edit",
                icon: <Pencil className="size-3.5" />,
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
                    <CardTitle id="list-title">Harga Perkiraan</CardTitle>
                </CardHeader>

                <CardContent className="flex min-h-0 flex-1 flex-col px-0">
                    <div className="flex shrink-0 flex-col gap-3 border-b px-5 py-3 sm:flex-row sm:items-center sm:justify-between">
                        <div className="space-y-0.5">
                            <p className="text-sm font-semibold">
                                Daftar Harga Perkiraan
                            </p>
                            <p className="text-muted-foreground text-xs">
                                {meta.total} data
                            </p>
                        </div>
                        <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-center">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    void fetchData(meta.current_page)
                                }
                                disabled={loading}
                                className="h-8 gap-1.5 text-xs"
                            >
                                <RefreshCw
                                    className={
                                        loading
                                            ? "size-3.5 animate-spin"
                                            : "size-3.5"
                                    }
                                />
                                Refresh
                            </Button>
                            <Input
                                className="h-8 w-full text-xs sm:w-64"
                                type="search"
                                placeholder="Pencarian..."
                                aria-label="Pencarian harga perkiraan"
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
                            showRowNumbers
                            columns={columns}
                            data={items}
                            getRowKey={(row) => row.id}
                            getRowLabel={(row) => row.nama_item}
                            actionsPlacement="trailing"
                            actions={rowActions}
                            onRowAction={(actionId, row) => {
                                if (actionId === "edit") {
                                    handleEdit(row);
                                }
                            }}
                            onRowClick={(row) => handleEdit(row)}
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
