import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
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
import { ActionButton } from '@apperp/ui/action-button';
import { Button } from '@apperp/ui/button';
import { api, errorMessage } from '../../api';
import { MasterAction, MasterConfig, MasterRecord, MasterResource, Permission, permission } from '../masters';
import RecordDetailPane, { DetailMode } from './RecordDetailPane';
import RecordListPane from './RecordListPane';

/**
 * Master yang sudah memakai tata letak daftar-detail. Selama daftar ini belum memuat
 * seluruh master, `MasterPage` tetap hidup berdampingan; ketika semuanya sudah pindah,
 * yang dihapus adalah daftar ini beserta `MasterPage`.
 */
export const DETAIL_LAYOUT_RESOURCES: MasterResource[] = [
    'group-aset', 'jenis-aset', 'pabrikan-aset', 'maintenance-job-types',
    'maintenance-checklist-variables', 'maintenance-checklist-templates',
];

const FORM_ID = 'master-detail-form';
const PER_PAGE = 20;

export default function MasterDetailPage({ config, permissions }: { config: MasterConfig; permissions: Permission[] }) {
    const can = (action: MasterAction) => permissions.includes(permission(config.resource, action));

    const [items, setItems] = useState<MasterRecord[]>([]);
    const [total, setTotal] = useState(0);
    const [lastPage, setLastPage] = useState(1);
    const [page, setPage] = useState(1);
    const [search, setSearch] = useState('');
    const [activeFilter, setActiveFilter] = useState('semua');
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const loadingMoreRef = useRef(false);

    const [selectedId, setSelectedId] = useState<string | null>(null);
    const [mode, setMode] = useState<DetailMode>('view');
    const [dirty, setDirty] = useState(false);
    const [saving, setSaving] = useState(false);
    /** Nilainya `undefined` berarti tidak ada tawaran pindah yang tertahan. */
    const [pendingSelect, setPendingSelect] = useState<string | null | undefined>(undefined);
    const [archiving, setArchiving] = useState(false);
    /** Dinaikkan untuk membuang suntingan: panel detail dipasang ulang dari data server. */
    const [formNonce, setFormNonce] = useState(0);

    const query = useMemo(() => {
        const params = new URLSearchParams({ per_page: String(PER_PAGE), page: String(page) });
        if (search.trim()) params.set('q', search.trim());
        if (activeFilter !== 'semua') params.set('aktif', activeFilter);

        return params.toString();
    }, [page, search, activeFilter]);

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const list = await api<{ data: MasterRecord[]; meta: { current_page: number; last_page: number; total: number } }>(
                `/${config.resource}?${query}`,
            );
            // Halaman pertama mengganti; halaman berikutnya menambah. Daftar yang hanya
            // tumbuh membuat record yang sedang dipilih tidak mungkin hilang dari bawah
            // kaki pengguna saat ia menggulir.
            setItems((current) => (list.meta.current_page === 1 ? list.data : [...current, ...list.data]));
            setTotal(list.meta.total);
            setLastPage(list.meta.last_page);
            setError('');
        } catch (caught) {
            setError(errorMessage(caught, 'Data belum dapat dimuat.'));
        } finally {
            setLoading(false);
            loadingMoreRef.current = false;
        }
    }, [config.resource, query]);

    useEffect(() => {
        const timer = window.setTimeout(() => { void load(); }, 250);

        return () => window.clearTimeout(timer);
    }, [load]);

    useEffect(() => { setPage(1); }, [search, activeFilter]);

    // Pilihan awal jatuh ke record pertama supaya panel detail tidak menyambut dengan layar
    // kosong. Tidak pernah menimpa pilihan yang sudah ada.
    useEffect(() => {
        if (mode === 'create' || selectedId !== null || items.length === 0) return;
        setSelectedId(items[0].id);
    }, [items, mode, selectedId]);

    const selected = items.find((item) => item.id === selectedId) ?? null;

    function commitSelect(id: string | null) {
        setSelectedId(id);
        setMode('view');
        setDirty(false);
        setPendingSelect(undefined);
    }

    function requestSelect(id: string) {
        if (mode !== 'view' && dirty) {
            setPendingSelect(id);
            return;
        }
        commitSelect(id);
    }

    function cancelEditing() {
        setDirty(false);
        setFormNonce((current) => current + 1);
        if (mode === 'create') setSelectedId(items[0]?.id ?? null);
        setMode('view');
    }

    async function toggleStatus() {
        if (!selected) return;
        try {
            await api(`/${config.resource}/${selected.id}`, {
                method: 'PATCH',
                body: JSON.stringify({ aktif: !selected.aktif }),
            });
            setPage(1);
            await load();
            setFormNonce((current) => current + 1);
        } catch (caught) {
            setError(errorMessage(caught, 'Status belum dapat diubah.'));
        }
    }

    async function archive() {
        if (!selected) return;
        try {
            await api(`/${config.resource}/${selected.id}`, { method: 'DELETE' });
            setSelectedId(null);
            setPage(1);
            await load();
        } catch (caught) {
            setError(errorMessage(caught, 'Data belum dapat diarsipkan.'));
        } finally {
            setArchiving(false);
        }
    }

    async function handleSaved(id: string) {
        setSelectedId(id);
        setMode('view');
        setPage(1);
        await load();
    }

    const loadMore = useCallback(() => {
        if (loading || loadingMoreRef.current || page >= lastPage) return;
        loadingMoreRef.current = true;
        setPage((current) => current + 1);
    }, [lastPage, loading, page]);

    const editing = mode !== 'view';

    return (
        <div className="flex h-full min-h-0 flex-col overflow-hidden">
            <div className="sticky top-0 z-20 flex shrink-0 flex-wrap items-center gap-2 border-b bg-background px-4 py-2">
                <span className="mr-2 font-semibold">{config.title}</span>
                {editing ? (
                    <>
                        <Button type="submit" form={FORM_ID} disabled={saving}>
                            {saving ? 'Menyimpan…' : 'Simpan'}
                        </Button>
                        <Button variant="outline" type="button" onClick={cancelEditing}>Batal</Button>
                    </>
                ) : (
                    <>
                        {can('update') && (
                            <ActionButton action="edit" type="button" disabled={!selected} onClick={() => setMode('edit')}>
                                Ubah
                            </ActionButton>
                        )}
                        {can('create') && (
                            <ActionButton action="create" type="button" onClick={() => { setSelectedId(null); setMode('create'); }}>
                                Tambah {config.singular}
                            </ActionButton>
                        )}
                        {/* Ubah status sengaja netral: ia bolak-balik dan tidak merusak apa pun,
                            jadi tidak pantas bersaing perhatian dengan aksi yang berkonsekuensi. */}
                        {can('update') && (
                            <Button variant="outline" type="button" disabled={!selected} onClick={() => void toggleStatus()}>
                                Ubah status
                            </Button>
                        )}
                        {can('archive') && (
                            <ActionButton action="archive" type="button" disabled={!selected} onClick={() => setArchiving(true)}>
                                Arsipkan
                            </ActionButton>
                        )}
                    </>
                )}
            </div>

            <div className="grid min-h-0 flex-1 grid-cols-1 lg:grid-cols-[20rem_minmax(0,1fr)]">
                <RecordListPane
                    config={config}
                    items={items}
                    total={total}
                    hasMore={page < lastPage}
                    loading={loading}
                    error={error}
                    search={search}
                    onSearchChange={setSearch}
                    activeFilter={activeFilter}
                    onActiveFilterChange={setActiveFilter}
                    selectedId={selectedId}
                    creating={mode === 'create'}
                    onSelect={requestSelect}
                    onLoadMore={loadMore}
                    onRetry={() => { void load(); }}
                />

                <RecordDetailPane
                    key={`${selectedId ?? 'baru'}:${formNonce}`}
                    formId={FORM_ID}
                    config={config}
                    record={mode === 'create' ? null : selected}
                    mode={mode}
                    permissions={permissions}
                    canEdit={can('update')}
                    onRequestEdit={() => setMode('edit')}
                    onDirtyChange={setDirty}
                    onSavingChange={setSaving}
                    onSaved={(id) => { void handleSaved(id); }}
                />
            </div>

            <AlertDialog open={pendingSelect !== undefined} onOpenChange={(open) => !open && setPendingSelect(undefined)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Perubahan belum disimpan</AlertDialogTitle>
                        <AlertDialogDescription>
                            Pindah ke {config.singular} lain akan membuang perubahan yang belum Anda simpan.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Tetap di sini</AlertDialogCancel>
                        <AlertDialogAction onClick={() => commitSelect(pendingSelect ?? null)}>
                            Buang perubahan
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            <AlertDialog open={archiving} onOpenChange={setArchiving}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Arsipkan {selected?.nama}?</AlertDialogTitle>
                        <AlertDialogDescription>
                            Data ini tidak lagi tampil pada daftar pilihan. Record yang sudah memakainya tidak berubah.
                        </AlertDialogDescription>
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
