import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
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
import { Button } from '@apperp/ui/button';
import { api, errorMessage } from '../../api';
import type {
    MasterAction,
    MasterConfig,
    MasterRecord,
    Permission,
} from '../masters';
import { permission } from '../masters';
import type { DetailMode } from './RecordDetailPane';
import RecordDetailPane from './RecordDetailPane';
import RecordListPane from './RecordListPane';

const FORM_ID = 'master-detail-form';
const PER_PAGE = 20;

export default function MasterDetailPage({
    config,
    permissions,
}: {
    config: MasterConfig;
    permissions: Permission[];
}) {
    const can = (action: MasterAction) =>
        permissions.includes(permission(config.resource, action));

    const [items, setItems] = useState<MasterRecord[]>([]);
    const [total, setTotal] = useState(0);
    const [lastPage, setLastPage] = useState(1);
    const [page, setPage] = useState(1);
    const [search, setSearch] = useState('');
    const [activeFilter, setActiveFilter] = useState('semua');
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const loadingMoreRef = useRef(false);

    const [selectedIdDipilih, setSelectedId] = useState<string | null>(null);
    const [mode, setMode] = useState<DetailMode>('view');
    const [dirty, setDirty] = useState(false);
    const [saving, setSaving] = useState(false);
    /** Nilainya `undefined` berarti tidak ada tawaran pindah yang tertahan. */
    const [pendingSelect, setPendingSelect] = useState<
        string | null | undefined
    >(undefined);
    const [archiving, setArchiving] = useState(false);
    /** Dinaikkan untuk membuang suntingan: panel detail dipasang ulang dari data server. */
    const [formNonce, setFormNonce] = useState(0);

    const query = useMemo(() => {
        const params = new URLSearchParams({
            per_page: String(PER_PAGE),
            page: String(page),
        });

        if (search.trim()) {
            params.set('q', search.trim());
        }

        if (activeFilter !== 'semua') {
            params.set('aktif', activeFilter);
        }

        return params.toString();
    }, [page, search, activeFilter]);

    const load = useCallback(async () => {
        setLoading(true);

        try {
            const list = await api<{
                data: MasterRecord[];
                meta: {
                    current_page: number;
                    last_page: number;
                    total: number;
                };
            }>(`/${config.resource}?${query}`);
            // Halaman pertama mengganti; halaman berikutnya menambah. Daftar yang hanya
            // tumbuh membuat record yang sedang dipilih tidak mungkin hilang dari bawah
            // kaki pengguna saat ia menggulir.
            setItems((current) =>
                list.meta.current_page === 1
                    ? list.data
                    : [...current, ...list.data],
            );
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
        const timer = window.setTimeout(() => {
            void load();
        }, 250);

        return () => window.clearTimeout(timer);
    }, [load]);

    // Pilihan awal jatuh ke record pertama supaya panel detail tidak menyambut dengan
    // layar kosong. Dihitung saat render, jadi tidak ada satu frame pun yang sempat
    // memperlihatkan panel kosong sebelum pilihan otomatisnya jadi. Pilihan yang sudah
    // ada tidak pernah ditimpa, dan saat sedang membuat record baru tidak ada pilihan.
    const selectedId =
        selectedIdDipilih ??
        (mode === 'create' ? null : (items[0]?.id ?? null));

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

        if (mode === 'create') {
            setSelectedId(items[0]?.id ?? null);
        }

        setMode('view');
    }

    async function toggleStatus() {
        if (!selected) {
            return;
        }

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
        if (!selected) {
            return;
        }

        try {
            await api(`/${config.resource}/${selected.id}`, {
                method: 'DELETE',
            });
            setSelectedId(null);
            setPage(1);
            await load();
        } catch (caught) {
            setError(errorMessage(caught, 'Data belum dapat diarsipkan.'));
        } finally {
            setArchiving(false);
        }
    }

    async function handleSaved(savedRecord: MasterRecord, created: boolean) {
        // Masukkan hasil POST/PATCH ke state sebelum memuat ulang daftar. Dengan begitu
        // panel detail langsung menerima kode dan nama dari respons yang sama, bukan
        // sempat menerima `record = null` sambil menunggu daftar selesai dimuat.
        setItems((current) => {
            const existingIndex = current.findIndex(
                (item) => item.id === savedRecord.id,
            );

            if (existingIndex === -1) {
                return [savedRecord, ...current];
            }

            return current.map((item, index) =>
                index === existingIndex ? savedRecord : item,
            );
        });
        setSelectedId(savedRecord.id);
        // Record baru langsung tetap disunting karena master maintenance biasanya
        // memiliki rincian lanjutan (misalnya nilai variable atau baris template).
        // Setelah PATCH biasa, kembali ke mode baca seperti sebelumnya.
        setMode(created ? 'edit' : 'view');
        setPage(1);
        await load();
    }

    const loadMore = useCallback(() => {
        if (loading || loadingMoreRef.current || page >= lastPage) {
            return;
        }

        loadingMoreRef.current = true;
        setPage((current) => current + 1);
    }, [lastPage, loading, page]);

    const editing = mode !== 'view';

    return (
        <div className="flex h-full min-h-0 flex-col overflow-hidden">
            <div className="bg-background sticky top-0 z-20 flex shrink-0 flex-wrap items-center gap-2 border-b px-4 py-2">
                <span className="mr-2 font-semibold">{config.title}</span>
                {editing ? (
                    <>
                        <Button type="submit" form={FORM_ID} disabled={saving}>
                            {saving ? 'Menyimpan…' : 'Simpan'}
                        </Button>
                        <Button
                            variant="outline"
                            type="button"
                            onClick={cancelEditing}
                        >
                            Batal
                        </Button>
                    </>
                ) : (
                    <>
                        {can('update') && (
                            <ActionButton
                                action="edit"
                                type="button"
                                disabled={!selected}
                                onClick={() => setMode('edit')}
                            >
                                Ubah
                            </ActionButton>
                        )}
                        {can('create') && (
                            <ActionButton
                                action="create"
                                type="button"
                                onClick={() => {
                                    setSelectedId(null);
                                    setMode('create');
                                }}
                            >
                                Tambah {config.singular}
                            </ActionButton>
                        )}
                        {/* Ubah status sengaja netral: ia bolak-balik dan tidak merusak apa pun,
                            jadi tidak pantas bersaing perhatian dengan aksi yang berkonsekuensi. */}
                        {can('update') && (
                            <Button
                                variant="outline"
                                type="button"
                                disabled={!selected}
                                onClick={() => void toggleStatus()}
                            >
                                Ubah status
                            </Button>
                        )}
                        {can('archive') && (
                            <ActionButton
                                action="archive"
                                type="button"
                                disabled={!selected}
                                onClick={() => setArchiving(true)}
                            >
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
                    onSearchChange={(next) => {
                        setSearch(next);
                        // Nomor halaman disetel ulang di tempat filternya diubah,
                        // bukan lewat effect susulan.
                        setPage(1);
                    }}
                    activeFilter={activeFilter}
                    onActiveFilterChange={(next) => {
                        setActiveFilter(next);
                        setPage(1);
                    }}
                    selectedId={selectedId}
                    creating={mode === 'create'}
                    onSelect={requestSelect}
                    onLoadMore={loadMore}
                    onRetry={() => {
                        void load();
                    }}
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
                    onSaved={(savedRecord, created) => {
                        void handleSaved(savedRecord, created);
                    }}
                />
            </div>

            <AlertDialog
                open={pendingSelect !== undefined}
                onOpenChange={(open) => !open && setPendingSelect(undefined)}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Perubahan belum disimpan
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            Pindah ke {config.singular} lain akan membuang
                            perubahan yang belum Anda simpan.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Tetap di sini</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={() => commitSelect(pendingSelect ?? null)}
                        >
                            Buang perubahan
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            <AlertDialog open={archiving} onOpenChange={setArchiving}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Arsipkan {selected?.nama}?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            Data ini tidak lagi tampil pada daftar pilihan.
                            Record yang sudah memakainya tidak berubah.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Batal</AlertDialogCancel>
                        <AlertDialogAction onClick={() => void archive()}>
                            Arsipkan
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
}
