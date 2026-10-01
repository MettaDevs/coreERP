import { useCallback, useEffect, useRef, useState } from 'react';
import type { Key } from 'react';
import { toast } from 'sonner';
import { useToday } from '@/hooks/use-work-date';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import { DataTable } from '@apperp/ui/data-table';
import type {
    DataTableColumn,
    DataTableRowAction,
} from '@apperp/ui/data-table';
import {
    Dialog,
    DialogAction,
    DialogBody,
    DialogCancel,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@apperp/ui/dialog';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@apperp/ui/empty';
import { Input } from '@apperp/ui/input';
import { RadioGroup, RadioGroupItem } from '@apperp/ui/radio-group';
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { Select } from '@apperp/ui/select';
import { api, errorMessage, toastSaveError } from '../../api';
import { optionLabel, useMasterOptions } from '../../master/useMasterOptions';

type ScheduleLine = {
    id: string;
    version: number;
    status: 'usulan' | 'work_order_dibuat' | 'diabaikan';
    jatuh_tempo: string;
    terlambat: boolean;
    nilai_jatuh_tempo: string | null;
    nilai_counter: string | null;
    rencana_kode: string | null;
    rencana_nama: string | null;
    dasar: 'tanggal_mulai' | 'work_order_terakhir' | 'nilai_counter' | null;
    interval: number | null;
    satuan_interval: string | null;
    job_type_nama: string | null;
    jenis_counter_nama: string | null;
    jenis_counter_satuan: string | null;
    aset_kode: string | null;
    aset_nama: string | null;
    pemeliharaan_aset_id: string | null;
    work_order_kode: string | null;
};

const STATUS = {
    usulan: { label: 'Usulan', variant: 'outline' },
    work_order_dibuat: { label: 'Work order dibuat', variant: 'secondary' },
    diabaikan: { label: 'Diabaikan', variant: 'outline' },
} as const;

const STATUS_FILTER = [
    { value: 'usulan', label: 'Usulan' },
    { value: 'work_order_dibuat', label: 'Work order dibuat' },
    { value: 'diabaikan', label: 'Diabaikan' },
    { value: 'semua', label: 'Semua status' },
];

/** Tanggal kalender `YYYY-MM-DD` menjadi `DD/MM/YYYY`, tanpa zona waktu. */
const showDate = (value: string | null | undefined) => {
    if (!value) {
        return '—';
    }

    const [year, month, day] = value.slice(0, 10).split('-');

    return day && month && year ? `${day}/${month}/${year}` : value;
};

const plainNumber = (value: string | null) =>
    value === null ? '' : String(Number(value));

/** Apa yang membuat baris ini jatuh tempo, dalam satu kalimat pendek. */
function trigger(line: ScheduleLine): string {
    if (line.dasar === 'nilai_counter') {
        const satuan = line.jenis_counter_satuan ?? '';

        return `${line.jenis_counter_nama ?? 'Counter'} ${plainNumber(line.nilai_jatuh_tempo)} ${satuan} (tercatat ${plainNumber(line.nilai_counter)})`;
    }

    const basis =
        line.dasar === 'work_order_terakhir'
            ? 'dari work order terakhir'
            : 'dari tanggal mulai';

    return `Setiap ${line.interval ?? ''} ${line.satuan_interval ?? ''}, ${basis}`;
}

/** Tanggal `YYYY-MM-DD` ditambah sekian hari, dihitung sebagai tanggal kalender. */
function addDays(date: string, days: number): string {
    const [year, month, day] = date.split('-').map(Number);
    const result = new Date(Date.UTC(year, month - 1, day + days));

    return result.toISOString().slice(0, 10);
}

/**
 * Jadwal pemeliharaan; padanan *Maintenance schedule* F&O.
 *
 * Usulan dihitung dari rencana pemeliharaan lewat **Hitung jadwal**, lalu yang dipilih dibuatkan
 * work order atau diabaikan. Menghitung ulang aman: jatuh tempo yang sudah diusulkan, dibuatkan
 * work order, atau diabaikan tidak muncul dua kali.
 */
export default function MaintenanceSchedulePage({
    permissions,
}: {
    context: {
        legal_entity_id: string | null;
        org_unit_id: string | null;
        user_id: string;
    };
    permissions: string[];
}) {
    const today = useToday();
    const canRun = permissions.includes(
        'management-aset.jadwal-pemeliharaan.run',
    );
    const canDiscard = permissions.includes(
        'management-aset.jadwal-pemeliharaan.discard',
    );
    const canCreateWorkOrder = permissions.includes(
        'management-aset.pemeliharaan-aset.create',
    );
    const [rows, setRows] = useState<ScheduleLine[]>([]);
    const [status, setStatus] = useState('usulan');
    const [loading, setLoading] = useState(true);
    const [reload, setReload] = useState(0);
    const [selected, setSelected] = useState<Key[]>([]);
    const [running, setRunning] = useState(false);
    const [until, setUntil] = useState(() => addDays(today, 30));
    const [planId, setPlanId] = useState('');
    const [converting, setConverting] = useState(false);
    const [grouping, setGrouping] = useState<'baris' | 'aset'>('baris');
    const [discarding, setDiscarding] = useState<ScheduleLine | null>(null);
    const [busy, setBusy] = useState(false);
    const runRef = useRef<HTMLDivElement>(null);
    const plans = useMasterOptions(running ? 'rencana-pemeliharaan' : null);

    useEffect(() => {
        let cancelled = false;

        api<{ data: ScheduleLine[] }>(`/jadwal-pemeliharaan?status=${status}`)
            .then((result) => {
                if (!cancelled) {
                    setRows(result.data);
                    setSelected([]);
                }
            })
            .catch((caught) => {
                if (!cancelled) {
                    toast.error(
                        errorMessage(caught, 'Jadwal belum dapat dimuat.'),
                    );
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setLoading(false);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [status, reload]);

    const refresh = useCallback(() => {
        setLoading(true);
        setReload((current) => current + 1);
    }, []);

    async function run() {
        setBusy(true);

        try {
            const result = await api<{
                data: { dibuat: number; dibersihkan: number };
            }>('/jadwal-pemeliharaan/hitung', {
                method: 'POST',
                body: JSON.stringify({
                    sampai: until,
                    rencana_pemeliharaan_id: planId || null,
                }),
            });
            const { dibuat, dibersihkan } = result.data;
            toast.success(
                dibuat === 0 && dibersihkan === 0
                    ? 'Jadwal sudah terkini. Tidak ada usulan baru.'
                    : `${dibuat} usulan baru${dibersihkan > 0 ? `, ${dibersihkan} usulan lama dibersihkan` : ''}.`,
            );
            setRunning(false);
            refresh();
        } catch (caught) {
            toastSaveError(caught, 'Jadwal belum dapat dihitung.');
        } finally {
            setBusy(false);
        }
    }

    async function convert() {
        setBusy(true);
        const chosen = rows.filter((row) => selected.includes(row.id));

        try {
            const result = await api<{
                data: { work_orders: { id: string; kode: string }[] };
            }>('/jadwal-pemeliharaan/work-order', {
                method: 'POST',
                body: JSON.stringify({
                    kelompok: grouping,
                    lines: chosen.map((row) => ({
                        id: row.id,
                        version: row.version,
                    })),
                }),
            });
            const numbers = result.data.work_orders.map((wo) => wo.kode);
            toast.success(
                numbers.length === 1
                    ? `Work order ${numbers[0]} dibuat.`
                    : `${numbers.length} work order dibuat: ${numbers.join(', ')}.`,
            );
            setConverting(false);
            refresh();
        } catch (caught) {
            toastSaveError(caught, 'Work order belum dapat dibuat.');
            refresh();
        } finally {
            setBusy(false);
        }
    }

    async function discard(line: ScheduleLine) {
        setDiscarding(null);

        try {
            await api(`/jadwal-pemeliharaan/${line.id}/abaikan`, {
                method: 'POST',
                body: JSON.stringify({ version: line.version }),
            });
            toast.success('Usulan diabaikan.');
            refresh();
        } catch (caught) {
            toastSaveError(caught, 'Usulan belum dapat diabaikan.');
            refresh();
        }
    }

    const columns: DataTableColumn<ScheduleLine>[] = [
        {
            id: 'jatuh_tempo',
            header: 'Jatuh tempo',
            cell: (row) => (
                <span className="flex items-center gap-2">
                    {showDate(row.jatuh_tempo)}
                    {row.terlambat && (
                        <Badge variant="destructive">Terlambat</Badge>
                    )}
                </span>
            ),
            sortValue: (row) => row.jatuh_tempo,
            width: 190,
        },
        {
            id: 'aset',
            header: 'Aset',
            cell: (row) =>
                [row.aset_kode, row.aset_nama].filter(Boolean).join(' · '),
            sortValue: (row) => row.aset_kode ?? '',
            minWidth: 180,
            width: 240,
        },
        {
            id: 'pekerjaan',
            header: 'Jenis pekerjaan',
            cell: (row) => row.job_type_nama ?? '—',
            sortValue: (row) => row.job_type_nama ?? '',
            width: 180,
        },
        {
            id: 'pemicu',
            header: 'Dasar',
            cell: (row) => trigger(row),
            minWidth: 200,
            width: 260,
        },
        {
            id: 'rencana',
            header: 'Rencana',
            cell: (row) => row.rencana_nama ?? '—',
            sortValue: (row) => row.rencana_nama ?? '',
            width: 180,
        },
        {
            id: 'status',
            header: 'Status',
            cell: (row) => (
                <Badge variant={STATUS[row.status].variant}>
                    {STATUS[row.status].label}
                </Badge>
            ),
            sortValue: (row) => STATUS[row.status].label,
            width: 160,
        },
        {
            id: 'wo',
            header: 'Work order',
            cell: (row) =>
                row.pemeliharaan_aset_id ? (
                    <a
                        className="text-primary font-medium hover:underline"
                        href={`/management-aset/pemeliharaan-aset/${row.pemeliharaan_aset_id}`}
                        onClick={(event) => event.stopPropagation()}
                    >
                        {row.work_order_kode}
                    </a>
                ) : (
                    '—'
                ),
            width: 150,
        },
    ];

    const actions: DataTableRowAction[] = canDiscard
        ? [{ id: 'discard', label: 'Abaikan', destructive: true }]
        : [];
    const selectable = status === 'usulan' && canCreateWorkOrder;

    return (
        <div className="space-y-0">
            <RecordActionBar title="Jadwal pemeliharaan">
                {canRun && (
                    <Button type="button" onClick={() => setRunning(true)}>
                        Hitung jadwal
                    </Button>
                )}
                {selectable && (
                    <Button
                        type="button"
                        variant="outline"
                        disabled={selected.length === 0}
                        onClick={() => setConverting(true)}
                    >
                        Buat work order
                        {selected.length > 0 ? ` (${selected.length})` : ''}
                    </Button>
                )}
            </RecordActionBar>

            <div className="flex flex-col gap-3 border-b px-5 py-3 sm:flex-row sm:items-end">
                <div className="w-full sm:w-52">
                    <Select
                        label="Status"
                        items={STATUS_FILTER}
                        value={status}
                        ariaLabel="Saring berdasarkan status"
                        onValueChange={(value) => {
                            setLoading(true);
                            setStatus(value ?? 'usulan');
                        }}
                    />
                </div>
            </div>

            {!loading && rows.length === 0 ? (
                <Empty>
                    <EmptyHeader>
                        <EmptyTitle>Tidak ada usulan jadwal</EmptyTitle>
                        <EmptyDescription>
                            Usulan muncul setelah jadwal dihitung dari rencana
                            pemeliharaan yang aktif.
                        </EmptyDescription>
                    </EmptyHeader>
                </Empty>
            ) : (
                <DataTable
                    columns={columns}
                    data={rows}
                    getRowKey={(row) => row.id}
                    getRowLabel={(row) =>
                        `${row.aset_kode ?? ''} ${showDate(row.jatuh_tempo)}`
                    }
                    actions={actions}
                    selection={
                        selectable
                            ? {
                                  selectedKeys: selected,
                                  onSelectedKeysChange: setSelected,
                              }
                            : undefined
                    }
                    onRowAction={(action, row) => {
                        if (action === 'discard') {
                            if (row.status !== 'usulan') {
                                toast.error(
                                    'Hanya usulan yang belum dibuatkan work order yang dapat diabaikan.',
                                );

                                return;
                            }

                            setDiscarding(row);
                        }
                    }}
                />
            )}

            <Dialog open={running} onOpenChange={setRunning}>
                <DialogContent size="compact" ref={runRef}>
                    <DialogHeader>
                        <DialogTitle>Hitung jadwal</DialogTitle>
                        <DialogDescription>
                            Jatuh tempo dihitung dari rencana aktif sampai
                            tanggal batas. Yang sudah diusulkan tidak diusulkan
                            dua kali.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogBody className="space-y-4">
                        <Input
                            label="Hitung sampai tanggal"
                            type="date"
                            required
                            min={today}
                            max={addDays(today, 366)}
                            value={until}
                            onChange={(event) => setUntil(event.target.value)}
                        />
                        <Select
                            label="Rencana"
                            items={[
                                { value: '', label: 'Semua rencana aktif' },
                                ...plans.options.map((plan) => ({
                                    value: plan.id,
                                    label: optionLabel(plan),
                                })),
                            ]}
                            value={planId || null}
                            placeholder="Semua rencana aktif"
                            ariaLabel="Rencana"
                            portalContainer={runRef}
                            onValueChange={(value) => setPlanId(value ?? '')}
                        />
                    </DialogBody>
                    <DialogFooter>
                        <DialogAction
                            type="button"
                            disabled={busy || !until}
                            onClick={() => void run()}
                        >
                            {busy ? 'Menghitung…' : 'Hitung'}
                        </DialogAction>
                        <DialogCancel type="button">Batal</DialogCancel>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={converting} onOpenChange={setConverting}>
                <DialogContent size="compact">
                    <DialogHeader>
                        <DialogTitle>Buat work order</DialogTitle>
                        <DialogDescription>
                            {selected.length} usulan terpilih menjadi work order
                            draf, lengkap dengan nomor dan checklist bawaan
                            jenis pekerjaannya.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogBody>
                        <RadioGroup
                            value={grouping}
                            onValueChange={(value) =>
                                setGrouping(value as 'baris' | 'aset')
                            }
                        >
                            <label className="flex items-start gap-2 text-sm">
                                <RadioGroupItem value="baris" />
                                <span>Satu work order per usulan</span>
                            </label>
                            <label className="flex items-start gap-2 text-sm">
                                <RadioGroupItem value="aset" />
                                <span>
                                    Gabungkan per aset — usulan satu aset dengan
                                    tipe work order yang sama menjadi satu work
                                    order.
                                </span>
                            </label>
                        </RadioGroup>
                    </DialogBody>
                    <DialogFooter>
                        <DialogAction
                            type="button"
                            disabled={busy}
                            onClick={() => void convert()}
                        >
                            {busy ? 'Membuat…' : 'Buat work order'}
                        </DialogAction>
                        <DialogCancel type="button">Batal</DialogCancel>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog
                open={discarding !== null}
                onOpenChange={(open) => !open && setDiscarding(null)}
            >
                <DialogContent size="compact">
                    <DialogHeader>
                        <DialogTitle>Abaikan usulan ini?</DialogTitle>
                        <DialogDescription>
                            Jatuh tempo yang diabaikan tidak diusulkan lagi saat
                            jadwal dihitung ulang.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <DialogAction
                            type="button"
                            variant="destructive"
                            onClick={() =>
                                discarding && void discard(discarding)
                            }
                        >
                            Abaikan
                        </DialogAction>
                        <DialogCancel type="button">Batal</DialogCancel>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
