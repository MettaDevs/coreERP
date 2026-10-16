import { useMemo, useState } from 'react';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
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
import { api, errorMessage } from '../api';
import { useRecordList } from '../use-record-list';
import GroupBookMatrix from './GroupBookMatrix';
import MasterForm from './MasterForm';
import type {
    MasterAction,
    MasterConfig,
    MasterRecord,
    ParentSummary,
    Permission,
} from './masters';
import { parentSummaryOf, permission } from './masters';
import MasterSelect from './MasterSelect';
import TipeAtributNilai from './TipeAtributNilai';

const optionLabel = (option: ParentSummary) =>
    `${option.kode} — ${option.nama}`;
const allLabel = (label: string) => `Semua ${label.toLowerCase()}`;

/**
 * Bagian yang disunting di dalam form pemiliknya, bukan sebagai menu tersendiri.
 *
 * Seluruhnya butuh id pemilik, jadi baru muncul setelah record tersimpan. Pilihan nilai
 * hanya relevan untuk atribut teks; ada atau tidaknya Values menentukan apakah form
 * aset memakai dropdown atau teks bebas.
 */
function extraSectionFor(
    resource: string,
    record: MasterRecord,
    canEdit: boolean,
    onVersionChange: (version: number) => void,
) {
    // Rincian disimpan dengan mengklaim versi record pemiliknya, jadi versi itu satu dan
    // dipegang bersama form: simpan rincian lalu simpan form tidak saling menolak.
    if (resource === 'group-aset') {
        return (
            <GroupBookMatrix
                groupId={record.id}
                canEdit={canEdit}
                version={record.version}
                onVersionChange={onVersionChange}
            />
        );
    }

    if (resource === 'tipe-atribut' && record.data_type === 'string') {
        return (
            <TipeAtributNilai
                tipeAtributId={record.id}
                canEdit={canEdit}
                version={record.version}
                onVersionChange={onVersionChange}
            />
        );
    }

    return undefined;
}

export default function MasterPage({
    config,
    permissions,
}: {
    config: MasterConfig;
    permissions: Permission[];
}) {
    const parents = useMemo(() => config.parents ?? [], [config.parents]);
    const can = (action: MasterAction) =>
        permissions.includes(permission(config.resource, action));
    const [search, setSearch] = useState('');
    const [activeFilter, setActiveFilter] = useState('semua');
    /** Filter induk per kolom foreign key; beberapa induk dapat disaring sekaligus. */
    const [parentFilter, setParentFilter] = useState<Record<string, string>>(
        {},
    );
    const [editing, setEditing] = useState<MasterRecord | null | undefined>();
    const [error, setError] = useState('');
    const [picked, setPicked] = useState<string[]>([]);

    const query = useMemo(() => {
        const params = new URLSearchParams();

        if (activeFilter !== 'semua') {
            params.set('aktif', activeFilter);
        }

        // Filter induk bersifat aditif: server menerapkan seluruhnya sekaligus.
        for (const parent of parents) {
            const selected = parentFilter[parent.field];

            if (selected && selected !== 'semua') {
                params.set(parent.field, selected);
            }
        }

        return params.toString();
    }, [activeFilter, parentFilter, parents]);

    const list = useRecordList<MasterRecord>(
        `/${config.resource}?${query}`,
        search,
    );
    const { items, setItems, loading } = list;
    const load = () => {
        setError('');
        list.reload();
    };

    // Pilihan hanya berlaku atas baris yang sedang tampil. Dengan menyaringnya saat
    // render, ganti filter atau ganti halaman membuat sisa pilihan lama gugur sendiri
    // begitu daftar barunya masuk — tanpa effect yang menyetel ulang state.
    const selectedIds = useMemo(
        () => picked.filter((id) => items.some((item) => item.id === id)),
        [picked, items],
    );

    async function toggle(item: MasterRecord) {
        try {
            await api(`/${config.resource}/${item.id}`, {
                method: 'PATCH',
                body: JSON.stringify({
                    aktif: !item.aktif,
                    version: item.version,
                }),
            });
            load();
        } catch (caught) {
            setError(errorMessage(caught, 'Status belum dapat diubah.'));
        }
    }

    async function archive(item: MasterRecord) {
        if (
            !window.confirm(
                `Arsipkan ${item.nama}? Data ini tidak lagi tampil pada daftar pilihan.`,
            )
        ) {
            return;
        }

        try {
            await api(`/${config.resource}/${item.id}`, {
                method: 'DELETE',
                body: JSON.stringify({ version: item.version }),
            });
            load();
        } catch (caught) {
            setError(errorMessage(caught, 'Data belum dapat diarsipkan.'));
        }
    }

    async function changeSelected(aktif: boolean) {
        const selected = items.filter(
            (item) => selectedIds.includes(item.id) && item.aktif !== aktif,
        );

        if (!selected.length) {
            return;
        }

        const action = aktif ? 'Aktifkan' : 'Nonaktifkan';

        if (!window.confirm(`${action} ${selected.length} data terpilih?`)) {
            return;
        }

        const results = await Promise.allSettled(
            selected.map((item) =>
                api(`/${config.resource}/${item.id}`, {
                    method: 'PATCH',
                    body: JSON.stringify({ aktif, version: item.version }),
                }),
            ),
        );
        const failed = results.filter(
            (result) => result.status === 'rejected',
        ).length;
        setPicked([]);

        if (failed) {
            setError(`${failed} data belum dapat diubah.`);
        }

        load();
    }

    async function archiveSelected() {
        const selected = items.filter((item) => selectedIds.includes(item.id));

        if (
            !selected.length ||
            !window.confirm(
                `Arsipkan ${selected.length} data terpilih? Data ini tidak lagi tampil pada daftar pilihan.`,
            )
        ) {
            return;
        }

        const results = await Promise.allSettled(
            selected.map((item) =>
                api(`/${config.resource}/${item.id}`, {
                    method: 'DELETE',
                    body: JSON.stringify({ version: item.version }),
                }),
            ),
        );
        const failed = results.filter(
            (result) => result.status === 'rejected',
        ).length;
        setPicked([]);

        if (failed) {
            setError(`${failed} data belum dapat diarsipkan.`);
        }

        load();
    }

    /** Versi baru sesudah rincian disimpan, untuk form yang terbuka dan baris daftarnya. */
    function changeVersion(id: string, version: number) {
        setEditing((current) =>
            current?.id === id ? { ...current, version } : current,
        );
        setItems((current) =>
            current.map((item) =>
                item.id === id ? { ...item, version } : item,
            ),
        );
    }

    const typeLabel: Record<string, string> = {
        string: 'Teks',
        decimal: 'Desimal',
        integer: 'Bilangan bulat',
        date: 'Tanggal',
        boolean: 'Ya/tidak',
    };
    const attributeColumns: DataTableColumn<MasterRecord>[] =
        config.resource === 'tipe-atribut'
            ? [
                  {
                      id: 'data_type',
                      header: 'Tipe data',
                      cell: (item) =>
                          typeLabel[String(item.data_type)] ??
                          String(item.data_type),
                      width: 150,
                  },
                  {
                      id: 'satuan',
                      header: 'Satuan',
                      cell: (item) => (
                          <span className="muted">
                              {String(item.satuan ?? '—')}
                          </span>
                      ),
                      width: 110,
                  },
                  {
                      id: 'values',
                      header: 'Values',
                      cell: (item) => Number(item.values_count ?? 0),
                      width: 90,
                  },
                  {
                      id: 'jenis-aset',
                      header: 'Jenis aset',
                      cell: (item) => Number(item.jenis_aset_count ?? 0),
                      width: 110,
                  },
              ]
            : [];
    const columns: DataTableColumn<MasterRecord>[] = [
        {
            id: 'kode',
            header: config.kodeLabel,
            cell: (item) => <span className="code">{item.kode}</span>,
            sortValue: (item) => item.kode,
            width: 170,
        },
        {
            id: 'nama',
            header: config.namaLabel,
            cell: (item) => <span className="name">{item.nama}</span>,
            sortValue: (item) => item.nama,
            width: 260,
        },
        ...parents.map((parent) => ({
            id: `parent-${parent.field}`,
            header: parent.label,
            cell: (item: MasterRecord) => {
                const summary = parentSummaryOf(item, parent);

                return (
                    <span className="muted">
                        {summary ? optionLabel(summary) : '—'}
                    </span>
                );
            },
            width: 220,
        })),
        ...attributeColumns,
        {
            id: 'keterangan',
            header: 'Keterangan',
            cell: (item) => (
                <span className="muted">{item.keterangan || '—'}</span>
            ),
            width: 260,
        },
        {
            id: 'status',
            header: 'Status',
            cell: (item) => (
                <Badge variant={item.aktif ? 'default' : 'secondary'}>
                    {item.aktif ? 'Aktif' : 'Tidak aktif'}
                </Badge>
            ),
            width: 120,
        },
    ];
    const rowActions: DataTableRowAction[] = [];

    if (can('update')) {
        rowActions.push(
            { id: 'edit', label: 'Ubah' },
            { id: 'toggle', label: 'Ubah status' },
        );
    }

    if (can('archive')) {
        rowActions.push({
            id: 'archive',
            label: 'Arsipkan',
            destructive: true,
            separatorBefore: rowActions.length > 0,
        });
    }

    return (
        <div className="h-full min-h-0 overflow-hidden">
            <Card
                aria-labelledby="list-title"
                className="flex h-full min-h-0 flex-col overflow-hidden rounded-none border-0 shadow-none"
            >
                <CardHeader className="min-h-0 shrink-0 border-b px-5 py-3">
                    <CardTitle id="list-title" className="text-base">
                        {config.title}
                    </CardTitle>
                    {can('create') && (
                        <CardAction>
                            <Button onClick={() => setEditing(null)}>
                                ＋ Tambah {config.singular}
                            </Button>
                        </CardAction>
                    )}
                </CardHeader>

                <CardContent className="flex min-h-0 flex-1 flex-col px-0">
                    <div className="flex shrink-0 flex-col gap-3 border-b px-5 py-3 sm:flex-row sm:items-end sm:justify-between">
                        <div className="space-y-1">
                            <p className="font-semibold">
                                Daftar {config.singular}
                            </p>
                            <p className="text-muted-foreground text-sm">
                                {items.length} data dimuat
                            </p>
                        </div>
                        <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row">
                            <Input
                                className="sm:w-70 w-full"
                                type="search"
                                placeholder="Cari kode atau nama"
                                aria-label={`Cari ${config.singular}`}
                                value={search}
                                onChange={(event) => {
                                    setSearch(event.target.value);
                                    // Nomor halaman disetel ulang di tempat filternya
                                    // diubah, bukan lewat effect susulan.
                                }}
                            />
                            {parents.map((parent) => (
                                <div
                                    key={parent.field}
                                    className="w-full sm:w-52"
                                >
                                    <MasterSelect
                                        resource={parent.resource}
                                        label={parent.label}
                                        value={
                                            parentFilter[parent.field] ===
                                            'semua'
                                                ? ''
                                                : (parentFilter[parent.field] ??
                                                  '')
                                        }
                                        emptyLabel={allLabel(parent.label)}
                                        onValueChange={(id) =>
                                            setParentFilter((current) => ({
                                                ...current,
                                                [parent.field]: id || 'semua',
                                            }))
                                        }
                                    />
                                </div>
                            ))}
                            <div className="w-full sm:w-44">
                                <Select
                                    items={[
                                        'Semua status',
                                        'Aktif',
                                        'Tidak aktif',
                                    ]}
                                    value={
                                        activeFilter === 'true'
                                            ? 'Aktif'
                                            : activeFilter === 'false'
                                              ? 'Tidak aktif'
                                              : 'Semua status'
                                    }
                                    searchPlaceholder="Cari status"
                                    emptyMessage="Status tidak ditemukan."
                                    ariaLabel="Saring berdasarkan status"
                                    onValueChange={(item) => {
                                        setActiveFilter(
                                            item === 'Aktif'
                                                ? 'true'
                                                : item === 'Tidak aktif'
                                                  ? 'false'
                                                  : 'semua',
                                        );
                                    }}
                                />
                            </div>
                        </div>
                    </div>
                    {selectedIds.length > 0 && (
                        <div className="bg-muted/40 flex shrink-0 flex-wrap items-center justify-between gap-2 border-b px-5 py-2 text-sm">
                            <span>{selectedIds.length} data dipilih</span>
                            <div className="flex gap-2">
                                {can('update') && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => changeSelected(false)}
                                    >
                                        Nonaktifkan
                                    </Button>
                                )}
                                {can('archive') && (
                                    <Button
                                        variant="destructive"
                                        size="sm"
                                        onClick={archiveSelected}
                                    >
                                        Arsipkan
                                    </Button>
                                )}
                            </div>
                        </div>
                    )}
                    {error ? (
                        <Empty>
                            <EmptyHeader>
                                <EmptyTitle>
                                    Data belum dapat ditampilkan
                                </EmptyTitle>
                                <EmptyDescription>
                                    {error || list.error}
                                </EmptyDescription>
                            </EmptyHeader>
                            <Button variant="outline" onClick={load}>
                                Coba lagi
                            </Button>
                        </Empty>
                    ) : loading && !items.length ? (
                        <Empty>
                            <EmptyDescription>
                                Memuat {config.singular}…
                            </EmptyDescription>
                        </Empty>
                    ) : items.length === 0 ? (
                        <Empty>
                            <EmptyHeader>
                                <EmptyTitle>
                                    Belum ada {config.singular}
                                </EmptyTitle>
                                <EmptyDescription>
                                    Tambahkan data pertama agar pilihan pada
                                    bagian lain sudah tersedia.
                                </EmptyDescription>
                            </EmptyHeader>
                        </Empty>
                    ) : (
                        <DataTable
                            scrollMode="viewport"
                            className="min-h-0 flex-1"
                            columns={columns}
                            incremental={list}
                            sort={list.sort}
                            onSortChange={list.onSortChange}
                            data={items}
                            getRowKey={(item) => item.id}
                            getRowLabel={(item) => item.nama}
                            actions={rowActions}
                            onRowAction={(action, item) => {
                                if (action === 'edit') {
                                    setEditing(item);
                                }

                                if (action === 'toggle') {
                                    toggle(item);
                                }

                                if (action === 'archive') {
                                    archive(item);
                                }
                            }}
                        />
                    )}
                </CardContent>
            </Card>

            {editing !== undefined && (
                <MasterForm
                    config={config}
                    value={editing}
                    onClose={() => setEditing(undefined)}
                    key={editing?.id ?? 'baru'}
                    onSaved={(savedRecord, created) => {
                        // Form hanya tetap terbuka bila ada rincian lanjutan yang
                        // baru dapat diisi setelah record pemiliknya tersimpan.
                        const keepOpen =
                            created &&
                            Boolean(
                                extraSectionFor(
                                    config.resource,
                                    savedRecord,
                                    can('update'),
                                    (version) =>
                                        changeVersion(savedRecord.id, version),
                                ),
                            );
                        setEditing(keepOpen ? savedRecord : undefined);
                        void load();
                    }}
                    extraSection={
                        editing
                            ? extraSectionFor(
                                  config.resource,
                                  editing,
                                  can('update'),
                                  (version) =>
                                      changeVersion(editing.id, version),
                              )
                            : undefined
                    }
                />
            )}
        </div>
    );
}
