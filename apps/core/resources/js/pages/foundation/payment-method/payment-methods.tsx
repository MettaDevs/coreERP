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
import { FormErrorHeader } from '@/components/form-error-header';
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

    const [values, setValues] = useState(initial);
    const [saving, setSaving] = useState(false);
    const isNew = !record;

    const dirty =
        values.code !== initial.code ||
        values.name !== initial.name ||
        values.description !== initial.description ||
        values.active !== initial.active;

    const transition = useRecordTransition(dirty, saving);
    const feedback = useSaveFeedback(
        record ? `payment-method-${record.id}` : 'payment-method-new',
    );

    const handleSave = async (event: React.FormEvent) => {
        event.preventDefault();
        feedback.clear();

        if (values.code.trim() === '') {
            feedback.setFieldError(
                'code',
                'Kode metode pembayaran wajib diisi.',
            );

            return;
        }

        if (values.name.trim() === '') {
            feedback.setFieldError(
                'name',
                'Nama metode pembayaran wajib diisi.',
            );

            return;
        }

        setSaving(true);

        try {
            const payload = {
                code: values.code.trim(),
                name: values.name.trim(),
                description:
                    values.description.trim() === ''
                        ? null
                        : values.description.trim(),
                active: values.active,
            };

            const url = record
                ? `/api/v1/payment-methods/${record.id}`
                : '/api/v1/payment-methods';
            const method = record ? 'PATCH' : 'POST';

            const headers: Record<string, string> = {};

            if (record) {
                headers['If-Match'] = `W/"${record.version}"`;
            } else {
                headers['Idempotency-Key'] = crypto.randomUUID();
            }

            const response = await apiRequest(url, {
                method,
                headers,
                body: JSON.stringify(payload),
            });

            if (!response.ok) {
                const data = await response.json().catch(() => null);

                if (response.status === 422 && data?.errors) {
                    feedback.setErrors(data.errors);

                    return;
                }

                if (response.status === 409) {
                    feedback.setConflict(
                        'Metode pembayaran telah diubah oleh pengguna lain. Silakan muat ulang.',
                    );

                    return;
                }

                throw new Error(
                    data?.message || 'Gagal menyimpan metode pembayaran.',
                );
            }

            const result = (await response.json()) as {
                data: PaymentMethodRecord;
            };
            toast.success(
                isNew
                    ? 'Metode pembayaran berhasil ditambahkan.'
                    : 'Metode pembayaran berhasil diperbarui.',
            );
            onSaved(result.data);
        } catch (error) {
            feedback.setGeneralError(
                error instanceof Error
                    ? error.message
                    : 'Gagal menyimpan metode pembayaran.',
            );
        } finally {
            setSaving(false);
        }
    };

    return (
        <PageDialog
            open
            onOpenChange={(nextOpen) => {
                if (!nextOpen) {
                    transition.requestTransition(onClose);
                }
            }}
        >
            <PageDialogContent>
                <form
                    noValidate
                    onSubmit={handleSave}
                    className="flex h-full flex-col"
                >
                    <PageDialogToolbar
                        title={
                            isNew
                                ? 'Metode pembayaran baru'
                                : `Metode pembayaran: ${record.code}`
                        }
                        onClose={() => transition.requestTransition(onClose)}
                        action={
                            canManage ? (
                                <ActionButton
                                    action="save"
                                    type="submit"
                                    disabled={saving}
                                />
                            ) : null
                        }
                    />
                    <PageDialogBody>
                        <FormErrorHeader feedback={feedback} />
                        <FormGrid className="space-y-4 pt-2">
                            <FormControl
                                id="payment-method-code"
                                label="Kode"
                                required
                                error={feedback.fieldErrors.code}
                            >
                                <Input
                                    id="payment-method-code"
                                    name="code"
                                    value={values.code}
                                    onChange={(e) =>
                                        setValues((v) => ({
                                            ...v,
                                            code: e.target.value,
                                        }))
                                    }
                                    disabled={!canManage || saving || !isNew}
                                    placeholder="Contoh: TRANSFER"
                                />
                            </FormControl>

                            <FormControl
                                id="payment-method-name"
                                label="Nama metode pembayaran"
                                required
                                error={feedback.fieldErrors.name}
                            >
                                <Input
                                    id="payment-method-name"
                                    name="name"
                                    value={values.name}
                                    onChange={(e) =>
                                        setValues((v) => ({
                                            ...v,
                                            name: e.target.value,
                                        }))
                                    }
                                    disabled={!canManage || saving}
                                    placeholder="Contoh: Transfer Bank"
                                />
                            </FormControl>

                            <FormControl
                                id="payment-method-description"
                                label="Keterangan"
                                error={feedback.fieldErrors.description}
                            >
                                <Textarea
                                    id="payment-method-description"
                                    name="description"
                                    value={values.description}
                                    onChange={(e) =>
                                        setValues((v) => ({
                                            ...v,
                                            description: e.target.value,
                                        }))
                                    }
                                    disabled={!canManage || saving}
                                    placeholder="Catatan tambahan mengenai metode pembayaran"
                                    rows={3}
                                />
                            </FormControl>

                            <div className="flex items-center justify-between rounded-lg border p-3">
                                <div className="space-y-0.5">
                                    <FieldLabel htmlFor="payment-method-active">
                                        Status aktif
                                    </FieldLabel>
                                    <p className="text-xs text-muted-foreground">
                                        Metode pembayaran aktif dapat dipilih
                                        pada transaksi dokumen.
                                    </p>
                                </div>
                                <Switch
                                    id="payment-method-active"
                                    checked={values.active}
                                    onCheckedChange={(checked) =>
                                        setValues((v) => ({
                                            ...v,
                                            active: checked,
                                        }))
                                    }
                                    disabled={!canManage || saving}
                                />
                            </div>
                        </FormGrid>
                    </PageDialogBody>
                </form>
            </PageDialogContent>
        </PageDialog>
    );
}

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
    const { confirm } = useConfirmation();

    const fetcher = useCallback(
        async ({ cursor, search, sort, signal }: ListRequest) => {
            const params = new URLSearchParams();

            if (cursor) {
                params.set('cursor', cursor);
            }

            if (search) {
                params.set('q', search);
            }

            if (sort) {
                params.set('sort', sort.column);
                params.set('direction', sort.direction);
            }

            return apiJson<{
                data: PaymentMethodRecord[];
                next_cursor: string | null;
            }>(`/api/v1/payment-methods?${params.toString()}`, { signal });
        },
        [],
    );

    const list = useIncrementalList<PaymentMethodRecord>({
        fetcher,
        search: query,
        defaultSort: { column: 'code', direction: 'asc' },
    });

    const archive = async (record: PaymentMethodRecord) => {
        const agreed = await confirm({
            title: 'Arsipkan metode pembayaran',
            message: `Apakah Anda yakin ingin mengarsipkan metode pembayaran "${record.name}" (${record.code})?`,
            confirmLabel: 'Delete',
            destructive: true,
        });

        if (!agreed) {
            return;
        }

        setDeleting(true);

        try {
            const response = await apiRequest(
                `/api/v1/payment-methods/${record.id}`,
                {
                    method: 'DELETE',
                    headers: { 'If-Match': `W/"${record.version}"` },
                },
            );

            if (!response.ok) {
                const data = await response.json().catch(() => null);

                throw new Error(
                    data?.message || 'Gagal mengarsipkan metode pembayaran.',
                );
            }

            toast.success('Metode pembayaran berhasil diarsipkan.');
            list.reload();
        } catch (error) {
            toast.error(
                error instanceof Error
                    ? error.message
                    : 'Gagal mengarsipkan metode pembayaran.',
            );
        } finally {
            setDeleting(false);
        }
    };

    const columns: DataTableColumn<PaymentMethodRecord>[] = [
        {
            id: 'code',
            header: 'Kode',
            width: 140,
            cell: (item) => (
                <span className="font-mono text-xs font-medium">
                    {item.code}
                </span>
            ),
        },
        {
            id: 'name',
            header: 'Nama metode pembayaran',
            cell: (item) => (
                <div className="space-y-0.5">
                    <span className="text-sm font-medium">{item.name}</span>
                    {item.description && (
                        <p className="line-clamp-1 text-xs text-muted-foreground">
                            {item.description}
                        </p>
                    )}
                </div>
            ),
        },
        {
            id: 'active',
            header: 'Status',
            width: 120,
            cell: (item) => (
                <Badge variant={item.active ? 'success' : 'secondary'}>
                    {item.active ? 'Aktif' : 'Nonaktif'}
                </Badge>
            ),
        },
    ];

    return (
        <>
            <Head title="Metode Pembayaran" />
            <ListPageLayout
                header={
                    <RecordActionBar title="Metode Pembayaran">
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
                        list.error ? (
                            <div className="flex flex-col items-center justify-center space-y-2 p-6">
                                <p className="text-sm text-destructive">
                                    Gagal memuat metode pembayaran: {list.error}
                                </p>
                                <button
                                    type="button"
                                    onClick={() => list.reload()}
                                    className="text-xs text-primary hover:underline"
                                >
                                    Coba lagi
                                </button>
                            </div>
                        ) : query ? (
                            'Tidak ada metode pembayaran yang cocok.'
                        ) : (
                            'Belum ada metode pembayaran.'
                        )
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
