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

export type PlanningYearRecord = {
    id: string;
    legal_entity_id: string;
    year: number;
    active: boolean;
    version: number;
};

export function PlanningYearCreateCard({
    legalEntityId,
    open,
    onOpenChange,
    onCreated,
}: {
    legalEntityId: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onCreated: (record: PlanningYearRecord) => void;
}) {
    return open ? (
        <PlanningYearCard
            legalEntityId={legalEntityId}
            canManage
            onClose={() => onOpenChange(false)}
            onSaved={(record) => {
                onCreated(record);
                onOpenChange(false);
            }}
        />
    ) : null;
}

function PlanningYearCard({
    legalEntityId,
    record,
    canManage,
    onClose,
    onSaved,
}: {
    legalEntityId: string;
    record?: PlanningYearRecord;
    canManage: boolean;
    onClose: () => void;
    onSaved: (record: PlanningYearRecord) => void;
}) {
    const initial = {
        year: record ? String(record.year) : '',
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
    const save = async () => {
        setSaving(true);
        clear();

        try {
            const result = await apiJson<{ data: PlanningYearRecord }>(
                record
                    ? `/api/v1/planning-years/${record.id}`
                    : '/api/v1/planning-years',
                {
                    method: record ? 'PATCH' : 'POST',
                    headers: record
                        ? undefined
                        : { 'Idempotency-Key': creationKey },
                    body: JSON.stringify(
                        record
                            ? { active: draft.active, version: record.version }
                            : {
                                  legal_entity_id: legalEntityId,
                                  year: draft.year,
                              },
                    ),
                },
            );
            onSaved(result.data);
            toast.success('Tahun rencana disimpan.');
        } catch (error) {
            report(error, 'Tahun rencana belum dapat disimpan.');
        } finally {
            setSaving(false);
        }
    };

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
                    title="Tahun rencana"
                    recordTitle={record ? String(record.year) : 'Baru'}
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
                                <FormControl data-error-field="year">
                                    <Input
                                        label="Tahun rencana"
                                        required
                                        type="number"
                                        min={2000}
                                        max={2100}
                                        step={1}
                                        value={draft.year}
                                        readOnly={Boolean(record) || !canManage}
                                        onChange={(event) =>
                                            setDraft({
                                                ...draft,
                                                year: event.target.value,
                                            })
                                        }
                                    />
                                </FormControl>
                                {record && (
                                    <FormControl data-error-field="active">
                                        {canManage ? (
                                            <>
                                                <FieldLabel htmlFor="planning-year-active">
                                                    Aktif
                                                </FieldLabel>
                                                <Switch
                                                    id="planning-year-active"
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

const columns: DataTableColumn<PlanningYearRecord>[] = [
    {
        id: 'year',
        header: 'Tahun rencana',
        cell: (record) => record.year,
        sortValue: (record) => record.year,
        width: 180,
    },
    {
        id: 'active',
        header: 'Status',
        cell: (record) => (
            <Badge variant="secondary">
                {record.active ? 'Aktif' : 'Nonaktif'}
            </Badge>
        ),
        width: 140,
    },
];

export default function PlanningYears({
    legalEntityId,
    canManage,
}: {
    legalEntityId: string;
    canManage: boolean;
}) {
    const [query, setQuery] = useState('');
    const [card, setCard] = useState<{ record?: PlanningYearRecord } | null>(
        null,
    );
    const [deleting, setDeleting] = useState(false);
    const { confirm, confirmation } = useConfirmation();
    const loadItems = useCallback(
        async ({ query, cursor, sort, signal }: ListRequest) => {
            const params = new URLSearchParams({
                legal_entity_id: legalEntityId,
                q: query,
            });

            if (cursor) {
                params.set('cursor', cursor);
            }

            if (sort) {
                params.set('sort', sort.columnId);
                params.set('direction', sort.direction);
            }

            const result = await apiJson<{
                data: PlanningYearRecord[];
                next_cursor: string | null;
            }>(`/api/v1/planning-years?${params}`, { signal });

            return { items: result.data, nextCursor: result.next_cursor };
        },
        [legalEntityId],
    );
    const list = useIncrementalList({
        loadItems,
        query,
        getKey: (record) => record.id,
    });
    const archive = async (record: PlanningYearRecord) => {
        if (
            !(await confirm({
                title: `Delete tahun rencana ${record.year}?`,
                description:
                    'Dokumen lama tetap menyimpan tahun ini. Tahun ini tidak lagi tersedia untuk pilihan baru.',
                confirmLabel: 'Delete',
                destructive: true,
            }))
        ) {
            return;
        }

        setDeleting(true);

        try {
            await apiRequest(`/api/v1/planning-years/${record.id}`, {
                method: 'DELETE',
                body: JSON.stringify({ version: record.version }),
            });
            list.reload();
        } catch (error) {
            toast.error(errorText(error, 'Tahun rencana belum dapat dihapus.'));
        } finally {
            setDeleting(false);
        }
    };

    return (
        <>
            <Head title="Tahun rencana" />
            {confirmation}
            <ListPageLayout
                header={
                    <RecordActionBar title="Tahun rencana">
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
                                label="Cari tahun rencana"
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
                    getRowLabel={(record) => String(record.year)}
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
                            ? 'Tidak ada tahun rencana yang cocok.'
                            : 'Belum ada tahun rencana.'
                    }
                />
            </ListPageLayout>
            {card && (
                <PlanningYearCard
                    key={`${legalEntityId}:${card.record?.id ?? 'new'}`}
                    legalEntityId={legalEntityId}
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

PlanningYears.layout = {
    breadcrumbs: [
        { title: 'Tahun rencana', href: '/settings/planning-years' },
    ] satisfies BreadcrumbItem[],
};
