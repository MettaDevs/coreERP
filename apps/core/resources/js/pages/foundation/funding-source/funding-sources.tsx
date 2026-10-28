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
import { apiJson, apiRequest, errorText } from '@/lib/core-api';
import type { BreadcrumbItem } from '@/types/navigation';

export type FundingSourceRecord = {
    id: string;
    name: string;
    description: string | null;
    active: boolean;
    version: number;
};

type CreateCardProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onCreated: (record: FundingSourceRecord) => void;
    initialName?: string;
};

export function FundingSourceCreateCard({
    open,
    onOpenChange,
    onCreated,
    initialName,
}: CreateCardProps) {
    return open ? (
        <FundingSourceCard
            onClose={() => onOpenChange(false)}
            onSaved={(record) => {
                onCreated(record);
                onOpenChange(false);
            }}
            initialName={initialName}
            canManage
        />
    ) : null;
}

function FundingSourceCard({
    record,
    initialName = '',
    canManage,
    onClose,
    onSaved,
}: {
    record?: FundingSourceRecord;
    initialName?: string;
    canManage: boolean;
    onClose: () => void;
    onSaved: (record: FundingSourceRecord) => void;
}) {
    const initial = {
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
            const result = await apiJson<{ data: FundingSourceRecord }>(
                record
                    ? `/api/v1/funding-sources/${record.id}`
                    : '/api/v1/funding-sources',
                {
                    method: record ? 'PATCH' : 'POST',
                    headers: record
                        ? undefined
                        : { 'Idempotency-Key': creationKey },
                    body: JSON.stringify(
                        record
                            ? {
                                  name: draft.name,
                                  description: draft.description,
                                  active: draft.active,
                                  version: record.version,
                              }
                            : {
                                  name: draft.name,
                                  description: draft.description,
                              },
                    ),
                },
            );
            onSaved(result.data);
            toast.success('Sumber dana disimpan.');
        } catch (error) {
            report(error, 'Sumber dana belum dapat disimpan.');
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
                    title="Sumber dana"
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
                                <FormControl data-error-field="name">
                                    <Input
                                        label="Nama"
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
                                    />
                                </FormControl>
                                <FormControl data-error-field="description">
                                    <Textarea
                                        label="Deskripsi"
                                        value={draft.description}
                                        maxLength={2000}
                                        readOnly={!canManage}
                                        onChange={(event) =>
                                            setDraft({
                                                ...draft,
                                                description: event.target.value,
                                            })
                                        }
                                    />
                                </FormControl>
                                {record && (
                                    <FormControl data-error-field="active">
                                        {canManage ? (
                                            <>
                                                <FieldLabel htmlFor="funding-source-active">
                                                    Aktif
                                                </FieldLabel>
                                                <Switch
                                                    id="funding-source-active"
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
                                )}
                            </FormGrid>
                        </PageDialogBody>
                    </form>
                </PageDialogContent>
            </PageDialog>
        </>
    );
}

const columns: DataTableColumn<FundingSourceRecord>[] = [
    {
        id: 'name',
        header: 'Nama',
        minWidth: 280,
        cell: (record) => record.name,
        sortValue: (record) => record.name,
    },
    {
        id: 'description',
        header: 'Deskripsi',
        minWidth: 320,
        cell: (record) => record.description ?? '',
        sortValue: (record) => record.description ?? '',
    },
    {
        id: 'active',
        header: 'Status',
        width: 120,
        cell: (record) => (
            <Badge variant="secondary">
                {record.active ? 'Aktif' : 'Nonaktif'}
            </Badge>
        ),
    },
];

export default function FundingSources({ canManage }: { canManage: boolean }) {
    const [query, setQuery] = useState('');
    const [card, setCard] = useState<{ record?: FundingSourceRecord } | null>(
        null,
    );
    const [deleting, setDeleting] = useState(false);
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
                data: FundingSourceRecord[];
                next_cursor: string | null;
            }>(`/api/v1/funding-sources?${params}`, { signal });

            return { items: result.data, nextCursor: result.next_cursor };
        },
        [],
    );
    const list = useIncrementalList({
        loadItems,
        query,
        getKey: (record) => record.id,
    });
    async function archive(record: FundingSourceRecord) {
        if (
            !(await confirm({
                title: `Delete sumber dana ${record.name}?`,
                description:
                    'Referensi pada dokumen lama tetap disimpan. Sumber dana ini tidak lagi muncul pada pilihan baru.',
                confirmLabel: 'Delete',
                destructive: true,
            }))
        ) {
            return;
        }

        setDeleting(true);

        try {
            await apiRequest(`/api/v1/funding-sources/${record.id}`, {
                method: 'DELETE',
                body: JSON.stringify({ version: record.version }),
            });
            list.reload();
        } catch (error) {
            toast.error(errorText(error, 'Sumber dana belum dapat dihapus.'));
        } finally {
            setDeleting(false);
        }
    }

    return (
        <>
            <Head title="Sumber dana" />
            {confirmation}
            <ListPageLayout
                header={
                    <RecordActionBar title="Sumber dana">
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
                                label="Cari sumber dana"
                                type="search"
                                value={query}
                                onChange={(event) =>
                                    setQuery(event.target.value)
                                }
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
                    getRowLabel={(record) => record.name}
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
                            ? 'Tidak ada sumber dana yang cocok.'
                            : 'Belum ada sumber dana.'
                    }
                />
            </ListPageLayout>
            {card && (
                <FundingSourceCard
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

FundingSources.layout = {
    breadcrumbs: [
        { title: 'Sumber dana', href: '/settings/funding-sources' },
    ] satisfies BreadcrumbItem[],
};
