import { type RefObject, useEffect, useRef, useState } from 'react';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import {
    Card,
    CardAction,
    CardContent,
    CardHeader,
    CardTitle,
} from '@apperp/ui/card';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@apperp/ui/empty';
import { Field, FieldLabel } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { Select } from '@apperp/ui/select';
import {
    Sheet,
    SheetContent,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@apperp/ui/sheet';
import { Textarea } from '@apperp/ui/textarea';
import { api, errorMessage, newIdempotencyKey } from '../../api';
import { toast } from 'sonner';

type Context = { legal_entity_id: string | null; org_unit_id: string | null };
type Permission = string;
type AssetType = { id: string; kode: string; nama: string };
type Detail = {
    jenis_aset_id: string;
    satuan_id: string;
    quantity: string;
    requested_specification: string;
    estimated_unit_price: string;
};
type Plan = {
    id: string;
    kode: string;
    planned_on: string;
    planning_year: number;
    planning_type: 'regular' | 'additional';
    funding_source: string | null;
    description: string | null;
    status: string;
    version: number;
    total_estimated_value: string | number;
    details?: Detail[];
};

const emptyDetail = (): Detail => ({
    jenis_aset_id: '',
    satuan_id: '',
    quantity: '1',
    requested_specification: '',
    estimated_unit_price: '0',
});

const emptyPlan = (): Omit<Plan, 'id' | 'kode' | 'version' | 'status'> & {
    details: Detail[];
} => ({
    planned_on: new Date().toISOString().slice(0, 10),
    planning_year: new Date().getFullYear(),
    planning_type: 'regular',
    funding_source: '',
    description: '',
    total_estimated_value: 0,
    details: [emptyDetail()],
});

function AssetDetailRow({
    detail,
    index,
    canRemove,
    types,
    units,
    portalContainer,
    onTypeSearch,
    onChange,
    onRemove,
}: {
    detail: Detail;
    index: number;
    canRemove: boolean;
    types: AssetType[];
    units: AssetType[];
    portalContainer: RefObject<HTMLDivElement | null>;
    onTypeSearch: (query: string) => void;
    onChange: (change: Partial<Detail>) => void;
    onRemove: () => void;
}) {
    const selectedType = types.find((type) => type.id === detail.jenis_aset_id);
    const selectedUnit = units.find((unit) => unit.id === detail.satuan_id);

    return (
        <div className="space-y-3 rounded-lg border p-3">
            <div className="flex justify-between">
                <span className="text-sm font-medium">Baris {index + 1}</span>
                {canRemove && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={onRemove}
                    >
                        Hapus
                    </Button>
                )}
            </div>
            <div className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_10rem_8rem]">
                <Field>
                    <Select
                        label="Jenis aset"
                        required
                        items={types.map((type) => type.nama)}
                        value={selectedType?.nama ?? null}
                        placeholder="Pilih jenis aset"
                        searchPlaceholder="Cari jenis aset"
                        ariaLabel={`Jenis aset baris ${index + 1}`}
                        portalContainer={portalContainer}
                        onSearchChange={onTypeSearch}
                        onValueChange={(value) =>
                            onChange({
                                jenis_aset_id:
                                    types.find((type) => type.nama === value)
                                        ?.id ?? '',
                            })
                        }
                    />
                </Field>
                <Field>
                    <Select
                        label="Satuan"
                        required
                        items={units.map((unit) => unit.nama)}
                        value={selectedUnit?.nama ?? null}
                        placeholder="Pilih satuan"
                        searchPlaceholder="Cari satuan"
                        ariaLabel={`Satuan baris ${index + 1}`}
                        portalContainer={portalContainer}
                        onValueChange={(value) =>
                            onChange({
                                satuan_id:
                                    units.find((unit) => unit.nama === value)
                                        ?.id ?? '',
                            })
                        }
                    />
                </Field>
                <Field>
                    <Input
                        label="Jumlah"
                        type="number"
                        min="0.0001"
                        step="0.0001"
                        value={detail.quantity}
                        onChange={(event) =>
                            onChange({ quantity: event.target.value })
                        }
                    />
                </Field>
            </div>
            <Field>
                <Input
                    label="Spesifikasi yang diminta"
                    value={detail.requested_specification}
                    onChange={(event) =>
                        onChange({
                            requested_specification: event.target.value,
                        })
                    }
                    required
                />
            </Field>
            <Field>
                <Input
                    label="Perkiraan harga satuan"
                    type="number"
                    min="0"
                    step="0.01"
                    value={detail.estimated_unit_price}
                    onChange={(event) =>
                        onChange({ estimated_unit_price: event.target.value })
                    }
                />
            </Field>
        </div>
    );
}

export default function PlanningPage({
    context,
    permissions,
}: {
    context: Context;
    permissions: Permission[];
}) {
    const can = (action: string) =>
        permissions.includes(`management-aset.perencanaan-aset.${action}`);
    const [plans, setPlans] = useState<Plan[]>([]);
    const [types, setTypes] = useState<AssetType[]>([]);
    const [units, setUnits] = useState<AssetType[]>([]);
    const [typeSearch, setTypeSearch] = useState('');
    const [editing, setEditing] = useState<
        (Plan & { details: Detail[] }) | null | undefined
    >();
    const [saving, setSaving] = useState(false);
    const sheetContentRef = useRef<HTMLDivElement>(null);

    const load = async () => {
        try {
            const result = await api<{ data: Plan[] }>('/perencanaan-aset');
            setPlans(result.data);
        } catch (caught) {
            toast.error(
                errorMessage(caught, 'Rencana aset belum dapat dimuat.'),
            );
        }
    };

    useEffect(() => {
        void load();
    }, []);
    useEffect(() => {
        if (!can('create') && !can('update')) return;
        api<{ data: AssetType[] }>('/reference-data/units-of-measure')
            .then((result) => setUnits(result.data))
            .catch(() => toast.error('Satuan belum dapat dimuat.'));
    }, [permissions.join(',')]);
    useEffect(() => {
        if (!can('create') && !can('update')) return;
        const timer = window.setTimeout(() => {
            api<{ data: AssetType[] }>(
                `/jenis-aset?per_page=20&aktif=true&q=${encodeURIComponent(typeSearch)}`,
            )
                .then((result) => setTypes(result.data))
                .catch(() => toast.error('Jenis aset belum dapat dimuat.'));
        }, 250);
        return () => window.clearTimeout(timer);
    }, [permissions.join(','), typeSearch]);

    const openEdit = async (id: string) => {
        try {
            const result = await api<{ data: Plan & { details: Detail[] } }>(
                `/perencanaan-aset/${id}`,
            );
            setEditing({
                ...result.data,
                details: result.data.details.map((detail) => ({
                    ...detail,
                    quantity: String(detail.quantity),
                    estimated_unit_price: String(detail.estimated_unit_price),
                })),
            });
        } catch (caught) {
            toast.error(
                errorMessage(caught, 'Rencana aset belum dapat dibuka.'),
            );
        }
    };

    const archive = async (plan: Plan) => {
        if (!window.confirm(`Arsipkan rencana ${plan.kode}?`)) return;
        try {
            await api(`/perencanaan-aset/${plan.id}`, {
                method: 'DELETE',
                body: JSON.stringify({ version: plan.version }),
            });
            await load();
        } catch (caught) {
            toast.error(
                errorMessage(caught, 'Rencana aset belum dapat diarsipkan.'),
            );
        }
    };

    const updateDetail = (index: number, change: Partial<Detail>) => {
        setEditing(
            (current) =>
                current && {
                    ...current,
                    details: current.details.map((detail, position) =>
                        position === index ? { ...detail, ...change } : detail,
                    ),
                },
        );
    };

    const save = async () => {
        if (!editing || !context.legal_entity_id || !context.org_unit_id) {
            toast.error(
                'Pilih entitas legal dan unit kerja aktif sebelum membuat rencana.',
            );
            return;
        }
        if (
            !editing.details.every(
                (detail) =>
                    detail.jenis_aset_id &&
                    detail.satuan_id &&
                    detail.requested_specification.trim(),
            )
        ) {
            toast.error(
                'Pilih jenis aset, satuan, dan isi spesifikasi pada setiap rincian.',
            );
            return;
        }

        setSaving(true);
        const body = {
            legal_entity_id: context.legal_entity_id,
            planning_org_unit_id: context.org_unit_id,
            planned_on: editing.planned_on,
            planning_year: Number(editing.planning_year),
            planning_type: editing.planning_type,
            funding_source: editing.funding_source || null,
            description: editing.description || null,
            details: editing.details.map((detail) => ({
                ...detail,
                quantity: Number(detail.quantity),
                estimated_unit_price: Number(detail.estimated_unit_price || 0),
            })),
        };

        try {
            if ('id' in editing) {
                await api(`/perencanaan-aset/${editing.id}`, {
                    method: 'PATCH',
                    body: JSON.stringify({ ...body, version: editing.version }),
                });
            } else {
                await api('/perencanaan-aset', {
                    method: 'POST',
                    headers: { 'Idempotency-Key': newIdempotencyKey() },
                    body: JSON.stringify(body),
                });
            }
            setEditing(undefined);
            await load();
            toast.success('Rencana aset disimpan.');
        } catch (caught) {
            toast.error(
                errorMessage(caught, 'Rencana aset belum dapat disimpan.'),
            );
        } finally {
            setSaving(false);
        }
    };

    return (
        <Card className="min-h-full rounded-none border-0 shadow-none">
            <CardHeader className="border-b px-5 py-3">
                <CardTitle>Perencanaan aset</CardTitle>
                {can('create') && (
                    <CardAction>
                        <Button
                            onClick={() => setEditing(emptyPlan() as never)}
                        >
                            Tambah rencana
                        </Button>
                    </CardAction>
                )}
            </CardHeader>
            <CardContent className="px-0">
                {!plans.length ? (
                    <Empty>
                        <EmptyHeader>
                            <EmptyTitle>Belum ada rencana aset</EmptyTitle>
                            <EmptyDescription>
                                Rencana dibuat dari jenis aset. Spesifikasi
                                dicatat pada rincian rencana, bukan pada master.
                            </EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <div className="divide-y">
                        {plans.map((plan) => (
                            <div
                                key={plan.id}
                                className="flex flex-wrap items-center justify-between gap-3 px-5 py-4"
                            >
                                <div>
                                    <p className="font-medium">{plan.kode}</p>
                                    <p className="text-muted-foreground text-sm">
                                        {plan.planned_on} ·{' '}
                                        {plan.planning_type === 'regular'
                                            ? 'Reguler'
                                            : 'Tambahan'}{' '}
                                        · Rp{' '}
                                        {Number(
                                            plan.total_estimated_value,
                                        ).toLocaleString('id-ID')}
                                    </p>
                                </div>
                                <div className="flex items-center gap-2">
                                    <Badge variant="secondary">
                                        {plan.status === 'draft'
                                            ? 'Draf'
                                            : plan.status}
                                    </Badge>
                                    {can('update') && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                void openEdit(plan.id)
                                            }
                                        >
                                            Ubah
                                        </Button>
                                    )}
                                    {can('archive') && (
                                        <Button
                                            variant="destructive"
                                            size="sm"
                                            onClick={() => void archive(plan)}
                                        >
                                            Arsipkan
                                        </Button>
                                    )}
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </CardContent>

            <Sheet
                open={editing !== undefined}
                onOpenChange={(open) => !open && setEditing(undefined)}
            >
                <SheetContent
                    ref={sheetContentRef}
                    side="right"
                    className="overflow-y-auto sm:max-w-5xl"
                >
                    <SheetHeader>
                        <SheetTitle>
                            {editing && 'id' in editing
                                ? 'Ubah perencanaan aset'
                                : 'Buat perencanaan aset'}
                        </SheetTitle>
                    </SheetHeader>
                    {editing && (
                        <div className="space-y-4 p-4">
                            <p className="text-muted-foreground text-sm">
                                Unit perencanaan dan penanggung jawab mengikuti
                                konteks aktif Anda.
                            </p>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field>
                                    <Input
                                        label="Tanggal perencanaan"
                                        type="date"
                                        value={editing.planned_on}
                                        onChange={(event) =>
                                            setEditing({
                                                ...editing,
                                                planned_on: event.target.value,
                                                planning_year: Number(
                                                    event.target.value.slice(
                                                        0,
                                                        4,
                                                    ),
                                                ),
                                            })
                                        }
                                        required
                                    />
                                </Field>
                                <Field>
                                    <Input
                                        label="Tahun perencanaan"
                                        type="number"
                                        value={editing.planning_year}
                                        onChange={(event) =>
                                            setEditing({
                                                ...editing,
                                                planning_year: Number(
                                                    event.target.value,
                                                ),
                                            })
                                        }
                                        required
                                    />
                                </Field>
                                <Field>
                                    <Select
                                        label="Jenis perencanaan"
                                        required
                                        items={['Reguler', 'Tambahan']}
                                        value={
                                            editing.planning_type === 'regular'
                                                ? 'Reguler'
                                                : 'Tambahan'
                                        }
                                        searchPlaceholder="Cari jenis perencanaan"
                                        ariaLabel="Jenis perencanaan"
                                        portalContainer={sheetContentRef}
                                        onValueChange={(value) =>
                                            setEditing({
                                                ...editing,
                                                planning_type:
                                                    value === 'Tambahan'
                                                        ? 'additional'
                                                        : 'regular',
                                            })
                                        }
                                    />
                                </Field>
                                <Field>
                                    <Input
                                        label="Sumber dana"
                                        value={editing.funding_source ?? ''}
                                        onChange={(event) =>
                                            setEditing({
                                                ...editing,
                                                funding_source:
                                                    event.target.value,
                                            })
                                        }
                                    />
                                </Field>
                            </div>
                            <Field>
                                <FieldLabel htmlFor="planning-description">
                                    Keterangan
                                </FieldLabel>
                                <Textarea
                                    id="planning-description"
                                    rows={6}
                                    className="min-h-36 resize-y"
                                    value={editing.description ?? ''}
                                    onChange={(event) =>
                                        setEditing({
                                            ...editing,
                                            description: event.target.value,
                                        })
                                    }
                                />
                            </Field>
                            <div className="space-y-3">
                                <div className="flex items-center justify-between">
                                    <h3 className="font-medium">
                                        Rincian aset
                                    </h3>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            setEditing({
                                                ...editing,
                                                details: [
                                                    ...editing.details,
                                                    emptyDetail(),
                                                ],
                                            })
                                        }
                                    >
                                        Tambah baris
                                    </Button>
                                </div>
                                {editing.details.map((detail, index) => (
                                    <AssetDetailRow
                                        key={index}
                                        detail={detail}
                                        index={index}
                                        canRemove={editing.details.length > 1}
                                        types={types}
                                        units={units}
                                        portalContainer={sheetContentRef}
                                        onTypeSearch={setTypeSearch}
                                        onChange={(change) =>
                                            updateDetail(index, change)
                                        }
                                        onRemove={() =>
                                            setEditing({
                                                ...editing,
                                                details: editing.details.filter(
                                                    (_, position) =>
                                                        position !== index,
                                                ),
                                            })
                                        }
                                    />
                                ))}
                            </div>
                            <SheetFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setEditing(undefined)}
                                >
                                    Batal
                                </Button>
                                <Button
                                    type="button"
                                    disabled={saving}
                                    onClick={() => void save()}
                                >
                                    {saving ? 'Menyimpan…' : 'Simpan'}
                                </Button>
                            </SheetFooter>
                        </div>
                    )}
                </SheetContent>
            </Sheet>
        </Card>
    );
}
