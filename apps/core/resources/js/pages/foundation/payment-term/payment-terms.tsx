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

export type PaymentTermRecord = {
    id: string;
    code: string;
    name: string;
    due_days: number;
    description: string | null;
    active: boolean;
    version: number;
};

type CreateCardProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onCreated: (record: PaymentTermRecord) => void;
    initialCode?: string;
    initialName?: string;
};

export function PaymentTermCreateCard({
    open,
    onOpenChange,
    onCreated,
    initialCode,
    initialName,
}: CreateCardProps) {
    return open ? (
        <PaymentTermCard
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

export function PaymentTermCard({
    record,
    initialCode = '',
    initialName = '',
    canManage,
    onClose,
    onSaved,
}: {
    record?: PaymentTermRecord;
    initialCode?: string;
    initialName?: string;
    canManage: boolean;
    onClose: () => void;
    onSaved: (record: PaymentTermRecord) => void;
}) {
    const initial = {
        code: record?.code ?? initialCode,
        name: record?.name ?? initialName,
        due_days: record?.due_days ?? 0,
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
            const result = await apiJson<{ data: PaymentTermRecord }>(
                record
                    ? `/api/v1/payment-terms/${record.id}`
                    : '/api/v1/payment-terms',
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
                                  due_days: Number(draft.due_days) || 0,
                                  description: draft.description,
                                  active: draft.active,
                                  version: record.version,
                              }
                            : {
                                  code: draft.code,
                                  name: draft.name,
                                  due_days: Number(draft.due_days) || 0,
                                  description: draft.description,
                                  active: draft.active,
                              },
                    ),
                },
            );
            onSaved(result.data);
            toast.success('Termin pembayaran disimpan.');
        } catch (error) {
            report(error, 'Termin pembayaran belum dapat disimpan.');
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
                    title="Termin pembayaran"
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
                                        placeholder="Contoh: NET30"
                                    />
                                </FormControl>
                                <FormControl data-error-field="name">
                                    <Input
                                        label="Nama termin"
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
                                        placeholder="Contoh: Net 30 Hari"
                                    />
                                </FormControl>
                                <FormControl data-error-field="due_days">
                                    <Input
                                        label="Hari jatuh tempo"
                                        type="number"
                                        min={0}
                                        max={3650}
                                        value={String(draft.due_days)}
                                        readOnly={!canManage}
                                        onChange={(event) =>
                                            setDraft({
                                                ...draft,
                                                due_days:
                                                    Number(
                                                        event.target.value,
                                                    ) || 0,
                                            })
                                        }
                                        placeholder="Contoh: 30"
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
                                        placeholder="Keterangan opsional tentang termin pembayaran"
                                    />
                                </FormControl>
                                <FormControl data-error-field="active">
                                    <div className="flex items-center gap-3">
                                        <Switch
                                            checked={draft.active}
                                            disabled={!canManage}
                                            onCheckedChange={(checked) =>
                                                setDraft({
                                                    ...draft,
                                                    active: checked,
                                                })
                                            }
                                        />
                                        <FieldLabel>Status aktif</FieldLabel>
                                    </div>
                                </FormControl>
                            </FormGrid>
                        </PageDialogBody>
                    </form>
                </PageDialogContent>
            </PageDialog>
        </>
    );
}

const columns: DataTableColumn<PaymentTermRecord>[] = [
    {
        id: 'code',
        header: 'Kode',
        width: 140,
        cell: (record) => (
            <span className="font-mono text-xs font-semibold">
                {record.code}
            </span>
        ),
        sortValue: (record) => record.code,
    },
    {
        id: 'name',
        header: 'Nama termin pembayaran',
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
        id: 'due_days',
        header: 'Jatuh tempo',
        width: 140,
        cell: (record) => (
            <span className="text-xs">
                {record.due_days > 0
                    ? `${record.due_days} hari`
                    : '0 hari (COD / Tunai)'}
            </span>
        ),
        sortValue: (record) => record.due_days,
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

export default function PaymentTermsPage({
    canManage = false,
}: {
    canManage?: boolean;
}) {
    const [query, setQuery] = useState('');
    const [card, setCard] = useState<{ record?: PaymentTermRecord } | null>(
        null,
    );
    const [deleting, setDeleting] = useState(false);
    const listFeedback = useSaveFeedback('payment-term-list');
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
                data: PaymentTermRecord[];
                next_cursor: string | null;
            }>(`/api/v1/payment-terms?${params}`, { signal });

            return { items: result.data, nextCursor: result.next_cursor };
        },
        [],
    );

    const list = useIncrementalList({
        loadItems,
        query,
        getKey: (record) => record.id,
    });

    async function archive(record: PaymentTermRecord) {
        if (
            !(await confirm({
                title: `Delete termin pembayaran ${record.name}?`,
                description:
                    'Referensi pada dokumen lama tetap disimpan. Termin pembayaran ini tidak lagi muncul pada pilihan baru.',
                confirmLabel: 'Delete',
                destructive: true,
            }))
        ) {
            return;
        }

        setDeleting(true);
        listFeedback.clear();

        try {
            await apiRequest(`/api/v1/payment-terms/${record.id}`, {
                method: 'DELETE',
                headers: { 'If-Match': `W/"${record.version}"` },
            });
            list.reload();
        } catch (error) {
            listFeedback.report(
                error,
                'Termin pembayaran belum dapat dihapus.',
            );
        } finally {
            setDeleting(false);
        }
    }

    return (
        <>
            <Head title="Termin pembayaran" />
            {confirmation}
            <ListPageLayout
                header={
                    <RecordActionBar
                        title="Termin pembayaran"
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
                                label="Cari termin pembayaran"
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
                            ? 'Tidak ada termin pembayaran yang cocok.'
                            : 'Belum ada termin pembayaran.'
                    }
                />
            </ListPageLayout>
            {card && (
                <PaymentTermCard
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

PaymentTermsPage.layout = {
    breadcrumbs: [
        { title: 'Termin pembayaran', href: '/settings/payment-terms' },
    ] satisfies BreadcrumbItem[],
};
