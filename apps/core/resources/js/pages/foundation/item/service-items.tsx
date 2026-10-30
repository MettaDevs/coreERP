import { ActionButton } from '@apperp/ui/action-button';
import { Badge } from '@apperp/ui/badge';
import { DataTable, DataTableText } from '@apperp/ui/data-table';
import type { DataTableColumn } from '@apperp/ui/data-table';
import { FieldLabel } from '@apperp/ui/field';
import { FormControl, FormGrid } from '@apperp/ui/form-control';
import { InlineLookup } from '@apperp/ui/inline-lookup';
import type { LookupLoader } from '@apperp/ui/inline-lookup';
import { Input } from '@apperp/ui/input';
import { ListPageLayout } from '@apperp/ui/list-page-layout';
import { NativeSelect, NativeSelectOption } from '@apperp/ui/native-select';
import {
    PageDialog,
    PageDialogBody,
    PageDialogContent,
    PageDialogToolbar,
} from '@apperp/ui/page-dialog';
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { Switch } from '@apperp/ui/switch';
import { Textarea } from '@apperp/ui/textarea';
import { useConfirmation } from '@apperp/ui/use-confirmation';
import { useIncrementalList } from '@apperp/ui/use-incremental-list';
import type { ListRequest } from '@apperp/ui/use-incremental-list';
import { useRecordTransition } from '@apperp/ui/use-record-transition';
import { Head } from '@inertiajs/react';
import { useCallback, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import { useSaveFeedback } from '@/hooks/use-save-feedback';
import { apiJson, apiRequest } from '@/lib/core-api';
import type { BreadcrumbItem } from '@/types/navigation';

export type ItemType = 'service' | 'inventory' | 'non_inventory';

export type UnitOfMeasureRef = {
    id: string;
    code: string;
    name: string;
    symbol?: string | null;
};

export type ItemRecord = {
    id: string;
    code: string;
    name: string;
    type: ItemType;
    unit_of_measure_id: string;
    unit_of_measure?: UnitOfMeasureRef | null;
    description: string | null;
    active: boolean;
    version: number;
};

export type ServiceItemRecord = ItemRecord;

type CreateCardProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onCreated: (record: ItemRecord) => void;
    initialCode?: string;
    initialName?: string;
    initialType?: ItemType;
};

export function ServiceItemCreateCard({
    open,
    onOpenChange,
    onCreated,
    initialCode,
    initialName,
    initialType,
}: CreateCardProps) {
    return open ? (
        <ServiceItemCard
            onClose={() => onOpenChange(false)}
            onSaved={(record) => {
                onCreated(record);
                onOpenChange(false);
            }}
            initialCode={initialCode}
            initialName={initialName}
            initialType={initialType}
            canManage
        />
    ) : null;
}

export function ServiceItemCard({
    record,
    initialCode = '',
    initialName = '',
    initialType = 'service',
    canManage,
    onClose,
    onSaved,
}: {
    record?: ItemRecord;
    initialCode?: string;
    initialName?: string;
    initialType?: ItemType;
    canManage: boolean;
    onClose: () => void;
    onSaved: (record: ItemRecord) => void;
}) {
    const contentRef = useRef<HTMLDivElement>(null);
    const initial = {
        code: record?.code ?? initialCode,
        name: record?.name ?? initialName,
        type: record?.type ?? initialType,
        unit_of_measure_id: record?.unit_of_measure_id ?? '',
        description: record?.description ?? '',
        active: record?.active ?? true,
    };
    const [draft, setDraft] = useState(initial);
    const [selectedUom, setSelectedUom] = useState<UnitOfMeasureRef | null>(
        record?.unit_of_measure
            ? {
                  id: record.unit_of_measure.id,
                  code: record.unit_of_measure.code,
                  name: record.unit_of_measure.name,
                  symbol: record.unit_of_measure.symbol,
              }
            : null,
    );
    const [creationKey] = useState(() => crypto.randomUUID());
    const [saving, setSaving] = useState(false);
    const { scopeRef, summary, clear, report } = useSaveFeedback(
        record?.id ?? 'new',
    );
    const transition = useRecordTransition(
        JSON.stringify(initial) !== JSON.stringify(draft),
        saving,
    );
    const close = () => transition.request(onClose);

    const loadUoms = useMemo<LookupLoader<UnitOfMeasureRef>>(() => {
        return async ({ query, cursor, signal }) => {
            const params = new URLSearchParams({
                incremental: '1',
                resource: 'units',
                status: 'active',
                q: query,
            });

            if (cursor) {
                params.set('cursor', cursor);
            }

            const result = await apiJson<{
                data: UnitOfMeasureRef[];
                next_cursor: string | null;
            }>(`/api/v1/units-of-measure?${params}`, { signal });

            return { items: result.data, nextCursor: result.next_cursor };
        };
    }, []);

    async function save() {
        setSaving(true);
        clear();

        try {
            const result = await apiJson<{ data: ItemRecord }>(
                record ? `/api/v1/items/${record.id}` : '/api/v1/items',
                {
                    method: record ? 'PATCH' : 'POST',
                    headers: record
                        ? { 'If-Match': `W/"${record.version}"` }
                        : { 'Idempotency-Key': creationKey },
                    body: JSON.stringify(
                        record
                            ? {
                                  code: draft.code,
                                  name: draft.name,
                                  type: draft.type,
                                  unit_of_measure_id: draft.unit_of_measure_id,
                                  description: draft.description,
                                  active: draft.active,
                                  version: record.version,
                              }
                            : {
                                  code: draft.code,
                                  name: draft.name,
                                  type: draft.type,
                                  unit_of_measure_id: draft.unit_of_measure_id,
                                  description: draft.description,
                                  active: draft.active,
                              },
                    ),
                },
            );
            onSaved(result.data);
            toast.success('Item / jasa disimpan.');
        } catch (error) {
            report(error, 'Item / jasa belum dapat disimpan.');
        } finally {
            setSaving(false);
        }
    }

    return (
        <>
            {transition.confirmation}
            <PageDialog
                open
                onOpenChange={(open) => {
                    if (!open) {
                        close();
                    }
                }}
            >
                <PageDialogContent
                    ref={contentRef}
                    title="Item / jasa"
                    recordTitle={record?.name ?? 'Baru'}
                    editing={canManage}
                    busy={saving}
                >
                    <form
                        noValidate
                        ref={scopeRef}
                        className="flex min-h-0 flex-1 flex-col"
                        onSubmit={(event) => {
                            event.preventDefault();

                            if (canManage && !saving) {
                                void save();
                            }
                        }}
                    >
                        <PageDialogToolbar>
                            {canManage && (
                                <ActionButton
                                    action="save"
                                    type="submit"
                                    pending={saving}
                                />
                            )}
                            <ActionButton
                                action="cancel"
                                onClick={close}
                                disabled={saving}
                            />
                        </PageDialogToolbar>
                        {summary}
                        <PageDialogBody inert={saving}>
                            <FormGrid>
                                <FormControl data-error-field="code">
                                    <Input
                                        label="Kode"
                                        required
                                        value={draft.code}
                                        maxLength={50}
                                        readOnly={!canManage || Boolean(record)}
                                        onChange={(event) =>
                                            setDraft({
                                                ...draft,
                                                code: event.target.value,
                                            })
                                        }
                                        placeholder="Contoh: JSA-001"
                                    />
                                </FormControl>
                                <FormControl data-error-field="name">
                                    <Input
                                        label="Nama item / jasa"
                                        required
                                        value={draft.name}
                                        maxLength={250}
                                        readOnly={!canManage}
                                        onChange={(event) =>
                                            setDraft({
                                                ...draft,
                                                name: event.target.value,
                                            })
                                        }
                                        placeholder="Contoh: Jasa Konsultasi IT"
                                    />
                                </FormControl>
                                <FormControl data-error-field="type">
                                    <NativeSelect
                                        label="Jenis"
                                        disabled={!canManage}
                                        value={draft.type}
                                        onChange={(event) =>
                                            setDraft({
                                                ...draft,
                                                type: event.target
                                                    .value as ItemType,
                                            })
                                        }
                                    >
                                        <NativeSelectOption value="service">
                                            Jasa
                                        </NativeSelectOption>
                                        <NativeSelectOption value="inventory">
                                            Persediaan
                                        </NativeSelectOption>
                                        <NativeSelectOption value="non_inventory">
                                            Non-persediaan
                                        </NativeSelectOption>
                                    </NativeSelect>
                                </FormControl>
                                <FormControl data-error-field="unit_of_measure_id">
                                    <InlineLookup<UnitOfMeasureRef>
                                        label="Satuan"
                                        required
                                        disabled={!canManage || saving}
                                        value={selectedUom}
                                        loadItems={loadUoms}
                                        onValueChange={(item) => {
                                            setSelectedUom(item);
                                            setDraft({
                                                ...draft,
                                                unit_of_measure_id: item.id,
                                            });
                                        }}
                                        getItemValue={(item) => item.id}
                                        getItemLabel={(item) =>
                                            item.symbol
                                                ? `${item.name} (${item.symbol})`
                                                : `${item.name} (${item.code})`
                                        }
                                        portalContainer={contentRef}
                                        placeholder="Pilih satuan..."
                                        emptyMessage="Satuan tidak ditemukan"
                                        columns={[
                                            {
                                                id: 'code',
                                                header: 'Kode',
                                                cell: (item) => (
                                                    <DataTableText>
                                                        {item.code}
                                                    </DataTableText>
                                                ),
                                                sortValue: (item) => item.code,
                                                width: 130,
                                            },
                                            {
                                                id: 'name',
                                                header: 'Nama',
                                                cell: (item) => (
                                                    <DataTableText>
                                                        {item.name}
                                                    </DataTableText>
                                                ),
                                                sortValue: (item) => item.name,
                                                width: 240,
                                            },
                                        ]}
                                    />
                                </FormControl>
                                <FormControl data-error-field="description">
                                    <Textarea
                                        label="Keterangan"
                                        rows={3}
                                        value={draft.description}
                                        maxLength={2000}
                                        readOnly={!canManage}
                                        onChange={(event) =>
                                            setDraft({
                                                ...draft,
                                                description: event.target.value,
                                            })
                                        }
                                        placeholder="Keterangan opsional..."
                                    />
                                </FormControl>
                                <FormControl data-error-field="active">
                                    {canManage ? (
                                        <>
                                            <div className="flex items-center gap-3 pt-2">
                                                <Switch
                                                    id="service-item-active"
                                                    checked={draft.active}
                                                    onCheckedChange={(
                                                        checked,
                                                    ) =>
                                                        setDraft({
                                                            ...draft,
                                                            active: checked,
                                                        })
                                                    }
                                                />
                                                <FieldLabel htmlFor="service-item-active">
                                                    Aktif
                                                </FieldLabel>
                                            </div>
                                            <p className="text-xs text-muted-foreground">
                                                Item/jasa nonaktif tidak dapat
                                                dipilih pada transaksi baru.
                                            </p>
                                        </>
                                    ) : (
                                        <Input
                                            label="Status"
                                            readOnly
                                            value={
                                                draft.active
                                                    ? 'Aktif'
                                                    : 'Nonaktif'
                                            }
                                        />
                                    )}
                                </FormControl>
                            </FormGrid>
                        </PageDialogBody>
                    </form>
                </PageDialogContent>
            </PageDialog>
        </>
    );
}

const typeLabels: Record<ItemType, string> = {
    service: 'Jasa',
    inventory: 'Persediaan',
    non_inventory: 'Non-persediaan',
};

const columns: DataTableColumn<ItemRecord>[] = [
    {
        id: 'code',
        header: 'Kode',
        width: 140,
        cell: (record) => (
            <span className="font-mono text-xs font-medium">{record.code}</span>
        ),
        sortValue: (record) => record.code,
    },
    {
        id: 'name',
        header: 'Nama item / jasa',
        minWidth: 260,
        cell: (record) => (
            <div className="space-y-0.5">
                <span className="text-sm font-medium">{record.name}</span>
                {record.description && (
                    <p className="line-clamp-1 text-xs text-muted-foreground">
                        {record.description}
                    </p>
                )}
            </div>
        ),
        sortValue: (record) => record.name,
    },
    {
        id: 'type',
        header: 'Jenis',
        width: 130,
        cell: (record) => (
            <Badge variant="outline">
                {typeLabels[record.type] ?? record.type}
            </Badge>
        ),
        sortValue: (record) => record.type,
    },
    {
        id: 'unit_of_measure',
        header: 'Satuan',
        width: 140,
        cell: (record) => (
            <span>
                {record.unit_of_measure
                    ? record.unit_of_measure.symbol
                        ? `${record.unit_of_measure.name} (${record.unit_of_measure.symbol})`
                        : `${record.unit_of_measure.name} (${record.unit_of_measure.code})`
                    : '-'}
            </span>
        ),
    },
    {
        id: 'active',
        header: 'Status',
        width: 120,
        cell: (record) => (
            <Badge variant={record.active ? 'default' : 'secondary'}>
                {record.active ? 'Aktif' : 'Nonaktif'}
            </Badge>
        ),
    },
];

export default function ServiceItemsPage({
    canManage = false,
}: {
    canManage?: boolean;
}) {
    const [query, setQuery] = useState('');
    const [card, setCard] = useState<{ record?: ItemRecord } | null>(null);
    const [deleting, setDeleting] = useState(false);
    const listFeedback = useSaveFeedback('service-item-list');
    const { confirm, confirmation } = useConfirmation();

    const loadItems = useCallback(
        async ({ query, cursor, sort, signal }: ListRequest) => {
            const params = new URLSearchParams({ q: query });

            if (cursor) {
                params.set('cursor', cursor);
            }

            if (sort) {
                params.set('sort', sort.columnId);
                params.set('direction', sort.direction);
            }

            const result = await apiJson<{
                data: ItemRecord[];
                next_cursor: string | null;
            }>(`/api/v1/items?${params}`, { signal });

            return { items: result.data, nextCursor: result.next_cursor };
        },
        [],
    );

    const list = useIncrementalList({
        loadItems,
        query,
        getKey: (record) => record.id,
    });

    async function toggleActive(record: ItemRecord) {
        listFeedback.clear();

        try {
            await apiJson<{ data: ItemRecord }>(
                `/api/v1/items/${record.id}/status`,
                {
                    method: 'PATCH',
                    headers: { 'If-Match': `W/"${record.version}"` },
                    body: JSON.stringify({ active: !record.active }),
                },
            );
            list.reload();
            toast.success(
                record.active
                    ? 'Item / jasa dinonaktifkan.'
                    : 'Item / jasa diaktifkan.',
            );
        } catch (error) {
            listFeedback.report(
                error,
                'Status item / jasa belum dapat diperbarui.',
            );
        }
    }

    async function archive(record: ItemRecord) {
        if (
            !(await confirm({
                title: `Delete item / jasa ${record.name}?`,
                description:
                    'Referensi pada dokumen lama tetap disimpan. Item/jasa ini tidak lagi muncul pada pilihan baru.',
                confirmLabel: 'Delete',
                destructive: true,
            }))
        ) {
            return;
        }

        setDeleting(true);
        listFeedback.clear();

        try {
            await apiRequest(`/api/v1/items/${record.id}`, {
                method: 'DELETE',
                headers: { 'If-Match': `W/"${record.version}"` },
            });
            list.reload();
        } catch (error) {
            listFeedback.report(error, 'Item / jasa belum dapat dihapus.');
        } finally {
            setDeleting(false);
        }
    }

    return (
        <>
            <Head title="Item / jasa" />
            {confirmation}
            <ListPageLayout
                header={
                    <RecordActionBar
                        title="Item / jasa"
                        feedback={listFeedback.summary}
                    >
                        {canManage && (
                            <ActionButton
                                action="create"
                                disabled={deleting}
                                onClick={() => setCard({})}
                            />
                        )}
                    </RecordActionBar>
                }
                filters={
                    <div className="px-4 pb-3">
                        <FormControl className="w-full sm:w-80">
                            <Input
                                label="Cari item / jasa"
                                type="search"
                                value={query}
                                onChange={(event) =>
                                    setQuery(event.target.value)
                                }
                                placeholder="Cari kode atau nama..."
                            />
                        </FormControl>
                    </div>
                }
            >
                <DataTable
                    columns={columns}
                    data={list.items}
                    incremental={list}
                    sort={list.sort}
                    onSortChange={list.onSortChange}
                    getRowKey={(record) => record.id}
                    getRowLabel={(record) => `${record.code} - ${record.name}`}
                    onRowClick={(record) => setCard({ record })}
                    actions={
                        canManage
                            ? [
                                  { id: 'edit', label: 'Ubah' },
                                  {
                                      id: 'toggle',
                                      label: 'Ubah status',
                                  },
                                  {
                                      id: 'delete',
                                      label: 'Delete',
                                      destructive: true,
                                  },
                              ]
                            : []
                    }
                    onRowAction={(action, record) => {
                        if (deleting) {
                            return;
                        }

                        if (action === 'delete') {
                            void archive(record);
                        } else if (action === 'toggle') {
                            void toggleActive(record);
                        } else {
                            setCard({ record });
                        }
                    }}
                    emptyMessage={
                        query
                            ? 'Tidak ada item / jasa yang cocok.'
                            : 'Belum ada item / jasa.'
                    }
                />
            </ListPageLayout>
            {card && (
                <ServiceItemCard
                    record={card.record}
                    canManage={canManage}
                    onClose={() => setCard(null)}
                    onSaved={() => {
                        setCard(null);
                        list.reload();
                    }}
                />
            )}
        </>
    );
}

ServiceItemsPage.layout = {
    breadcrumbs: [
        { title: 'Item / jasa', href: '/settings/service-items' },
    ] satisfies BreadcrumbItem[],
};
