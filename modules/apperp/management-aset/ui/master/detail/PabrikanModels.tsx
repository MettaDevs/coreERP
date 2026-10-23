import { Trash2 } from 'lucide-react';
import { useState } from 'react';
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
import { useRecordNavigation } from '@apperp/ui/use-record-navigation';
import { useRecordTransition } from '@apperp/ui/use-record-transition';
import type { ApiValidationErrors } from '../../api';
import { useRecordList } from '../../use-record-list';
import type { MasterDetailsPayload } from '../DraftMasterDetails';
import type { MasterRecord, ParentSummary } from '../masters';
import ModelAsetFormSheet from './ModelAsetFormSheet';
import type { PabrikanModelRecord } from './pabrikanAsetDetail';

export default function PabrikanModels({
    manufacturer,
    canReadModels,
    canReadJenis,
    canCreate,
    canUpdate,
    canArchive,
    canReadAset,
    onChange,
    validationErrors,
}: {
    manufacturer: MasterRecord;
    canReadModels: boolean;
    canReadJenis: boolean;
    canCreate: boolean;
    canUpdate: boolean;
    canArchive: boolean;
    canReadAset: boolean;
    onChange: (details: MasterDetailsPayload) => void;
    validationErrors?: ApiValidationErrors;
}) {
    const [modelDirty, setModelDirty] = useState(false);
    const {
        request: requestModelTransition,
        confirmation: modelTransitionConfirmation,
    } = useRecordTransition(modelDirty, false);
    const closeModel = () => {
        requestModelTransition(() => {
            setEditing(undefined);
            setModelDirty(false);
        });
    };
    const createModel = () => {
        requestModelTransition(() => {
            setEditing(null);
            setModelDirty(false);
        });
    };
    const [versiMuat, setVersiMuat] = useState(0);
    const muatUlang = () => {
        setVersiMuat((versi) => versi + 1);
    };
    const [editing, setEditing] = useState<
        PabrikanModelRecord | null | undefined
    >(undefined);
    const [archiving, setArchiving] = useState<PabrikanModelRecord | null>(
        null,
    );
    const [changedModels, setChangedModels] = useState<PabrikanModelRecord[]>(
        [],
    );
    const [deletedModels, setDeletedModels] = useState<
        { id: string; version: number }[]
    >([]);
    function publishModels(
        rows: PabrikanModelRecord[],
        deleted: { id: string; version: number }[],
    ) {
        setChangedModels(rows);
        setDeletedModels(deleted);
        onChange({
            models: {
                rows: rows.map((row) => ({
                    ...(row.creation_key
                        ? { creation_key: row.creation_key }
                        : { id: row.id, version: row.version }),
                    nama: row.nama,
                    keterangan: row.keterangan,
                    aktif: row.aktif,
                    jenis_aset_id: row.jenis_aset_id,
                })),
                deleted,
            },
        });
    }

    const list = useRecordList<PabrikanModelRecord>(
        '/model-aset',
        '',
        { pabrikan_aset_id: manufacturer.id, refresh: String(versiMuat) },
        canReadModels,
    );
    const { loading } = list;
    const items = [
        ...changedModels.filter((row) => row.creation_key),
        ...list.items
            .filter(
                (row) =>
                    !deletedModels.some((deleted) => deleted.id === row.id),
            )
            .map(
                (row) =>
                    changedModels.find((changed) => changed.id === row.id) ??
                    row,
            ),
    ];

    const modelNavigation = useRecordNavigation({
        items,
        currentId: editing?.id ?? null,
        getId: (item) => item.id,
        onNavigate: (id) => {
            const row = items.find((item) => item.id === id);

            if (row) {
                setEditing(row);
            }
        },
        scopeKey: JSON.stringify([manufacturer.id, list.sort]),
        hasMore: list.hasMore,
        loading: list.loading,
        loadMore: list.loadMore,
        disabled: modelDirty,
    });
    function archive() {
        if (!archiving) {
            return;
        }

        publishModels(
            changedModels.filter((row) => row.id !== archiving.id),
            archiving.creation_key
                ? deletedModels
                : [
                      ...deletedModels,
                      { id: archiving.id, version: archiving.version },
                  ],
        );
        setEditing((current) =>
            current?.id === archiving.id ? undefined : current,
        );
        setArchiving(null);
    }

    if (!canReadModels) {
        return (
            <Empty>
                <EmptyDescription>
                    Daftar model belum tersedia karena Anda belum memiliki akses
                    untuk membacanya.
                </EmptyDescription>
            </Empty>
        );
    }

    const optionLabel = (option: ParentSummary) =>
        `${option.kode} — ${option.nama}`;
    const columns: DataTableColumn<PabrikanModelRecord>[] = [
        {
            id: 'model',
            header: 'Model',
            cell: (item) => (
                <div>
                    <span className="font-medium">{item.nama}</span>
                    {!item.aktif && (
                        <Badge className="ml-2" variant="secondary">
                            Tidak aktif
                        </Badge>
                    )}
                </div>
            ),
            sortValue: (item) => item.nama,
            width: 220,
        },
        {
            id: 'description',
            header: 'Keterangan',
            cell: (item) => (
                <span className="text-muted-foreground">
                    {item.keterangan || '–'}
                </span>
            ),
            width: 260,
        },
        {
            id: 'aset-type',
            header: 'Jenis aset',
            cell: (item) => (
                <span className="text-muted-foreground">
                    {item.jenis_aset ? optionLabel(item.jenis_aset) : '–'}
                </span>
            ),
            width: 220,
        },
        {
            id: 'aset',
            header: 'Aset',
            cell: (item) => (canReadAset ? (item.aset_count ?? '–') : '–'),
            width: 90,
        },
    ];
    const actions: DataTableRowAction[] = [];

    if (canUpdate) {
        actions.push({ id: 'edit', label: 'Ubah' });
    }

    if (canArchive) {
        actions.push({
            icon: <Trash2 aria-hidden="true" />,
            id: 'archive',
            label: 'Delete',
            destructive: true,
            separatorBefore: actions.length > 0,
        });
    }

    return (
        <div className="space-y-3">
            {modelTransitionConfirmation}
            {Object.entries(validationErrors ?? {})
                .filter(([field]) => field.startsWith('details.models.'))
                .map(([field, messages]) => (
                    <p key={field} className="text-destructive text-sm">
                        {messages[0]}
                    </p>
                ))}
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <p className="font-semibold">Model</p>
                    <p className="text-muted-foreground text-sm">
                        {items.length} model dimuat untuk pabrikan ini.
                    </p>
                </div>
                {canCreate && (
                    <ActionButton
                        action="create"
                        type="button"
                        onClick={() => setEditing(null)}
                    />
                )}
            </div>
            {/* Keadaan memuat diperiksa lebih dulu daripada pesan kesalahan supaya
                tombol "Coba lagi" langsung terlihat bekerja: pesan lama tidak lagi
                menutupi permintaan yang sedang berjalan. */}
            {loading ? (
                <Empty>
                    <EmptyDescription>Memuat model…</EmptyDescription>
                </Empty>
            ) : list.error ? (
                <Empty>
                    <EmptyHeader>
                        <EmptyTitle>Model belum dapat ditampilkan</EmptyTitle>
                        <EmptyDescription>{list.error}</EmptyDescription>
                    </EmptyHeader>
                    <Button variant="outline" onClick={muatUlang}>
                        Coba lagi
                    </Button>
                </Empty>
            ) : items.length === 0 ? (
                <Empty>
                    <EmptyHeader>
                        <EmptyTitle>Belum ada model</EmptyTitle>
                        <EmptyDescription>
                            Tambahkan model pertama untuk pabrikan ini.
                        </EmptyDescription>
                    </EmptyHeader>
                </Empty>
            ) : (
                <DataTable
                    columns={columns}
                    incremental={list}
                    sort={list.sort}
                    onSortChange={list.onSortChange}
                    data={items}
                    getRowKey={(item) => item.id}
                    getRowLabel={(item) => item.nama}
                    actions={actions}
                    onRowAction={(action, item) => {
                        if (action === 'edit') {
                            setEditing(item);
                        }

                        if (action === 'archive') {
                            setArchiving(item);
                        }
                    }}
                />
            )}

            {editing !== undefined && (
                <ModelAsetFormSheet
                    onDelete={
                        editing && canArchive
                            ? () => setArchiving(editing)
                            : undefined
                    }
                    key={editing?.id ?? 'baru'}
                    manufacturer={manufacturer}
                    value={editing}
                    canReadJenis={canReadJenis}
                    onClose={closeModel}
                    onNew={canCreate ? createModel : undefined}
                    navigation={modelNavigation}
                    onDirtyChange={setModelDirty}
                    onSaved={(row) => {
                        publishModels(
                            [
                                ...changedModels.filter(
                                    (item) => item.id !== row.id,
                                ),
                                row,
                            ],
                            deletedModels,
                        );
                        setEditing(undefined);
                        setModelDirty(false);
                    }}
                />
            )}

            <AlertDialog
                open={archiving !== null}
                onOpenChange={(open) => !open && setArchiving(null)}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Delete {archiving?.nama}?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            Model ini tidak lagi tampil pada daftar pilihan.
                            Aset yang sudah memakainya tidak berubah.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Batal</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={(event) => {
                                event.preventDefault();
                                void archive();
                            }}
                            asChild
                        >
                            <ActionButton action="archive" />
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
}
