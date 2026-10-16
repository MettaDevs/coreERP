import { router } from '@inertiajs/react';
import type { RefObject } from 'react';
import { useRef, useState } from 'react';
import { toast } from 'sonner';
import { useWorkDate } from '@/hooks/use-work-date';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import {
    Card,
    CardAction,
    CardContent,
    CardHeader,
    CardTitle,
} from '@apperp/ui/card';
import { Checkbox } from '@apperp/ui/checkbox';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@apperp/ui/empty';
import { Field, FieldLabel } from '@apperp/ui/field';
import { IncrementalListStatus } from '@apperp/ui/incremental-list-status';
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
import {
    api,
    errorMessage,
    newIdempotencyKey,
    toastSaveError,
} from '../../api';
import { useMasterOptions } from '../../master/useMasterOptions';
import { useRecordList } from '../../use-record-list';
import { useRecordOptions } from '../../use-record-list';

type Context = { legal_entity_id: string | null; org_unit_id: string | null };
type Permission = string;
type AsetType = { id: string; kode: string; nama: string };
type Detail = {
    id?: string;
    nama_aset?: string;
    unit?: string;
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

// Tanggal dan tahun rencana dari tanggal kerja pengguna, bukan dari jam perangkat.
const emptyPlan = (
    workDate: string,
): Omit<Plan, 'id' | 'kode' | 'version' | 'status'> & {
    details: Detail[];
} => ({
    planned_on: workDate,
    planning_year: Number(workDate.slice(0, 4)),
    planning_type: 'regular',
    funding_source: '',
    description: '',
    total_estimated_value: 0,
    details: [emptyDetail()],
});

function AsetDetailRow({
    detail,
    index,
    canRemove,
    portalContainer,
    onChange,
    onRemove,
}: {
    detail: Detail;
    index: number;
    canRemove: boolean;
    portalContainer: RefObject<HTMLDivElement | null>;
    onChange: (change: Partial<Detail>) => void;
    onRemove: () => void;
}) {
    const typeSource = useMasterOptions('jenis-aset', '', detail.jenis_aset_id);
    const unitSource = useRecordOptions<AsetType>(
        '/reference-data/units-of-measure',
        (item) => item.nama,
        detail.satuan_id,
    );

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
                        {...typeSource.selectProps}
                        items={typeSource.options.map((type) => ({
                            value: type.id,
                            label: type.nama,
                        }))}
                        value={detail.jenis_aset_id || null}
                        placeholder="Pilih jenis aset"
                        searchPlaceholder="Cari jenis aset"
                        ariaLabel={`Jenis aset baris ${index + 1}`}
                        portalContainer={portalContainer}
                        onValueChange={(value) =>
                            onChange({
                                jenis_aset_id: value ?? '',
                            })
                        }
                    />
                </Field>
                <Field>
                    <Select
                        label="Satuan"
                        required
                        {...unitSource.selectProps}
                        items={unitSource.options.map((unit) => ({
                            value: unit.id,
                            label: unit.nama,
                        }))}
                        value={detail.satuan_id || null}
                        placeholder="Pilih satuan"
                        searchPlaceholder="Cari satuan"
                        ariaLabel={`Satuan baris ${index + 1}`}
                        portalContainer={portalContainer}
                        onValueChange={(value) =>
                            onChange({
                                satuan_id: value ?? '',
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
    const { date: workDate } = useWorkDate();
    const [editing, setEditing] = useState<
        (Plan & { details: Detail[] }) | null | undefined
    >();
    const [viewing, setViewing] = useState<Plan | null>(null);
    const [selectedLines, setSelectedLines] = useState<string[]>([]);
    const [manualCode, setManualCode] = useState('');
    const [numberPolicy, setNumberPolicy] = useState<{
        allow_manual: boolean;
        allow_automatic: boolean;
    } | null>(null);
    const [saving, setSaving] = useState(false);
    const sheetContentRef = useRef<HTMLDivElement>(null);

    // Effect adalah satu-satunya pemilik pengambilan daftar rencana. Pemuatan ulang
    // setelah simpan atau arsip dinyatakan dengan menaikkan penanda ini.
    const [versiMuat, setVersiMuat] = useState(0);
    const muatUlang = () => setVersiMuat((versi) => versi + 1);

    const list = useRecordList<Plan>('/perencanaan-aset', '', {
        refresh: String(versiMuat),
    });
    const plans = list.items;
    const openCreate = async () => {
        setNumberPolicy(null);
        setManualCode('');

        try {
            const result = await api<{
                data: { allow_manual: boolean; allow_automatic: boolean };
            }>('/perencanaan-aset/number-policy');
            setNumberPolicy(result.data);
            setEditing(emptyPlan(workDate) as never);
        } catch (caught) {
            toast.error(
                errorMessage(caught, 'Pengaturan kode belum dapat dimuat.'),
            );
        }
    };

    const openDetails = async (id: string) => {
        try {
            const result = await api<{ data: Plan }>(`/perencanaan-aset/${id}`);
            setViewing(result.data);
            setSelectedLines(
                (result.data.details ?? []).flatMap((line) =>
                    line.id ? [line.id] : [],
                ),
            );
        } catch (caught) {
            toast.error(
                errorMessage(caught, 'Rincian perencanaan belum dapat dimuat.'),
            );
        }
    };

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
        if (!window.confirm(`Arsipkan rencana ${plan.kode}?`)) {
            return;
        }

        try {
            await api(`/perencanaan-aset/${plan.id}`, {
                method: 'DELETE',
                body: JSON.stringify({ version: plan.version }),
            });
            muatUlang();
        } catch (caught) {
            toastSaveError(caught, 'Rencana aset belum dapat diarsipkan.');
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
                'Pilih perusahaan dan unit kerja aktif sebelum membuat rencana.',
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

        if (
            !('id' in editing) &&
            !numberPolicy?.allow_automatic &&
            !manualCode.trim()
        ) {
            toast.error('Isi kode perencanaan terlebih dahulu.');

            return;
        }

        setSaving(true);
        const body = {
            ...(!('id' in editing) ? { kode: manualCode.trim() || null } : {}),
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
            muatUlang();
            toast.success('Rencana aset disimpan.');
        } catch (caught) {
            toastSaveError(caught, 'Rencana aset belum dapat disimpan.');
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
                        <Button onClick={() => void openCreate()}>
                            Tambah rencana
                        </Button>
                    </CardAction>
                )}
            </CardHeader>
            <CardContent className="px-0">
                {!plans.length && !list.loading && !list.error ? (
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
                                <div className="min-w-0 flex-1">
                                    <p
                                        className="truncate font-medium"
                                        title={`${plan.kode} - ${plan.description || 'Tanpa tujuan'}`}
                                    >
                                        {plan.kode} ·{' '}
                                        {plan.description || 'Tanpa tujuan'}
                                    </p>
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
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            void openDetails(plan.id)
                                        }
                                    >
                                        Buka rincian
                                    </Button>
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
                <IncrementalListStatus state={list} />
            </CardContent>

            <Sheet
                open={viewing !== null}
                onOpenChange={(open) => !open && setViewing(null)}
            >
                <SheetContent
                    side="right"
                    className="overflow-y-auto sm:max-w-3xl"
                >
                    <SheetHeader>
                        <SheetTitle
                            className="truncate"
                            title={`${viewing?.kode ?? ''} - ${viewing?.description || 'Tanpa tujuan'}`}
                        >
                            {viewing?.kode} ·{' '}
                            {viewing?.description || 'Tanpa tujuan'}
                        </SheetTitle>
                    </SheetHeader>
                    <div className="space-y-4 p-4">
                        <p className="text-muted-foreground text-sm">
                            {viewing?.planned_on.slice(0, 10)} · Pilih baris
                            yang akan dibuatkan permintaan.
                        </p>
                        {viewing?.details?.map((line, index) => (
                            <label
                                key={line.id ?? index}
                                className="flex items-start gap-3 rounded-lg border p-3"
                            >
                                <Checkbox
                                    aria-label={`Pilih baris ${index + 1}`}
                                    checked={selectedLines.includes(
                                        line.id ?? '',
                                    )}
                                    onCheckedChange={(checked) => {
                                        if (line.id) {
                                            setSelectedLines((current) =>
                                                checked
                                                    ? [...current, line.id!]
                                                    : current.filter(
                                                          (id) =>
                                                              id !== line.id,
                                                      ),
                                            );
                                        }
                                    }}
                                />
                                <span className="space-y-1">
                                    <span className="block font-medium">
                                        {line.nama_aset}
                                    </span>
                                    <span className="block text-sm">
                                        {line.quantity} {line.unit} ·{' '}
                                        {line.requested_specification}
                                    </span>
                                </span>
                            </label>
                        ))}
                        <SheetFooter>
                            {permissions.includes(
                                'management-aset.permintaan-pembelian-aset.create',
                            ) && (
                                <Button
                                    disabled={selectedLines.length === 0}
                                    onClick={() => {
                                        if (!viewing) {
                                            return;
                                        }

                                        const query = new URLSearchParams({
                                            planning: viewing.id,
                                            lines: selectedLines.join(','),
                                        });
                                        router.visit(
                                            `/management-aset/permintaan-pembelian-aset?${query.toString()}`,
                                        );
                                    }}
                                >
                                    Buat permintaan
                                </Button>
                            )}
                            <Button
                                variant="outline"
                                onClick={() => setViewing(null)}
                            >
                                Tutup
                            </Button>
                        </SheetFooter>
                    </div>
                </SheetContent>
            </Sheet>

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
                            <Input
                                label="Kode perencanaan"
                                value={
                                    'id' in editing
                                        ? editing.kode
                                        : numberPolicy?.allow_manual
                                          ? manualCode
                                          : 'Dibuat otomatis saat disimpan'
                                }
                                disabled={
                                    'id' in editing ||
                                    !numberPolicy?.allow_manual
                                }
                                required={
                                    !('id' in editing) &&
                                    !numberPolicy?.allow_automatic
                                }
                                placeholder={
                                    numberPolicy?.allow_automatic
                                        ? 'Kosongkan untuk membuat kode otomatis'
                                        : 'Masukkan kode'
                                }
                                onChange={(event) =>
                                    setManualCode(event.target.value)
                                }
                            />
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
                                    Tujuan perencanaan
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
                                    <AsetDetailRow
                                        key={index}
                                        detail={detail}
                                        index={index}
                                        canRemove={editing.details.length > 1}
                                        portalContainer={sheetContentRef}
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
