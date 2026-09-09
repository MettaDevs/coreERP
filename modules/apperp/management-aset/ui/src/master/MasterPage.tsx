import { useEffect, useMemo, useState } from 'react';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import {
    Card,
    CardAction,
    CardContent,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@apperp/ui/card';
import {
    DataTable,
    type DataTableColumn,
    type DataTableRowAction,
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
import GroupBookMatrix from './GroupBookMatrix';
import MasterForm from './MasterForm';
import TipeAtributNilai from './TipeAtributNilai';
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
) {
    if (resource === 'group-aset')
        return <GroupBookMatrix groupId={record.id} canEdit={canEdit} />;
    if (resource === 'tipe-atribut' && record.data_type === 'string') {
        return <TipeAtributNilai tipeAtributId={record.id} canEdit={canEdit} />;
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
    const [items, setItems] = useState<MasterRecord[]>([]);
    const [meta, setMeta] = useState<ListMeta>(emptyMeta);
    const [search, setSearch] = useState('');
    const [activeFilter, setActiveFilter] = useState('semua');
    /** Filter induk per kolom foreign key; beberapa induk dapat disaring sekaligus. */
    const [parentFilter, setParentFilter] = useState<Record<string, string>>(
        {},
    );
    const [page, setPage] = useState(1);
    const [editing, setEditing] = useState<MasterRecord | null | undefined>();
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [parentOptions, setParentOptions] = useState<
        Record<string, ParentSummary[]>
    >({});
    const [parentOptionsError, setParentOptionsError] = useState<
        Record<string, string>
    >({});
    const [selectedIds, setSelectedIds] = useState<string[]>([]);

    const query = useMemo(() => {
        const params = new URLSearchParams({
            per_page: '20',
            page: String(page),
        });
        if (search.trim()) params.set('q', search.trim());
        if (activeFilter !== 'semua') params.set('aktif', activeFilter);
        // Filter induk bersifat aditif: server menerapkan seluruhnya sekaligus.
        for (const parent of parents) {
            const selected = parentFilter[parent.field];
            if (selected && selected !== 'semua')
                params.set(parent.field, selected);
        }
        return params.toString();
    }, [search, activeFilter, parentFilter, page, parents]);

    async function load() {
        setLoading(true);
        setError('');
        try {
            const list = await api<{ data: MasterRecord[]; meta: ListMeta }>(
                `/${config.resource}?${query}`,
            );
            setItems(list.data);
            setMeta(list.meta);
        } catch (caught) {
            setError(errorMessage(caught, 'Data belum dapat dimuat.'));
        } finally {
            setLoading(false);
        }
    }

    useEffect(() => {
        setParentOptions({});
        setParentOptionsError({});
        if (!parents.length) return;
        let cancelled = false;
        // Induk saling lepas, jadi seluruh pilihan dimuat berbarengan; gagalnya satu
        // induk tidak menghalangi induk lain tampil.
        for (const parent of parents) {
            api<{ data: MasterRecord[] }>(
                `/${parent.resource}?per_page=100&aktif=true`,
            )
                .then((result) => {
                    if (cancelled) return;
                    const options = result.data.map(({ id, kode, nama }) => ({
                        id,
                        kode,
                        nama,
                    }));
                    setParentOptions((current) => ({
                        ...current,
                        [parent.field]: options,
                    }));
                })
                .catch(() => {
                    if (cancelled) return;
                    setParentOptions((current) => ({
                        ...current,
                        [parent.field]: [],
                    }));
                    setParentOptionsError((current) => ({
                        ...current,
                        [parent.field]: `Pilihan ${parent.label.toLowerCase()} belum dapat dimuat. Anda memerlukan akses lihat untuk memilih induk.`,
                    }));
                });
        }
        return () => {
            cancelled = true;
        };
    }, [parents]);

    useEffect(() => {
        setPage(1);
    }, [search, activeFilter, parentFilter]);
    useEffect(() => {
        setSelectedIds([]);
    }, [config.resource, query]);
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
        if (
            !window.confirm(
                `Arsipkan ${item.nama}? Data ini tidak lagi tampil pada daftar pilihan.`,
            )
        )
            return;
        try {
            await api(`/${config.resource}/${item.id}`, { method: 'DELETE' });
            load();
        } catch (caught) {
            setError(errorMessage(caught, 'Data belum dapat diarsipkan.'));
        }
    }

    async function changeSelected(aktif: boolean) {
        const selected = items.filter(
            (item) => selectedIds.includes(item.id) && item.aktif !== aktif,
        );
        if (!selected.length) return;
        const action = aktif ? 'Aktifkan' : 'Nonaktifkan';
        if (!window.confirm(`${action} ${selected.length} data terpilih?`))
            return;
        const results = await Promise.allSettled(
            selected.map((item) =>
                api(`/${config.resource}/${item.id}`, {
                    method: 'PATCH',
                    body: JSON.stringify({ aktif }),
                }),
            ),
        );
        const failed = results.filter(
            (result) => result.status === 'rejected',
        ).length;
        setSelectedIds([]);
        if (failed) setError(`${failed} data belum dapat diubah.`);
        load();
    }

    async function archiveSelected() {
        const selected = items.filter((item) => selectedIds.includes(item.id));
        if (
            !selected.length ||
            !window.confirm(
                `Arsipkan ${selected.length} data terpilih? Data ini tidak lagi tampil pada daftar pilihan.`,
            )
        )
            return;
        const results = await Promise.allSettled(
            selected.map((item) =>
                api(`/${config.resource}/${item.id}`, { method: 'DELETE' }),
            ),
        );
        const failed = results.filter(
            (result) => result.status === 'rejected',
        ).length;
        setSelectedIds([]);
        if (failed) setError(`${failed} data belum dapat diarsipkan.`);
        load();
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
                      id: 'asset-types',
                      header: 'Jenis aset',
                      cell: (item) => Number(item.asset_types_count ?? 0),
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
    if (can('update'))
        rowActions.push(
            { id: 'edit', label: 'Ubah' },
            { id: 'toggle', label: 'Ubah status' },
        );
    if (can('archive'))
        rowActions.push({
            id: 'archive',
            label: 'Arsipkan',
            destructive: true,
            separatorBefore: rowActions.length > 0,
        });

    return (
        <div>
            <Card
                aria-labelledby="list-title"
                className="min-h-full rounded-none border-0 shadow-none"
            >
                <CardHeader className="min-h-0 border-b px-5 py-3">
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

                <CardContent className="px-0">
                    <div className="flex flex-col gap-3 border-b px-5 py-3 sm:flex-row sm:items-end sm:justify-between">
                        <div className="space-y-1">
                            <p className="font-semibold">
                                Daftar {config.singular}
                            </p>
                            <p className="text-muted-foreground text-sm">
                                {meta.total} data ditemukan
                            </p>
                        </div>
                        <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row">
                            <Input
                                className="sm:w-70 w-full"
                                type="search"
                                placeholder="Cari kode atau nama"
                                aria-label={`Cari ${config.singular}`}
                                value={search}
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
                            />
                            {parents.map((parent) => {
                                const options =
                                    parentOptions[parent.field] ?? [];
                                const selected = options.find(
                                    (option) =>
                                        option.id ===
                                        parentFilter[parent.field],
                                );
                                return (
                                    <div
                                        key={parent.field}
                                        className="w-full sm:w-52"
                                    >
                                        <Select
                                            items={[
                                                allLabel(parent.label),
                                                ...options.map(optionLabel),
                                            ]}
                                            value={
                                                selected
                                                    ? optionLabel(selected)
                                                    : allLabel(parent.label)
                                            }
                                            searchPlaceholder={`Cari ${parent.label.toLowerCase()}`}
                                            emptyMessage={`${parent.label} tidak ditemukan.`}
                                            ariaLabel={`Saring berdasarkan ${parent.label.toLowerCase()}`}
                                            onValueChange={(item) =>
                                                setParentFilter((current) => ({
                                                    ...current,
                                                    [parent.field]:
                                                        item ===
                                                        allLabel(parent.label)
                                                            ? 'semua'
                                                            : (options.find(
                                                                  (option) =>
                                                                      optionLabel(
                                                                          option,
                                                                      ) ===
                                                                      item,
                                                              )?.id ?? 'semua'),
                                                }))
                                            }
                                        />
                                    </div>
                                );
                            })}
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
                                    onValueChange={(item) =>
                                        setActiveFilter(
                                            item === 'Aktif'
                                                ? 'true'
                                                : item === 'Tidak aktif'
                                                  ? 'false'
                                                  : 'semua',
                                        )
                                    }
                                />
                            </div>
                        </div>
                    </div>
                    {selectedIds.length > 0 && (
                        <div className="bg-muted/40 flex flex-wrap items-center justify-between gap-2 border-b px-5 py-2 text-sm">
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
                                <EmptyDescription>{error}</EmptyDescription>
                            </EmptyHeader>
                            <Button variant="outline" onClick={load}>
                                Coba lagi
                            </Button>
                        </Empty>
                    ) : loading ? (
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
                    <CardFooter
                        className="text-muted-foreground justify-end gap-3 border-t text-sm"
                        aria-label={`Halaman daftar ${config.singular}`}
                    >
                        <Button
                            variant="outline"
                            disabled={meta.current_page <= 1}
                            onClick={() => setPage((current) => current - 1)}
                        >
                            Sebelumnya
                        </Button>
                        <span>
                            Halaman {meta.current_page} dari {meta.last_page}
                        </span>
                        <Button
                            variant="outline"
                            disabled={meta.current_page >= meta.last_page}
                            onClick={() => setPage((current) => current + 1)}
                        >
                            Berikutnya
                        </Button>
                    </CardFooter>
                )}
            </Card>

            {editing !== undefined && (
                <MasterForm
                    config={config}
                    value={editing}
                    parentOptions={parentOptions}
                    parentOptionsError={parentOptionsError}
                    onClose={() => setEditing(undefined)}
                    key={editing?.id ?? 'baru'}
                    onSaved={(savedRecord, created) => {
                        // Setelah POST, tetap buka record dengan respons lengkap agar kode
                        // otomatis dan nama langsung terlihat tanpa membuka form dua kali.
                        setEditing(created ? savedRecord : undefined);
                        void load();
                    }}
                    extraSection={
                        editing
                            ? extraSectionFor(
                                  config.resource,
                                  editing,
                                  can('update'),
                              )
                            : undefined
                    }
                />
            )}
        </div>
    );
}
