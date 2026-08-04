import { useEffect, useMemo, useState } from 'react';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import { Card, CardAction, CardContent, CardFooter, CardHeader, CardTitle } from '@apperp/ui/card';
import { DataTable, type DataTableColumn, type DataTableRowAction } from '@apperp/ui/data-table';
import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@apperp/ui/empty';
import { Input } from '@apperp/ui/input';
import { Select } from '@apperp/ui/select';
import { api, errorMessage } from '../api';
import MasterForm from './MasterForm';
import {
    MasterAction,
    MasterConfig,
    MasterRecord,
    ParentSummary,
    Permission,
    parentSummaryOf,
    permission,
} from './masters';

type ListMeta = { current_page: number; last_page: number; total: number };
const emptyMeta: ListMeta = { current_page: 1, last_page: 1, total: 0 };

export default function MasterPage({ config, permissions }: { config: MasterConfig; permissions: Permission[] }) {
    const parent = config.parent;
    const can = (action: MasterAction) => permissions.includes(permission(config.resource, action));
    const [items, setItems] = useState<MasterRecord[]>([]);
    const [meta, setMeta] = useState<ListMeta>(emptyMeta);
    const [search, setSearch] = useState('');
    const [activeFilter, setActiveFilter] = useState('semua');
    const [parentFilter, setParentFilter] = useState('semua');
    const [page, setPage] = useState(1);
    const [editing, setEditing] = useState<MasterRecord | null | undefined>();
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [parentOptions, setParentOptions] = useState<ParentSummary[]>([]);
    const [parentOptionsError, setParentOptionsError] = useState('');
    const [selectedIds, setSelectedIds] = useState<string[]>([]);
    const parentItems = parentOptions.map((option) => `${option.kode} — ${option.nama}`);
    const selectedParent = parentOptions.find((option) => option.id === parentFilter);

    const query = useMemo(() => {
        const params = new URLSearchParams({ per_page: '20', page: String(page) });
        if (search.trim()) params.set('q', search.trim());
        if (activeFilter !== 'semua') params.set('aktif', activeFilter);
        if (parent && parentFilter !== 'semua') params.set(parent.field, parentFilter);
        return params.toString();
    }, [search, activeFilter, parentFilter, page, parent]);

    async function load() {
        setLoading(true);
        setError('');
        try {
            const list = await api<{ data: MasterRecord[]; meta: ListMeta }>(`/${config.resource}?${query}`);
            setItems(list.data);
            setMeta(list.meta);
        } catch (caught) {
            setError(errorMessage(caught, 'Data belum dapat dimuat.'));
        } finally {
            setLoading(false);
        }
    }

    useEffect(() => {
        if (!parent) {
            setParentOptions([]);
            setParentOptionsError('');
            return;
        }
        let cancelled = false;
        setParentOptionsError('');
        api<{ data: MasterRecord[] }>(`/${parent.resource}?per_page=100&aktif=true`)
            .then((result) => {
                if (!cancelled) setParentOptions(result.data.map(({ id, kode, nama }) => ({ id, kode, nama })));
            })
            .catch(() => {
                if (cancelled) return;
                setParentOptions([]);
                setParentOptionsError(`Pilihan ${parent.label.toLowerCase()} belum dapat dimuat. Anda memerlukan akses lihat untuk memilih induk.`);
            });
        return () => { cancelled = true; };
    }, [parent]);

    useEffect(() => { setPage(1); }, [search, activeFilter, parentFilter]);
    useEffect(() => { setSelectedIds([]); }, [config.resource, query]);
    useEffect(() => {
        const timer = window.setTimeout(load, 250);
        return () => window.clearTimeout(timer);
    }, [query]);

    async function toggle(item: MasterRecord) {
        try {
            await api(`/${config.resource}/${item.id}`, {
                method: 'PATCH',
                body: JSON.stringify({ aktif: !item.aktif }),
            });
            load();
        } catch (caught) {
            setError(errorMessage(caught, 'Status belum dapat diubah.'));
        }
    }

    async function archive(item: MasterRecord) {
        if (!window.confirm(`Arsipkan ${item.nama}? Data ini tidak lagi tampil pada daftar pilihan.`)) return;
        try {
            await api(`/${config.resource}/${item.id}`, { method: 'DELETE' });
            load();
        } catch (caught) {
            setError(errorMessage(caught, 'Data belum dapat diarsipkan.'));
        }
    }

    async function changeSelected(aktif: boolean) {
        const selected = items.filter((item) => selectedIds.includes(item.id) && item.aktif !== aktif);
        if (!selected.length) return;
        const action = aktif ? 'Aktifkan' : 'Nonaktifkan';
        if (!window.confirm(`${action} ${selected.length} data terpilih?`)) return;
        const results = await Promise.allSettled(selected.map((item) => api(`/${config.resource}/${item.id}`, {
            method: 'PATCH',
            body: JSON.stringify({ aktif }),
        })));
        const failed = results.filter((result) => result.status === 'rejected').length;
        setSelectedIds([]);
        if (failed) setError(`${failed} data belum dapat diubah.`);
        load();
    }

    async function archiveSelected() {
        const selected = items.filter((item) => selectedIds.includes(item.id));
        if (!selected.length || !window.confirm(`Arsipkan ${selected.length} data terpilih? Data ini tidak lagi tampil pada daftar pilihan.`)) return;
        const results = await Promise.allSettled(selected.map((item) => api(`/${config.resource}/${item.id}`, { method: 'DELETE' })));
        const failed = results.filter((result) => result.status === 'rejected').length;
        setSelectedIds([]);
        if (failed) setError(`${failed} data belum dapat diarsipkan.`);
        load();
    }

    const columns: DataTableColumn<MasterRecord>[] = [
        { id: 'kode', header: config.kodeLabel, cell: (item) => <span className="code">{item.kode}</span>, sortValue: (item) => item.kode, width: 170 },
        { id: 'nama', header: config.namaLabel, cell: (item) => <span className="name">{item.nama}</span>, sortValue: (item) => item.nama, width: 260 },
        ...(parent ? [{ id: 'parent', header: parent.label, cell: (item: MasterRecord) => {
            const summary = parentSummaryOf(item, parent);
            return <span className="muted">{summary ? `${summary.kode} — ${summary.nama}` : '—'}</span>;
        }, width: 220 }] : []),
        { id: 'keterangan', header: 'Keterangan', cell: (item) => <span className="muted">{item.keterangan || '—'}</span>, width: 260 },
        { id: 'status', header: 'Status', cell: (item) => <Badge variant={item.aktif ? 'default' : 'secondary'}>{item.aktif ? 'Aktif' : 'Tidak aktif'}</Badge>, width: 120 },
    ];
    const rowActions: DataTableRowAction[] = [];
    if (can('update')) rowActions.push({ id: 'edit', label: 'Ubah' }, { id: 'toggle', label: 'Ubah status' });
    if (can('archive')) rowActions.push({ id: 'archive', label: 'Arsipkan', destructive: true, separatorBefore: rowActions.length > 0 });

    return (
        <div>
            <Card aria-labelledby="list-title" className="min-h-full rounded-none border-0 shadow-none">
                <CardHeader className="min-h-0 border-b px-5 py-3">
                    <CardTitle id="list-title" className="text-base">{config.title}</CardTitle>
                    {can('create') && <CardAction><Button onClick={() => setEditing(null)}>＋ Tambah {config.singular}</Button></CardAction>}
                </CardHeader>

                <CardContent className="px-0">
                    <div className="flex flex-col gap-3 border-b px-5 py-3 sm:flex-row sm:items-end sm:justify-between">
                        <div className="space-y-1">
                            <p className="font-semibold">Daftar {config.singular}</p>
                            <p className="text-sm text-muted-foreground">{meta.total} data ditemukan</p>
                        </div>
                        <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row">
                            <Input className="w-full sm:w-70" type="search" placeholder="Cari kode atau nama" aria-label={`Cari ${config.singular}`} value={search} onChange={(event) => setSearch(event.target.value)} />
                            {parent && (
                                <div className="w-full sm:w-52">
                                    <Select
                                        items={[`Semua ${parent.label.toLowerCase()}`, ...parentItems]}
                                        value={selectedParent ? `${selectedParent.kode} — ${selectedParent.nama}` : `Semua ${parent.label.toLowerCase()}`}
                                        searchPlaceholder={`Cari ${parent.label.toLowerCase()}`}
                                        emptyMessage={`${parent.label} tidak ditemukan.`}
                                        ariaLabel={`Saring berdasarkan ${parent.label.toLowerCase()}`}
                                        onValueChange={(item) => setParentFilter(item === `Semua ${parent.label.toLowerCase()}` ? 'semua' : parentOptions.find((option) => `${option.kode} — ${option.nama}` === item)?.id ?? 'semua')}
                                    />
                                </div>
                            )}
                            <div className="w-full sm:w-44">
                                <Select
                                    items={['Semua status', 'Aktif', 'Tidak aktif']}
                                    value={activeFilter === 'true' ? 'Aktif' : activeFilter === 'false' ? 'Tidak aktif' : 'Semua status'}
                                    searchPlaceholder="Cari status"
                                    emptyMessage="Status tidak ditemukan."
                                    ariaLabel="Saring berdasarkan status"
                                    onValueChange={(item) => setActiveFilter(item === 'Aktif' ? 'true' : item === 'Tidak aktif' ? 'false' : 'semua')}
                                />
                            </div>
                        </div>
                    </div>
                    {selectedIds.length > 0 && (
                        <div className="flex flex-wrap items-center justify-between gap-2 border-b bg-muted/40 px-5 py-2 text-sm">
                            <span>{selectedIds.length} data dipilih</span>
                            <div className="flex gap-2">
                                {can('update') && <Button variant="outline" size="sm" onClick={() => changeSelected(false)}>Nonaktifkan</Button>}
                                {can('archive') && <Button variant="destructive" size="sm" onClick={archiveSelected}>Arsipkan</Button>}
                            </div>
                        </div>
                    )}
                    {error ? (
                        <Empty><EmptyHeader><EmptyTitle>Data belum dapat ditampilkan</EmptyTitle><EmptyDescription>{error}</EmptyDescription></EmptyHeader><Button variant="outline" onClick={load}>Coba lagi</Button></Empty>
                    ) : loading ? (
                        <Empty><EmptyDescription>Memuat {config.singular}…</EmptyDescription></Empty>
                    ) : items.length === 0 ? (
                        <Empty><EmptyHeader><EmptyTitle>Belum ada {config.singular}</EmptyTitle><EmptyDescription>Tambahkan data pertama agar pilihan pada bagian lain sudah tersedia.</EmptyDescription></EmptyHeader></Empty>
                    ) : (
                        <DataTable
                            columns={columns}
                            data={items}
                            getRowKey={(item) => item.id}
                            getRowLabel={(item) => item.nama}
                            actions={rowActions}
                            onRowAction={(action, item) => {
                                if (action === 'edit') setEditing(item);
                                if (action === 'toggle') toggle(item);
                                if (action === 'archive') archive(item);
                            }}
                        />
                    )}
                </CardContent>
                {meta.last_page > 1 && (
                    <CardFooter className="justify-end gap-3 border-t text-sm text-muted-foreground" aria-label={`Halaman daftar ${config.singular}`}>
                        <Button variant="outline" disabled={meta.current_page <= 1} onClick={() => setPage((current) => current - 1)}>Sebelumnya</Button>
                        <span>Halaman {meta.current_page} dari {meta.last_page}</span>
                        <Button variant="outline" disabled={meta.current_page >= meta.last_page} onClick={() => setPage((current) => current + 1)}>Berikutnya</Button>
                    </CardFooter>
                )}
            </Card>

            {editing !== undefined && (
                <MasterForm config={config} value={editing} parentOptions={parentOptions} parentOptionsError={parentOptionsError} onClose={() => setEditing(undefined)} onSaved={() => { setEditing(undefined); load(); }} />
            )}
        </div>
    );
}
