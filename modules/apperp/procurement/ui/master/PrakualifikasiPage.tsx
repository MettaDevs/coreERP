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
import { Select } from '@apperp/ui/select';
import { api, toastSaveError } from '../api';
import { PrakualifikasiDetailModal } from './prakualifikasi/PrakualifikasiDetailModal';
import { PrakualifikasiFormModal } from './prakualifikasi/PrakualifikasiFormModal';
import type { PrakualifikasiFormData } from './prakualifikasi/PrakualifikasiFormModal';

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

export default function PrakualifikasiPage(_props?: PageProps) {
    void _props;
    const [items, setItems] = useState<PrakualifikasiRow[]>([]);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState('semua');

    const [meta, setMeta] = useState<PaginationMeta>({
        current_page: 1,
        last_page: 1,
        per_page: 20,
        total: 0,
    });

    // Form Modal (Tambah / Edit)
    const [formModalOpen, setFormModalOpen] = useState(false);
    const [editingItem, setEditingItem] =
        useState<PrakualifikasiFormData | null>(null);

    // Detail Modal (View)
    const [detailModalOpen, setDetailModalOpen] = useState(false);
    const [selectedItem, setSelectedItem] = useState<PrakualifikasiRow | null>(
        null,
    );

    // Delete / Archive Confirmation
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

                if (statusFilter !== 'semua') {
                    params.set('status_kualifikasi', statusFilter);
                }

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
                        per_page: 20,
                        total: res.data?.length || 0,
                    },
                );
            } catch (err) {
                toastSaveError(
                    err,
                    'Gagal memuat daftar prakualifikasi vendor.',
                );
            } finally {
                setLoading(false);
            }
        },
        [search, statusFilter],
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

    const handleEdit = (item: PrakualifikasiRow) => {
        setEditingItem(item);
        setFormModalOpen(true);
    };

    const handleView = (item: PrakualifikasiRow) => {
        setSelectedItem(item);
        setDetailModalOpen(true);
    };

    const handleSuccess = (saved: PrakualifikasiRow, isNew: boolean) => {
        if (isNew) {
            setItems((prev) => [saved, ...prev]);
            setMeta((prev) => ({ ...prev, total: prev.total + 1 }));
        } else {
            setItems((prev) =>
                prev.map((it) => (it.id === saved.id ? saved : it)),
            );
        }
    };

    const handleArchive = async () => {
        if (!deleteId) {
            return;
        }

        setDeleting(true);

        try {
            await api(`/prakualifikasi/${deleteId}`, {
                method: 'DELETE',
            });
            toast.success('Data prakualifikasi berhasil diarsipkan.');
            setItems((prev) => prev.filter((it) => it.id !== deleteId));
            setMeta((prev) => ({
                ...prev,
                total: Math.max(0, prev.total - 1),
            }));
            setDeleteId(null);
        } catch (err) {
            toastSaveError(err, 'Gagal menghapus data prakualifikasi.');
        } finally {
            setDeleting(false);
        }
    };

    const columns: DataTableColumn<PrakualifikasiRow>[] = useMemo(
        () => [
            {
                id: 'nomor_prakualifikasi',
                header: 'No. Dokumen',
                cell: (row) => (
                    <div className="space-y-0.5">
                        <span className="font-mono text-xs font-semibold">
                            {row.nomor_prakualifikasi}
                        </span>
                        <div className="text-muted-foreground text-[11px]">
                            {formatDisplayDate(row.tanggal_prakualifikasi)}
                        </div>
                    </div>
                ),
                width: 170,
            },
            {
                id: 'vendor',
                header: 'Vendor / Rekanan',
                cell: (row) => (
                    <div className="space-y-0.5">
                        <div className="text-xs font-semibold">
                            {row.nama_vendor}
                        </div>
                        <div className="text-muted-foreground font-mono text-[11px]">
                            {row.kode_vendor}
                        </div>
                    </div>
                ),
                width: 220,
            },
            {
                id: 'pic',
                header: 'Kontak / PIC',
                cell: (row) => (
                    <div className="space-y-0.5 text-xs">
                        <div>{row.nama_pic || '—'}</div>
                        <div className="text-muted-foreground font-mono text-[11px]">
                            {row.telepon || '—'}
                        </div>
                    </div>
                ),
                width: 160,
            },
            {
                id: 'alamat',
                header: 'Alamat',
                cell: (row) => (
                    <span className="text-muted-foreground line-clamp-2 text-xs">
                        {row.alamat || '—'}
                    </span>
                ),
                width: 240,
            },
            {
                id: 'status_vendor',
                header: 'Status Rekanan',
                cell: (row) => (
                    <Badge variant="secondary" className="text-[11px]">
                        {row.status_vendor || 'Vendor Resmi'}
                    </Badge>
                ),
                width: 140,
            },
            {
                id: 'status_kualifikasi',
                header: 'Hasil Evaluasi',
                cell: (row) => {
                    const status = row.status_kualifikasi || 'Lolos';
                    const isLolos = status.toLowerCase() === 'lolos';

                    return (
                        <Badge
                            variant={isLolos ? 'default' : 'destructive'}
                            className="text-[11px]"
                        >
                            {status}
                        </Badge>
                    );
                },
                width: 130,
            },
        ],
        [],
    );

    const rowActions: DataTableRowAction[] = useMemo(
        () => [
            { id: 'view', label: 'Lihat Detail' },
            { id: 'edit', label: 'Ubah' },
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
                    <CardTitle id="list-title">Prakualifikasi Vendor</CardTitle>
                    <CardAction>
                        <ActionButton action="create" onClick={handleCreate} />
                    </CardAction>
                </CardHeader>

                <CardContent className="flex min-h-0 flex-1 flex-col px-0">
                    <div className="flex shrink-0 flex-col gap-3 border-b px-5 py-3 sm:flex-row sm:items-end sm:justify-between">
                        <div className="space-y-1">
                            <p className="text-sm font-semibold">
                                Daftar Prakualifikasi
                            </p>
                            <p className="text-muted-foreground text-xs">
                                {meta.total} data dimuat
                            </p>
                        </div>
                        <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-center">
                            <Input
                                className="h-8 w-full text-xs sm:w-64"
                                type="search"
                                placeholder="Cari nomor, kode, atau vendor..."
                                aria-label="Cari prakualifikasi"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                            />
                            <div className="w-full sm:w-44">
                                <Select
                                    items={['Semua status', 'Lolos', 'Batal']}
                                    value={
                                        statusFilter === 'semua'
                                            ? 'Semua status'
                                            : statusFilter
                                    }
                                    searchPlaceholder="Cari status"
                                    emptyMessage="Status tidak ditemukan."
                                    ariaLabel="Saring berdasarkan status kualifikasi"
                                    onValueChange={(val) => {
                                        setStatusFilter(
                                            val === 'Semua status'
                                                ? 'semua'
                                                : val,
                                        );
                                    }}
                                />
                            </div>
                        </div>
                    </div>

                    {loading && !items.length ? (
                        <Empty>
                            <EmptyDescription>
                                Memuat data prakualifikasi…
                            </EmptyDescription>
                        </Empty>
                    ) : items.length === 0 ? (
                        <Empty>
                            <EmptyHeader>
                                <EmptyTitle>
                                    Belum ada data prakualifikasi
                                </EmptyTitle>
                                <EmptyDescription>
                                    Pilih Baru untuk memulai proses
                                    prakualifikasi vendor pertama.
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
                            actions={rowActions}
                            onRowAction={(actionId, row) => {
                                if (actionId === 'view') {
                                    handleView(row);
                                }

                                if (actionId === 'edit') {
                                    handleEdit(row);
                                }

                                if (actionId === 'archive') {
                                    setDeleteId(row.id);
                                }
                            }}
                        />
                    )}
                </CardContent>
            </Card>

            {/* Modal Form Tambah / Ubah */}
            <PrakualifikasiFormModal
                open={formModalOpen}
                onOpenChange={setFormModalOpen}
                initialData={editingItem}
                onSuccess={handleSuccess}
            />

            {/* Modal Detail */}
            <PrakualifikasiDetailModal
                open={detailModalOpen}
                onOpenChange={setDetailModalOpen}
                item={selectedItem}
                onEdit={handleEdit}
            />

            {/* Dialog Konfirmasi Hapus */}
            <AlertDialog
                open={Boolean(deleteId)}
                onOpenChange={(open) => !open && setDeleteId(null)}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Hapus Data Prakualifikasi?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            Apakah Anda yakin ingin menghapus data
                            prakualifikasi ini? Tindakan ini akan mengarsipkan
                            dokumen dan data tidak akan tampil di transaksi
                            baru.
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
                            {deleting ? 'Menghapus…' : 'Hapus'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
}
