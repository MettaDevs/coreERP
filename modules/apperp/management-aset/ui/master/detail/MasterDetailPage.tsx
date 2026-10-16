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
import { MasterDetailLayout } from '@apperp/ui/master-detail-layout';
import { useState } from 'react';
import { api, errorMessage } from '../../api';
import { useRecordList } from '../../use-record-list';
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

export default function MasterDetailPage({
    config,
    permissions,
}: {
    config: MasterConfig;
    permissions: Permission[];
}) {
    const can = (action: MasterAction) =>
        permissions.includes(permission(config.resource, action));

    const [search, setSearch] = useState('');
    const [showDetail, setShowDetail] = useState(false);
    const [activeFilter, setActiveFilter] = useState('semua');
    const [error, setError] = useState('');
    const [snapshot, setSnapshot] = useState<MasterRecord | null>(null);

    const [selectedId, setSelectedId] = useState<string | null>(null);
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

    const list = useRecordList<MasterRecord>(
        `/${config.resource}`,
        search,
        activeFilter === 'semua' ? {} : { aktif: activeFilter },
    );
    const { items, setItems, loading } = list;
    const load = () => {
        setError('');
        list.reload();
    };

    const selected =
        items.find((item) => item.id === selectedId) ??
        (snapshot?.id === selectedId ? snapshot : null);

    function commitSelect(id: string | null) {
        setShowDetail(id !== null);
        setSnapshot(items.find((item) => item.id === id) ?? null);
        setSelectedId(id);
        setMode('view');
        setDirty(false);
        setPendingSelect(undefined);
    }

    function requestSelect(id: string) {
        if (id === selectedId) {
            setShowDetail(true);

            return;
        }

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
            const response = await api<{ data: MasterRecord }>(
                `/${config.resource}/${selected.id}`,
                {
                    method: 'PATCH',
                    body: JSON.stringify({
                        aktif: !selected.aktif,
                        version: selected.version,
                    }),
                },
            );
            setSnapshot(response.data);
            setSelectedId(response.data.id);
            load();
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
                body: JSON.stringify({ version: selected.version }),
            });
            setSnapshot(null);
            setSelectedId(null);
            load();
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
        setSnapshot(savedRecord);
        setSelectedId(savedRecord.id);
        setShowDetail(true);
        // Record baru langsung tetap disunting karena master maintenance biasanya
        // memiliki rincian lanjutan (misalnya nilai variable atau baris template).
        // Setelah PATCH biasa, kembali ke mode baca seperti sebelumnya.
        setMode(created ? 'edit' : 'view');
        load();
    }

    /**
     * Rincian record (baris template, atribut, kaitan) disimpan dengan mengklaim versi record
     * itu. Versi barunya dicatat pada daftar, satu-satunya pemegang versi, supaya penyimpanan
     * berikutnya — rincian lain atau form-nya sendiri — berangkat dari versi terbaru.
     */
    function changeVersion(id: string, version: number) {
        setSnapshot((current) =>
            current?.id === id ? { ...current, version } : current,
        );
        setItems((current) =>
            current.map((item) =>
                item.id === id ? { ...item, version } : item,
            ),
        );
    }

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
                                    setSnapshot(null);
                                    setSelectedId(null);
                                    setMode('create');
                                    setShowDetail(true);
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

            <MasterDetailLayout
                detailOpen={showDetail}
                onDetailOpenChange={setShowDetail}
                list={
                    <RecordListPane
                        config={config}
                        items={items}
                        hasMore={list.hasMore}
                        loading={loading}
                        error={error || list.error}
                        search={search}
                        onSearchChange={(next) => {
                            if (mode === 'view') {
                                setSnapshot(null);
                                setSelectedId(null);
                            }

                            setSearch(next);
                            // Nomor halaman disetel ulang di tempat filternya diubah,
                            // bukan lewat effect susulan.
                        }}
                        activeFilter={activeFilter}
                        onActiveFilterChange={(next) => {
                            if (mode === 'view') {
                                setSnapshot(null);
                                setSelectedId(null);
                            }

                            setActiveFilter(next);
                        }}
                        selectedId={selectedId}
                        creating={mode === 'create'}
                        onSelect={requestSelect}
                        onLoadMore={list.loadMore}
                        onRetry={list.retry}
                    />
                }
            >
                <RecordDetailPane
                    key={`${selectedId ?? 'baru'}:${formNonce}`}
                    formId={FORM_ID}
                    config={config}
                    record={mode === 'create' ? null : selected}
                    mode={mode}
                    permissions={permissions}
                    canEdit={can('update')}
                    onRequestEdit={() => {
                        setSnapshot(selected);
                        setSelectedId(selected?.id ?? null);
                        setMode('edit');
                        setShowDetail(true);
                    }}
                    onDirtyChange={setDirty}
                    onSavingChange={setSaving}
                    onVersionChange={(version) => {
                        if (selectedId) {
                            changeVersion(selectedId, version);
                        }
                    }}
                    onSaved={(savedRecord, created) => {
                        void handleSaved(savedRecord, created);
                    }}
                />
            </MasterDetailLayout>

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
