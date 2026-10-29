import { ActionButton } from '@apperp/ui/action-button';
import { Badge } from '@apperp/ui/badge';
import { DataTable } from '@apperp/ui/data-table';
import type { DataTableColumn } from '@apperp/ui/data-table';
import { FieldLabel } from '@apperp/ui/field';
import { FormControl, FormGrid } from '@apperp/ui/form-control';
import { Input } from '@apperp/ui/input';
import { ListPageLayout } from '@apperp/ui/list-page-layout';
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
import { useCallback, useState } from 'react';
import { toast } from 'sonner';
import { useSaveFeedback } from '@/hooks/use-save-feedback';
import { apiJson, apiRequest } from '@/lib/core-api';
import type { BreadcrumbItem } from '@/types/navigation';

export type PaymentMethodRecord = {
    id: string;
    code: string;
    name: string;
    description: string | null;
    active: boolean;
    version: number;
};

type CreateCardProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onCreated: (record: PaymentMethodRecord) => void;
    initialCode?: string;
    initialName?: string;
};

export function PaymentMethodCreateCard({
    open,
    onOpenChange,
    onCreated,
    initialCode,
    initialName,
}: CreateCardProps) {
    return open ? (
        <PaymentMethodCard
            onClose={() => onOpenChange(false)}
            onSaved={(record) => {
                onCreated(record);
                onOpenChange(false);
            }}
            initialCode={initialCode}
            initialName={initialName}
            canManage
        />
    ) : null;
}

export function PaymentMethodCard({
    record,
    initialCode = '',
    initialName = '',
    canManage,
    onClose,
    onSaved,
}: {
    record?: PaymentMethodRecord;
    initialCode?: string;
    initialName?: string;
    canManage: boolean;
    onClose: () => void;
    onSaved: (record: PaymentMethodRecord) => void;
}) {
    const initial = {
        code: record?.code ?? initialCode,
        name: record?.name ?? initialName,
        description: record?.description ?? '',
        active: record?.active ?? true,
    };
    const [draft, setDraft] = useState(initial);
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

    async function save() {
        setSaving(true);
        clear();

        try {
            const result = await apiJson<{ data: PaymentMethodRecord }>(
                record
                    ? `/api/v1/payment-methods/${record.id}`
                    : '/api/v1/payment-methods',
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
                                  description: draft.description,
                                  active: draft.active,
                                  version: record.version,
                              }
                            : {
                                  code: draft.code,
                                  name: draft.name,
                                  description: draft.description,
                                  active: draft.active,
                              },
                    ),
                },
            );
            onSaved(result.data);
            toast.success('Metode pembayaran disimpan.');
        } catch (error) {
            report(error, 'Metode pembayaran belum dapat disimpan.');
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
                    title="Metode pembayaran"
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
                                        placeholder="Contoh: TRANSFER"
                                    />
                                </FormControl>
                                <FormControl data-error-field="name">
                                    <Input
                                        label="Nama metode pembayaran"
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
                                        placeholder="Contoh: Transfer Bank"
                                    />
                                </FormControl>
                                <FormControl data-error-field="description">
                                    <Textarea
                                        label="Keterangan"
                                        value={draft.description}
                                        maxLength={2000}
                                        readOnly={!canManage}
                                        onChange={(event) =>
                                            setDraft({
                                                ...draft,
                                                description: event.target.value,
                                            })
                                        }
                                        placeholder="Catatan tambahan mengenai metode pembayaran"
                                    />
                                </FormControl>
                                <FormControl data-error-field="active">
                                    {canManage ? (
                                        <>
                                            <FieldLabel htmlFor="payment-method-active">
                                                Aktif
                                            </FieldLabel>
                                            <Switch
                                                id="payment-method-active"
                                                checked={draft.active}
                                                onCheckedChange={(active) =>
                                                    setDraft({
                                                        ...draft,
                                                        active,
                                                    })
                                                }
                                            />
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

const columns: DataTableColumn<PaymentMethodRecord>[] = [
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
        header: 'Nama metode pembayaran',
        minWidth: 280,
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

export default function PaymentMethodsPage({
    canManage = false,
}: {
    canManage?: boolean;
}) {
    const [query, setQuery] = useState('');
    const [card, setCard] = useState<{ record?: PaymentMethodRecord } | null>(
        null,
    );
    const [deleting, setDeleting] = useState(false);
    const listFeedback = useSaveFeedback('payment-method-list');
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
                data: PaymentMethodRecord[];
                next_cursor: string | null;
            }>(`/api/v1/payment-methods?${params}`, { signal });

            return { items: result.data, nextCursor: result.next_cursor };
        },
        [],
    );

    const list = useIncrementalList({
        loadItems,
        query,
        getKey: (record) => record.id,
    });

    async function archive(record: PaymentMethodRecord) {
        if (
            !(await confirm({
                title: `Delete metode pembayaran ${record.name}?`,
                description:
                    'Referensi pada dokumen lama tetap disimpan. Metode pembayaran ini tidak lagi muncul pada pilihan baru.',
                confirmLabel: 'Delete',
                destructive: true,
            }))
        ) {
            return;
        }

        setDeleting(true);
        listFeedback.clear();

        try {
            await apiRequest(`/api/v1/payment-methods/${record.id}`, {
                method: 'DELETE',
                headers: { 'If-Match': `W/"${record.version}"` },
            });
            list.reload();
        } catch (error) {
            listFeedback.report(
                error,
                'Metode pembayaran belum dapat dihapus.',
            );
        } finally {
            setDeleting(false);
        }
    }

    return (
        <>
            <Head title="Metode pembayaran" />
            {confirmation}
            <ListPageLayout
                header={
                    <RecordActionBar
                        title="Metode pembayaran"
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
                                label="Cari metode pembayaran"
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
                        } else {
                            setCard({ record });
                        }
                    }}
                    emptyMessage={
                        query
                            ? 'Tidak ada metode pembayaran yang cocok.'
                            : 'Belum ada metode pembayaran.'
                    }
                />
            </ListPageLayout>
            {card && (
                <PaymentMethodCard
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

PaymentMethodsPage.layout = {
    breadcrumbs: [
        { title: 'Metode pembayaran', href: '/settings/payment-methods' },
    ] satisfies BreadcrumbItem[],
};
