import { ActionButton } from '@apperp/ui/action-button';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import { DataTable } from '@apperp/ui/data-table';
import type { DataTableColumn } from '@apperp/ui/data-table';
import { Input } from '@apperp/ui/input';
import { ListPageLayout } from '@apperp/ui/list-page-layout';
import { Select } from '@apperp/ui/select';
import { Tabs, TabsList, TabsTrigger } from '@apperp/ui/tabs';
import { useIncrementalList } from '@apperp/ui/use-incremental-list';
import { Head } from '@inertiajs/react';
import { Settings2 } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import type { BreadcrumbItem } from '@/types/navigation';
import { CatalogForm } from './catalog-form';
import { catalogLoader, labels } from './types';
import type { Catalog, Choice, Conversion, Draft, Unit } from './types';

const identityColumns: DataTableColumn<Choice>[] = [
    {
        id: 'code',
        header: 'Kode',
        cell: (row) => row.code,
        sortValue: (row) => row.code,
        width: 150,
    },
    {
        id: 'name',
        header: 'Nama',
        cell: (row) => row.name,
        sortValue: (row) => row.name,
        width: 260,
    },
];
const statusColumn: DataTableColumn<Choice> = {
    id: 'active',
    header: 'Status',
    cell: (row) => (
        <Badge variant="outline">{row.active ? 'Aktif' : 'Nonaktif'}</Badge>
    ),
    sortValue: (row) => Number(row.active),
    width: 120,
};
const unitColumns: DataTableColumn<Unit>[] = [
    ...identityColumns,
    {
        id: 'class_name',
        header: 'Kelas',
        cell: (row) => row.class_name,
        sortValue: (row) => row.class_name,
        width: 200,
    },
    {
        id: 'system_name',
        header: 'Sistem',
        cell: (row) => row.system_name ?? '-',
        sortValue: (row) => row.system_name ?? '',
        width: 180,
    },
    {
        id: 'symbol',
        header: 'Simbol',
        cell: (row) => row.symbol ?? '-',
        sortValue: (row) => row.symbol ?? '',
        width: 110,
    },
    {
        id: 'decimal_places',
        header: 'Angka desimal',
        cell: (row) => row.decimal_places,
        sortValue: (row) => row.decimal_places,
        width: 150,
    },
    statusColumn,
];
const conversionColumns: DataTableColumn<Conversion>[] = [
    {
        id: 'from_code',
        header: 'Kode asal',
        cell: (row) => row.from_code,
        sortValue: (row) => row.from_code,
        width: 140,
    },
    {
        id: 'from_name',
        header: 'Dari satuan',
        cell: (row) => row.from_name,
        sortValue: (row) => row.from_name,
        width: 220,
    },
    {
        id: 'to_code',
        header: 'Kode tujuan',
        cell: (row) => row.to_code,
        sortValue: (row) => row.to_code,
        width: 140,
    },
    {
        id: 'to_name',
        header: 'Ke satuan',
        cell: (row) => row.to_name,
        sortValue: (row) => row.to_name,
        width: 220,
    },
    {
        id: 'factor',
        header: 'Faktor',
        cell: (row) => row.factor,
        sortValue: (row) => Number(row.factor),
        width: 130,
    },
    {
        id: 'offset',
        header: 'Pergeseran',
        cell: (row) => row.offset,
        sortValue: (row) => Number(row.offset),
        width: 130,
    },
    {
        id: 'rounding_scale',
        header: 'Pembulatan desimal',
        cell: (row) => row.rounding_scale ?? 'Tanpa pembulatan',
        sortValue: (row) => row.rounding_scale ?? -1,
        width: 200,
    },
];

export default function UnitsOfMeasure(props: {
    canManage: boolean;
    scopeKey: string;
}) {
    return <UnitsPage key={props.scopeKey} canManage={props.canManage} />;
}
function UnitsPage({ canManage }: { canManage: boolean }) {
    const [catalog, setCatalog] = useState<Catalog>('units');
    const [visited, setVisited] = useState<Set<Catalog>>(new Set(['units']));
    const [draft, setDraft] = useState<Draft | null>(null);
    const [saved, setSaved] = useState<{
        kind: Catalog;
        record: Unit | Choice | Conversion;
    } | null>(null);
    const startCreate = () =>
        setDraft(
            catalog === 'units'
                ? { kind: 'units', record: null }
                : { kind: catalog },
        );
    const tabs = (
        <Tabs
            value={catalog}
            onValueChange={(value) => {
                if (
                    value === 'units' ||
                    value === 'classes' ||
                    value === 'systems' ||
                    value === 'conversions'
                ) {
                    setCatalog(value);
                    setVisited((current) => new Set([...current, value]));
                }
            }}
        >
            <TabsList className="min-h-9 max-w-full flex-wrap justify-start group-data-[orientation=horizontal]/tabs:h-auto">
                {Object.entries(labels).map(([key, label]) => (
                    <TabsTrigger key={key} value={key}>
                        {label}
                    </TabsTrigger>
                ))}
            </TabsList>
        </Tabs>
    );

    return (
        <>
            <Head title="Satuan" />
            <ListPageLayout
                header={
                    <div className="flex flex-wrap items-center justify-between gap-3 border-b px-4 py-3">
                        <h1 className="text-xl font-semibold">Satuan</h1>
                        {canManage &&
                            (catalog === 'conversions' ? (
                                <Button variant="outline" onClick={startCreate}>
                                    <Settings2 />
                                    Atur konversi
                                </Button>
                            ) : (
                                <ActionButton
                                    action="create"
                                    onClick={startCreate}
                                >
                                    Tambah {labels[catalog].toLowerCase()}
                                </ActionButton>
                            ))}
                    </div>
                }
            >
                {visited.has('units') && (
                    <CatalogTable<Unit>
                        catalog="units"
                        active={catalog === 'units'}
                        tabs={tabs}
                        columns={unitColumns}
                        saved={
                            saved?.kind === 'units' && 'version' in saved.record
                                ? saved.record
                                : null
                        }
                        onEdit={
                            canManage
                                ? (record) =>
                                      setDraft({ kind: 'units', record })
                                : undefined
                        }
                    />
                )}
                {visited.has('classes') && (
                    <CatalogTable<Choice>
                        catalog="classes"
                        active={catalog === 'classes'}
                        tabs={tabs}
                        columns={[...identityColumns, statusColumn]}
                        saved={
                            saved?.kind === 'classes' && 'code' in saved.record
                                ? saved.record
                                : null
                        }
                    />
                )}
                {visited.has('systems') && (
                    <CatalogTable<Choice>
                        catalog="systems"
                        active={catalog === 'systems'}
                        tabs={tabs}
                        columns={[...identityColumns, statusColumn]}
                        saved={
                            saved?.kind === 'systems' && 'code' in saved.record
                                ? saved.record
                                : null
                        }
                    />
                )}
                {visited.has('conversions') && (
                    <CatalogTable<Conversion>
                        catalog="conversions"
                        active={catalog === 'conversions'}
                        tabs={tabs}
                        columns={conversionColumns}
                        saved={
                            saved?.kind === 'conversions' &&
                            'factor' in saved.record
                                ? saved.record
                                : null
                        }
                    />
                )}
            </ListPageLayout>
            {draft && (
                <CatalogForm
                    draft={draft}
                    onClose={() => setDraft(null)}
                    onSaved={(record) => {
                        setSaved({ kind: draft.kind, record });
                        setDraft(null);
                    }}
                />
            )}
        </>
    );
}
function CatalogTable<T extends { id: string }>({
    catalog,
    active,
    tabs,
    columns,
    onEdit,
    saved,
}: {
    catalog: Catalog;
    active: boolean;
    tabs: ReactNode;
    columns: DataTableColumn<T>[];
    onEdit?: (record: T) => void;
    saved: T | null;
}) {
    const [query, setQuery] = useState('');
    const [status, setStatus] = useState('all');
    const loadItems = useMemo(
        () =>
            catalogLoader<T>(
                catalog,
                status === 'all' || catalog === 'conversions'
                    ? {}
                    : { active: status === 'active' ? '1' : '0' },
            ),
        [catalog, status],
    );
    const list = useIncrementalList({
        loadItems,
        query,
        active,
        getKey: (row) => row.id,
    });
    const appliedRecord = useRef<T | null>(null);
    useEffect(() => {
        if (saved && appliedRecord.current !== saved) {
            appliedRecord.current = saved;
            list.setItems((rows) => [
                saved,
                ...rows.filter((row) => row.id !== saved.id),
            ]);
            list.reload();
        }
    }, [saved, list]);

    return (
        <div
            className={
                active
                    ? 'flex min-h-0 flex-1 flex-col overflow-hidden'
                    : 'hidden'
            }
        >
            <div className="shrink-0 space-y-3 border-b p-4">
                {tabs}
                <div className="flex flex-col gap-3 sm:flex-row">
                    <div className="w-full sm:max-w-sm">
                        <Input
                            aria-label={`Cari ${labels[catalog].toLowerCase()}`}
                            placeholder="Cari kode atau nama..."
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                        />
                    </div>
                    {catalog !== 'conversions' && (
                        <div className="w-full sm:w-44">
                            <Select
                                label="Status"
                                value={status}
                                onValueChange={(value) =>
                                    setStatus(value ?? 'all')
                                }
                                items={[
                                    { value: 'all', label: 'Semua' },
                                    { value: 'active', label: 'Aktif' },
                                    { value: 'inactive', label: 'Nonaktif' },
                                ]}
                            />
                        </div>
                    )}
                </div>
            </div>
            <DataTable
                columns={columns}
                data={list.items}
                getRowKey={(row) => row.id}
                incremental={list}
                serverSort
                sort={list.sort}
                onSortChange={list.onSortChange}
                actions={onEdit ? [{ id: 'edit', label: 'Ubah' }] : []}
                actionsPlacement="inline"
                onRowAction={(_, record) => onEdit?.(record)}
                onRowClick={onEdit}
                emptyMessage={`Belum ada ${labels[catalog].toLowerCase()} yang cocok.`}
            />
        </div>
    );
}
UnitsOfMeasure.layout = {
    breadcrumbs: [
        { title: 'Satuan', href: '/settings/units-of-measure' },
    ] satisfies BreadcrumbItem[],
};
