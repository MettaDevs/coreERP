import { usePage } from '@inertiajs/react';
import { useEffect, useEffectEvent, useRef, useState } from 'react';
import { useWorkDate } from '@/hooks/use-work-date';
import { Button } from '@apperp/ui/button';
import { DataTable } from '@apperp/ui/data-table';
import type {
    DataTableColumn,
    DataTableRowAction,
} from '@apperp/ui/data-table';
import { Input } from '@apperp/ui/input';
import { ListPageLayout } from '@apperp/ui/list-page-layout';
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { Select } from '@apperp/ui/select';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
    SheetFooter,
} from '@apperp/ui/sheet';
import { Textarea } from '@apperp/ui/textarea';
import {
    api,
    errorMessage,
    newIdempotencyKey,
    saveErrorMessage,
} from '../../api';
import { requestPrint } from '../../print';
import { useRecordList, useRecordOptions } from '../../use-record-list';

type Context = { legal_entity_id: string | null; org_unit_id: string | null };
type PlanLine = {
    id: string;
    jenis_aset_id: string;
    satuan_id: string;
    nama_aset: string;
    unit: string;
    quantity: string | number;
    requested_specification: string;
};
type Plan = {
    id: string;
    kode: string;
    description: string | null;
    planned_on: string;
    details?: PlanLine[];
};
export type RequestLine = {
    id?: string;
    planning_detail_id: string;
    planning_code?: string;
    jenis_aset_id: string;
    satuan_id: string;
    nama_aset: string;
    unit?: string;
    quantity: string | number;
    specification: string;
    note?: string | null;
    received_quantity?: string | number;
    remaining_quantity?: string | number;
};
export type PurchaseRequest = {
    id: string;
    kode: string;
    legal_entity_id: string;
    requesting_org_unit_id: string;
    requested_on: string;
    description: string | null;
    status: string;
    version: number;
    details?: RequestLine[];
};

export default function PurchaseRequestPage({
    context,
    permissions,
}: {
    context: Context;
    permissions: string[];
}) {
    const can = (action: string) =>
        permissions.includes(
            `management-aset.permintaan-pembelian-aset.${action}`,
        );
    const { date: workDate } = useWorkDate();
    const [record, setRecord] = useState<PurchaseRequest | null>(null);
    const [lines, setLines] = useState<RequestLine[]>([]);
    const [planId, setPlanId] = useState<string | null>(null);
    const [date, setDate] = useState(workDate);
    const [description, setDescription] = useState('');
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [reload, setReload] = useState(0);
    const portalContainer = useRef<HTMLDivElement>(null);
    const creationKey = useRef(newIdempotencyKey());

    const list = useRecordList<PurchaseRequest>(
        '/permintaan-pembelian-aset',
        '',
        { refresh: String(reload) },
    );
    const records = list.items;
    const loading = list.loading;
    const planSource = useRecordOptions<Plan>(
        open && !record ? '/perencanaan-aset' : null,
        (plan) =>
            `${plan.kode} · ${plan.description || 'Tanpa tujuan'} · ${plan.planned_on.slice(0, 10)}`,
        planId ?? '',
    );
    const plans = planSource.options;

    function create() {
        setError('');
        setRecord(null);
        setLines([]);
        setPlanId(null);
        setDate(workDate);
        setDescription('');
        creationKey.current = newIdempotencyKey();
        setEditing(true);
        setOpen(true);
    }

    async function selectPlan(id: string | null, selectedIds?: string[]) {
        setPlanId(id);
        setLines([]);

        if (!id) {
            return;
        }

        setBusy(true);
        setError('');

        try {
            const result = await api<{ data: Plan }>(`/perencanaan-aset/${id}`);
            setLines(
                (result.data.details ?? [])
                    .filter(
                        (line) => !selectedIds || selectedIds.includes(line.id),
                    )
                    .map((line) => ({
                        planning_detail_id: line.id,
                        planning_code: result.data.kode,
                        jenis_aset_id: line.jenis_aset_id,
                        satuan_id: line.satuan_id,
                        nama_aset: line.nama_aset,
                        unit: line.unit,
                        quantity: line.quantity,
                        specification: line.requested_specification,
                    })),
            );
        } catch (caught) {
            setError(
                errorMessage(caught, 'Baris perencanaan belum dapat dimuat.'),
            );
        } finally {
            setBusy(false);
        }
    }

    const { url } = usePage();
    const sourceQuery = new URL(url, 'http://localhost').searchParams;
    const sourceId = sourceQuery.get('planning');
    const sourceLines = sourceQuery.get('lines');
    const openSourceForm = useEffectEvent((source: Plan) => {
        if (!can('create')) {
            return;
        }

        setRecord(null);
        setPlanId(source.id);
        setDate(workDate);
        setDescription('');
        creationKey.current = newIdempotencyKey();
        const selectedIds =
            sourceLines === null ? null : sourceLines.split(',');
        setLines(
            (source.details ?? [])
                .filter((line) => !selectedIds || selectedIds.includes(line.id))
                .map((line) => ({
                    planning_detail_id: line.id,
                    planning_code: source.kode,
                    jenis_aset_id: line.jenis_aset_id,
                    satuan_id: line.satuan_id,
                    nama_aset: line.nama_aset,
                    unit: line.unit,
                    quantity: line.quantity,
                    specification: line.requested_specification,
                })),
        );
        setError('');
        setEditing(true);
        setOpen(true);
    });
    useEffect(() => {
        if (!sourceId) {
            return;
        }

        let cancelled = false;
        const loadSource = async () => {
            try {
                const source = await api<{ data: Plan }>(
                    `/perencanaan-aset/${sourceId}`,
                );

                if (!cancelled) {
                    openSourceForm(source.data);
                }
            } catch (caught) {
                if (!cancelled) {
                    setError(
                        errorMessage(caught, 'Perencanaan belum dapat dimuat.'),
                    );
                }
            }
        };
        void loadSource();

        return () => {
            cancelled = true;
        };
    }, [sourceId, sourceLines]);

    async function show(id: string, edit = false) {
        setBusy(true);
        setError('');

        try {
            const result = await api<{ data: PurchaseRequest }>(
                `/permintaan-pembelian-aset/${id}`,
            );
            setRecord(result.data);
            setLines(result.data.details ?? []);
            setDate(result.data.requested_on.slice(0, 10));
            setDescription(result.data.description ?? '');
            setEditing(edit);
            setOpen(true);
        } catch (caught) {
            setError(errorMessage(caught, 'Permintaan belum dapat dibuka.'));
        } finally {
            setBusy(false);
        }
    }

    async function save() {
        if (
            !context.legal_entity_id ||
            !context.org_unit_id ||
            lines.length === 0
        ) {
            setError(
                'Pilih perusahaan, unit kerja, dan baris perencanaan terlebih dahulu.',
            );

            return;
        }

        setBusy(true);
        setError('');

        try {
            await api(
                `/permintaan-pembelian-aset${record ? `/${record.id}` : ''}`,
                {
                    method: record ? 'PATCH' : 'POST',
                    headers: record
                        ? {}
                        : { 'Idempotency-Key': creationKey.current },
                    body: JSON.stringify({
                        legal_entity_id:
                            record?.legal_entity_id ?? context.legal_entity_id,
                        requesting_org_unit_id:
                            record?.requesting_org_unit_id ??
                            context.org_unit_id,
                        requested_on: date,
                        description,
                        version: record?.version,
                        details: lines,
                    }),
                },
            );
            setOpen(false);
            setReload((value) => value + 1);
        } catch (caught) {
            setError(
                saveErrorMessage(caught, 'Permintaan belum dapat disimpan.'),
            );
        } finally {
            setBusy(false);
        }
    }

    async function cancel() {
        if (!record) {
            return;
        }

        setBusy(true);
        setError('');

        try {
            const result = await api<{ data: PurchaseRequest }>(
                `/permintaan-pembelian-aset/${record.id}/batal`,
                {
                    method: 'POST',
                    body: JSON.stringify({ version: record.version }),
                },
            );
            setRecord(result.data);
            setLines(result.data.details ?? []);
            setReload((value) => value + 1);
        } catch (caught) {
            setError(
                saveErrorMessage(caught, 'Permintaan belum dapat dibatalkan.'),
            );
        } finally {
            setBusy(false);
        }
    }

    const columns: DataTableColumn<PurchaseRequest>[] = [
        {
            id: 'kode',
            header: 'No. permintaan',
            cell: (row) => row.kode,
            width: 180,
        },
        {
            id: 'date',
            header: 'Tanggal',
            cell: (row) => row.requested_on.slice(0, 10),
            width: 140,
        },
        {
            id: 'description',
            header: 'Keterangan',
            cell: (row) => row.description ?? '—',
            width: 300,
        },
        {
            id: 'status',
            header: 'Status',
            cell: (row) => (row.status === 'cancelled' ? 'Dibatalkan' : 'Draf'),
            width: 140,
        },
    ];
    const actions: DataTableRowAction[] = [
        { id: 'view', label: 'Buka rincian' },
        { id: 'print', label: 'Cetak' },
    ];

    if (can('update')) {
        actions.push({ id: 'edit', label: 'Ubah' });
    }

    return (
        <>
            <ListPageLayout
                header={
                    <RecordActionBar title="Permintaan pembelian aset">
                        {can('create') && (
                            <Button
                                disabled={busy}
                                onClick={() => void create()}
                            >
                                Buat permintaan
                            </Button>
                        )}
                    </RecordActionBar>
                }
            >
                {!open && error && (
                    <p role="alert" className="text-destructive p-4 text-sm">
                        {error}
                    </p>
                )}
                {loading && <p className="p-4 text-sm">Memuat permintaan…</p>}
                <DataTable
                    columns={columns}
                    incremental={list}
                    sort={list.sort}
                    onSortChange={list.onSortChange}
                    data={records}
                    getRowKey={(row) => row.id}
                    actions={actions}
                    actionsPlacement="inline"
                    rowActivation="double-click"
                    onRowClick={(row) =>
                        void show(
                            row.id,
                            can('update') && row.status === 'draft',
                        )
                    }
                    onRowAction={(action, row) => {
                        if (action === 'print') {
                            requestPrint({
                                report: 'purchase-request',
                                title: `Cetak ${row.kode}`,
                                parameters: { id: row.id },
                            });
                        } else {
                            void show(row.id, action === 'edit');
                        }
                    }}
                    emptyMessage="Belum ada permintaan pembelian aset."
                />
            </ListPageLayout>
            <Sheet
                open={open}
                onOpenChange={(value) => {
                    if (!busy) {
                        setOpen(value);
                    }
                }}
            >
                <SheetContent
                    ref={portalContainer}
                    side="right"
                    className="w-full sm:max-w-4xl"
                >
                    <SheetHeader>
                        <SheetTitle>
                            {record?.kode ?? 'Buat permintaan pembelian aset'}
                        </SheetTitle>
                    </SheetHeader>
                    <form
                        className="min-w-0 space-y-4 overflow-y-auto p-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            void save();
                        }}
                    >
                        {error && (
                            <p
                                role="alert"
                                className="text-destructive text-sm"
                            >
                                {error}
                            </p>
                        )}
                        {!record && (
                            <fieldset className="min-w-0" disabled={busy}>
                                <Select
                                    {...planSource.selectProps}
                                    label="Perencanaan"
                                    required
                                    items={plans.map((plan) => ({
                                        value: plan.id,
                                        label: `${plan.kode} · ${plan.description || 'Tanpa tujuan'} · ${plan.planned_on.slice(0, 10)}`,
                                    }))}
                                    value={planId}
                                    portalContainer={portalContainer}
                                    onValueChange={(value) =>
                                        void selectPlan(value)
                                    }
                                    placeholder="Pilih perencanaan"
                                    searchPlaceholder="Cari kode atau tujuan perencanaan"
                                />
                            </fieldset>
                        )}
                        <Input
                            label="Tanggal permintaan"
                            type="date"
                            required
                            value={date}
                            readOnly={!editing}
                            onChange={(event) => setDate(event.target.value)}
                        />
                        <Textarea
                            label="Keterangan"
                            value={description}
                            readOnly={!editing}
                            onChange={(event) =>
                                setDescription(event.target.value)
                            }
                        />
                        {lines.map((line, index) => (
                            <div
                                key={line.planning_detail_id || index}
                                className="space-y-3 rounded-lg border p-3"
                            >
                                <p className="font-medium">{line.nama_aset}</p>
                                <p className="text-muted-foreground text-sm">
                                    {line.planning_code ??
                                        'Belum terhubung ke perencanaan'}{' '}
                                    · {line.unit}
                                </p>
                                <Input
                                    label="Jumlah diminta"
                                    type="number"
                                    required
                                    min="0.0001"
                                    step="0.0001"
                                    value={line.quantity}
                                    readOnly={!editing}
                                    onChange={(event) =>
                                        setLines(
                                            lines.map((item, position) =>
                                                position === index
                                                    ? {
                                                          ...item,
                                                          quantity:
                                                              event.target
                                                                  .value,
                                                      }
                                                    : item,
                                            ),
                                        )
                                    }
                                />
                                <Textarea
                                    label="Spesifikasi"
                                    required
                                    value={line.specification}
                                    readOnly={!editing}
                                    onChange={(event) =>
                                        setLines(
                                            lines.map((item, position) =>
                                                position === index
                                                    ? {
                                                          ...item,
                                                          specification:
                                                              event.target
                                                                  .value,
                                                      }
                                                    : item,
                                            ),
                                        )
                                    }
                                />
                                {!editing && (
                                    <p className="text-sm">
                                        Diterima: {line.received_quantity ?? 0}{' '}
                                        · Sisa:{' '}
                                        {line.remaining_quantity ??
                                            line.quantity}
                                    </p>
                                )}
                                {editing && lines.length > 1 && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        onClick={() =>
                                            setLines(
                                                lines.filter(
                                                    (_, position) =>
                                                        position !== index,
                                                ),
                                            )
                                        }
                                    >
                                        Hapus baris
                                    </Button>
                                )}
                            </div>
                        ))}
                        {!record && plans.length === 0 && (
                            <p className="text-muted-foreground text-sm">
                                Buat perencanaan terlebih dahulu sebelum membuat
                                permintaan.
                            </p>
                        )}
                        <SheetFooter>
                            {editing ? (
                                <Button
                                    type="submit"
                                    disabled={busy || lines.length === 0}
                                >
                                    {busy ? 'Menyimpan…' : 'Simpan'}
                                </Button>
                            ) : (
                                <>
                                    {record && (
                                        <Button
                                            type="button"
                                            onClick={() =>
                                                requestPrint({
                                                    report: 'purchase-request',
                                                    title: `Cetak ${record.kode}`,
                                                    parameters: {
                                                        id: record.id,
                                                    },
                                                })
                                            }
                                        >
                                            Cetak
                                        </Button>
                                    )}
                                    {record?.status === 'draft' &&
                                        can('cancel') && (
                                            <Button
                                                type="button"
                                                variant="outline"
                                                disabled={busy}
                                                onClick={() => void cancel()}
                                            >
                                                Batalkan permintaan
                                            </Button>
                                        )}
                                </>
                            )}
                            <Button
                                type="button"
                                variant="outline"
                                disabled={busy}
                                onClick={() => setOpen(false)}
                            >
                                Tutup
                            </Button>
                        </SheetFooter>
                    </form>
                </SheetContent>
            </Sheet>
        </>
    );
}
