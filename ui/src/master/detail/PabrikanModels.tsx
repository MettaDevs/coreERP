import { useCallback, useEffect, useState } from 'react';
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
import { Button } from '@apperp/ui/button';
import { DataTable, type DataTableColumn, type DataTableRowAction } from '@apperp/ui/data-table';
import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@apperp/ui/empty';
import { api, errorMessage } from '../../api';
import { MasterRecord, ParentSummary } from '../masters';
import ModelAsetFormSheet from './ModelAsetFormSheet';
import { PabrikanModelRecord } from './pabrikanAsetDetail';

type ListMeta = { current_page: number; last_page: number; total: number };

export default function PabrikanModels({
    manufacturer,
    canReadModels,
    canReadJenis,
    canCreate,
    canUpdate,
    canArchive,
    canReadAssets,
    onChanged,
}: {
    manufacturer: MasterRecord;
    canReadModels: boolean;
    canReadJenis: boolean;
    canCreate: boolean;
    canUpdate: boolean;
    canArchive: boolean;
    canReadAssets: boolean;
    onChanged: () => void;
}) {
    const [items, setItems] = useState<PabrikanModelRecord[]>([]);
    const [meta, setMeta] = useState<ListMeta>({ current_page: 1, last_page: 1, total: 0 });
    const [page, setPage] = useState(1);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [editing, setEditing] = useState<PabrikanModelRecord | null | undefined>(undefined);
    const [archiving, setArchiving] = useState<PabrikanModelRecord | null>(null);

    const load = useCallback(async () => {
        if (!canReadModels) return;
        setLoading(true);
        setError('');
        const params = new URLSearchParams({
            pabrikan_aset_id: manufacturer.id,
            per_page: '20',
            page: String(page),
        });

        try {
            const result = await api<{ data: PabrikanModelRecord[]; meta: ListMeta }>(`/model-aset?${params}`);
            setItems(result.data);
            setMeta(result.meta);
        } catch (caught) {
            setError(errorMessage(caught, 'Model belum dapat dimuat.'));
        } finally {
            setLoading(false);
        }
    }, [canReadModels, manufacturer.id, page]);

    useEffect(() => {
        setPage(1);
    }, [manufacturer.id]);

    useEffect(() => {
        void load();
    }, [load]);

    async function archive() {
        if (!archiving) return;
        try {
            await api(`/model-aset/${archiving.id}`, { method: 'DELETE' });
            setArchiving(null);
            await load();
            onChanged();
        } catch (caught) {
            setError(errorMessage(caught, 'Model belum dapat diarsipkan.'));
        }
    }

    if (!canReadModels) {
        return <Empty><EmptyDescription>Daftar model belum tersedia karena Anda belum memiliki akses untuk membacanya.</EmptyDescription></Empty>;
    }

    const optionLabel = (option: ParentSummary) => `${option.kode} — ${option.nama}`;
    const columns: DataTableColumn<PabrikanModelRecord>[] = [
        {
            id: 'model',
            header: 'Model',
            cell: (item) => (
                <div>
                    <span className="font-medium">{item.nama}</span>
                    {!item.aktif && <Badge className="ml-2" variant="secondary">Tidak aktif</Badge>}
                </div>
            ),
            sortValue: (item) => item.nama,
            width: 220,
        },
        { id: 'description', header: 'Keterangan', cell: (item) => <span className="text-muted-foreground">{item.keterangan || '–'}</span>, width: 260 },
        {
            id: 'asset-type',
            header: 'Jenis aset',
            cell: (item) => <span className="text-muted-foreground">{item.jenis_aset ? optionLabel(item.jenis_aset) : '–'}</span>,
            width: 220,
        },
        {
            id: 'assets',
            header: 'Aset',
            cell: (item) => canReadAssets ? (item.asset_count ?? '–') : '–',
            width: 90,
        },
    ];
    const actions: DataTableRowAction[] = [];
    if (canUpdate) actions.push({ id: 'edit', label: 'Ubah' });
    if (canArchive) actions.push({ id: 'archive', label: 'Arsipkan', destructive: true, separatorBefore: actions.length > 0 });

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <p className="font-semibold">Model</p>
                    <p className="text-sm text-muted-foreground">{meta.total} model ditemukan untuk pabrikan ini.</p>
                </div>
                {canCreate && (
                    <ActionButton action="create" type="button" onClick={() => setEditing(null)}>
                        Tambah model
                    </ActionButton>
                )}
            </div>
            {error ? (
                <Empty>
                    <EmptyHeader><EmptyTitle>Model belum dapat ditampilkan</EmptyTitle><EmptyDescription>{error}</EmptyDescription></EmptyHeader>
                    <Button variant="outline" onClick={() => void load()}>Coba lagi</Button>
                </Empty>
            ) : loading ? (
                <Empty><EmptyDescription>Memuat model…</EmptyDescription></Empty>
            ) : items.length === 0 ? (
                <Empty>
                    <EmptyHeader><EmptyTitle>Belum ada model</EmptyTitle><EmptyDescription>Tambahkan model pertama untuk pabrikan ini.</EmptyDescription></EmptyHeader>
                </Empty>
            ) : (
                <DataTable
                    columns={columns}
                    data={items}
                    getRowKey={(item) => item.id}
                    getRowLabel={(item) => item.nama}
                    actions={actions}
                    onRowAction={(action, item) => {
                        if (action === 'edit') setEditing(item);
                        if (action === 'archive') setArchiving(item);
                    }}
                />
            )}
            {meta.last_page > 1 && (
                <div className="flex items-center justify-end gap-3 text-sm text-muted-foreground">
                    <Button variant="outline" size="sm" disabled={meta.current_page <= 1} onClick={() => setPage((current) => current - 1)}>Sebelumnya</Button>
                    <span>Halaman {meta.current_page} dari {meta.last_page}</span>
                    <Button variant="outline" size="sm" disabled={meta.current_page >= meta.last_page} onClick={() => setPage((current) => current + 1)}>Berikutnya</Button>
                </div>
            )}

            {editing !== undefined && (
                <ModelAsetFormSheet
                    key={editing?.id ?? 'baru'}
                    manufacturer={manufacturer}
                    value={editing}
                    canReadJenis={canReadJenis}
                    onClose={() => setEditing(undefined)}
                    onSaved={async () => {
                        setEditing(undefined);
                        await load();
                        onChanged();
                    }}
                />
            )}

            <AlertDialog open={archiving !== null} onOpenChange={(open) => !open && setArchiving(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Arsipkan {archiving?.nama}?</AlertDialogTitle>
                        <AlertDialogDescription>Model ini tidak lagi tampil pada daftar pilihan. Aset yang sudah memakainya tidak berubah.</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Batal</AlertDialogCancel>
                        <AlertDialogAction onClick={() => void archive()}>Arsipkan</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
}
