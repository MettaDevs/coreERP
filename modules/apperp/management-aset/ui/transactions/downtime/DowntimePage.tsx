import { usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { useDateTimeFormat } from '@/hooks/use-date-time';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import { Checkbox } from '@apperp/ui/checkbox';
import { DataTable } from '@apperp/ui/data-table';
import type { DataTableColumn } from '@apperp/ui/data-table';
import {
    Dialog,
    DialogAction,
    DialogBody,
    DialogCancel,
    DialogContent,
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
import { Field, FieldDescription } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { Select } from '@apperp/ui/select';
import { zonedInput } from '../../_shared/format';
import {
    ApiError,
    api,
    errorMessage,
    newIdempotencyKey,
    toastSaveError,
} from '../../api';
import type { MasterOption } from '../../master/useMasterOptions';
import { optionLabel, useMasterOptions } from '../../master/useMasterOptions';
import type { Downtime } from './downtime';

type Draft = {
    id: string | null;
    version: number;
    aset_id: string;
    mulai: string;
    selesai: string;
    alasan_downtime_id: string;
    keterangan: string;
};

/**
 * Pencatatan downtime aset; padanan *Maintenance downtime* Dynamics 365 F&O.
 *
 * Downtime dari work order dibuka dan ditutup otomatis oleh perpindahan status work order; layar ini
 * untuk mencatat henti yang tidak lewat work order dan mengoreksi waktunya. Waktu diketik dan
 * ditampilkan menurut zona pengguna.
 */
export default function DowntimePage({
    permissions,
}: {
    permissions: string[];
}) {
    const can = (action: string) =>
        permissions.includes(`management-aset.downtime-aset.${action}`);
    const { clock } = usePage().props;
    const timeZone = clock?.timezone ?? 'UTC';
    const formatDateTime = useDateTimeFormat();
    const [rows, setRows] = useState<Downtime[] | null>(null);
    const [reload, setReload] = useState(0);
    const [filterAset, setFilterAset] = useState('');
    const [openOnly, setOpenOnly] = useState(false);
    const [assets, setAssets] = useState<MasterOption[]>([]);
    const reasons = useMasterOptions('alasan-downtime');
    const [draft, setDraft] = useState<Draft | null>(null);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [busy, setBusy] = useState(false);
    const dialogRef = useRef<HTMLDivElement>(null);
    const message = (field: string) => errors[field]?.[0];

    useEffect(() => {
        let cancelled = false;
        api<{ data: MasterOption[] }>('/aset')
            .then((result) => !cancelled && setAssets(result.data))
            .catch(() => !cancelled && setAssets([]));

        return () => {
            cancelled = true;
        };
    }, []);

    useEffect(() => {
        let cancelled = false;
        const query = new URLSearchParams(
            Object.entries({
                aset_id: filterAset,
                terbuka: openOnly ? '1' : '',
            }).filter(([, value]) => Boolean(value)),
        ).toString();
        api<{ data: Downtime[] }>(`/downtime-aset${query ? `?${query}` : ''}`)
            .then((result) => !cancelled && setRows(result.data))
            .catch(
                (caught) =>
                    !cancelled &&
                    toast.error(
                        errorMessage(caught, 'Downtime belum dapat dimuat.'),
                    ),
            );

        return () => {
            cancelled = true;
        };
    }, [filterAset, openOnly, reload]);

    async function save() {
        if (!draft) {
            return;
        }

        setBusy(true);
        setErrors({});
        const body = {
            mulai: draft.mulai,
            selesai: draft.selesai || null,
            alasan_downtime_id: draft.alasan_downtime_id || null,
            keterangan: draft.keterangan || null,
        };

        try {
            await (draft.id
                ? api(`/downtime-aset/${draft.id}`, {
                      method: 'PATCH',
                      body: JSON.stringify({ ...body, version: draft.version }),
                  })
                : api('/downtime-aset', {
                      method: 'POST',
                      headers: { 'Idempotency-Key': newIdempotencyKey() },
                      body: JSON.stringify({ ...body, aset_id: draft.aset_id }),
                  }));
            toast.success('Downtime disimpan.');
            setDraft(null);
            setReload((current) => current + 1);
        } catch (caught) {
            if (caught instanceof ApiError) {
                setErrors(caught.validationErrors);
            }

            toastSaveError(caught, 'Downtime belum dapat disimpan.');
        } finally {
            setBusy(false);
        }
    }

    async function archive(row: Downtime) {
        try {
            await api(`/downtime-aset/${row.id}`, {
                method: 'DELETE',
                body: JSON.stringify({ version: row.version }),
            });
            toast.success('Downtime diarsipkan.');
            setReload((current) => current + 1);
        } catch (caught) {
            toastSaveError(caught, 'Downtime belum dapat diarsipkan.');
        }
    }

    const assetChoices = assets.map((asset) => ({
        value: asset.id,
        label: optionLabel(asset),
    }));
    const columns: DataTableColumn<Downtime>[] = [
        {
            id: 'aset',
            header: 'Aset',
            cell: (row) =>
                [row.aset_kode, row.aset_nama].filter(Boolean).join(' · '),
            sortValue: (row) => row.aset_kode,
            minWidth: 200,
        },
        {
            id: 'mulai',
            header: 'Mulai',
            cell: (row) => formatDateTime(row.mulai),
            sortValue: (row) => row.mulai,
            width: 170,
        },
        {
            id: 'selesai',
            header: 'Selesai',
            cell: (row) =>
                row.terbuka ? (
                    <Badge variant="destructive">Masih berhenti</Badge>
                ) : (
                    formatDateTime(row.selesai)
                ),
            width: 170,
        },
        {
            id: 'durasi',
            header: 'Durasi (jam)',
            cell: (row) => row.durasi_jam.toLocaleString('id-ID'),
            sortValue: (row) => row.durasi_jam,
            align: 'right',
            width: 120,
        },
        {
            id: 'alasan',
            header: 'Alasan',
            cell: (row) => (
                <span className="flex items-center gap-2">
                    {row.alasan_downtime_nama ?? '—'}
                    {!row.masuk_kpi && (
                        <Badge variant="outline">Tidak dihitung KPI</Badge>
                    )}
                </span>
            ),
            width: 220,
        },
        {
            id: 'sumber',
            header: 'Sumber',
            cell: (row) =>
                [row.pemeliharaan_aset_kode, row.permintaan_pemeliharaan_kode]
                    .filter(Boolean)
                    .join(' · ') ||
                (row.sumber === 'work_order' ? 'Work order' : 'Dicatat manual'),
            width: 200,
        },
    ];

    const openEdit = (row: Downtime) => {
        setErrors({});
        setDraft({
            id: row.id,
            version: row.version,
            aset_id: row.aset_id,
            mulai: zonedInput(row.mulai, timeZone),
            selesai: zonedInput(row.selesai, timeZone),
            alasan_downtime_id: row.alasan_downtime_id ?? '',
            keterangan: row.keterangan ?? '',
        });
    };

    return (
        <div>
            <RecordActionBar title="Downtime aset">
                {can('create') && (
                    <Button
                        type="button"
                        onClick={() => {
                            setErrors({});
                            setDraft({
                                id: null,
                                version: 0,
                                aset_id: filterAset,
                                mulai: '',
                                selesai: '',
                                alasan_downtime_id: '',
                                keterangan: '',
                            });
                        }}
                    >
                        Catat downtime
                    </Button>
                )}
            </RecordActionBar>

            <div className="flex flex-col gap-3 border-b px-5 py-3 sm:flex-row sm:flex-wrap sm:items-end">
                <div className="w-full sm:w-80">
                    <Select
                        label="Aset"
                        items={[
                            { value: '', label: 'Semua aset' },
                            ...assetChoices,
                        ]}
                        value={filterAset || null}
                        placeholder="Semua aset"
                        searchPlaceholder="Cari kode atau nama aset"
                        ariaLabel="Saring berdasarkan aset"
                        onValueChange={(value) => setFilterAset(value ?? '')}
                    />
                </div>
                <label className="flex items-center gap-2 text-sm">
                    <Checkbox
                        checked={openOnly}
                        onCheckedChange={(checked) =>
                            setOpenOnly(checked === true)
                        }
                    />
                    Hanya aset yang masih berhenti
                </label>
            </div>

            {rows !== null && rows.length === 0 ? (
                <Empty>
                    <EmptyHeader>
                        <EmptyTitle>Belum ada downtime tercatat</EmptyTitle>
                        <EmptyDescription>
                            Downtime tercatat otomatis saat work order dengan
                            pekerjaan yang menuntut aset berhenti mulai
                            dikerjakan, atau dicatat di sini saat aset berhenti
                            di luar work order.
                        </EmptyDescription>
                    </EmptyHeader>
                </Empty>
            ) : (
                <DataTable
                    columns={columns}
                    data={rows ?? []}
                    getRowKey={(row) => row.id}
                    getRowLabel={(row) => row.aset_kode}
                    actions={[
                        ...(can('update')
                            ? [
                                  {
                                      id: 'ubah',
                                      label: 'Koreksi waktu atau alasan',
                                  },
                              ]
                            : []),
                        ...(can('archive')
                            ? [
                                  {
                                      id: 'arsip',
                                      label: 'Arsipkan',
                                      destructive: true,
                                  },
                              ]
                            : []),
                    ]}
                    onRowAction={(id, row) =>
                        id === 'arsip' ? void archive(row) : openEdit(row)
                    }
                />
            )}

            <Dialog
                open={draft !== null}
                onOpenChange={(open) => !open && setDraft(null)}
            >
                <DialogContent size="compact" ref={dialogRef}>
                    <DialogHeader>
                        <DialogTitle>
                            {draft?.id ? 'Koreksi downtime' : 'Catat downtime'}
                        </DialogTitle>
                    </DialogHeader>
                    {draft && (
                        <DialogBody className="space-y-4">
                            <Field>
                                <Select
                                    label="Aset"
                                    required
                                    items={assetChoices}
                                    value={draft.aset_id || null}
                                    placeholder="Pilih aset"
                                    searchPlaceholder="Cari kode atau nama aset"
                                    ariaLabel="Aset"
                                    portalContainer={dialogRef}
                                    onValueChange={(value) =>
                                        !draft.id &&
                                        setDraft({
                                            ...draft,
                                            aset_id: value ?? '',
                                        })
                                    }
                                />
                                {message('aset_id') && (
                                    <FieldDescription>
                                        {message('aset_id')}
                                    </FieldDescription>
                                )}
                            </Field>
                            <div className="grid grid-cols-2 gap-3">
                                <Input
                                    label="Mulai berhenti"
                                    type="datetime-local"
                                    required
                                    value={draft.mulai}
                                    onChange={(event) =>
                                        setDraft({
                                            ...draft,
                                            mulai: event.target.value,
                                        })
                                    }
                                />
                                <Field>
                                    <Input
                                        label="Kembali beroperasi"
                                        type="datetime-local"
                                        value={draft.selesai}
                                        onChange={(event) =>
                                            setDraft({
                                                ...draft,
                                                selesai: event.target.value,
                                            })
                                        }
                                    />
                                    <FieldDescription>
                                        Kosongkan bila aset masih berhenti.
                                    </FieldDescription>
                                </Field>
                            </div>
                            {(message('mulai') ?? message('selesai')) && (
                                <p className="text-destructive text-sm">
                                    {message('mulai') ?? message('selesai')}
                                </p>
                            )}
                            <Field>
                                <Select
                                    label="Alasan"
                                    items={reasons.options.map((option) => ({
                                        value: option.id,
                                        label: optionLabel(option),
                                    }))}
                                    value={draft.alasan_downtime_id || null}
                                    placeholder="Tanpa alasan"
                                    ariaLabel="Alasan downtime"
                                    portalContainer={dialogRef}
                                    onValueChange={(value) =>
                                        setDraft({
                                            ...draft,
                                            alasan_downtime_id: value ?? '',
                                        })
                                    }
                                />
                                <FieldDescription>
                                    Downtime tanpa alasan tetap mengurangi
                                    ketersediaan aset pada KPI. Pilih alasan
                                    yang tidak dihitung KPI untuk henti
                                    terencana.
                                </FieldDescription>
                            </Field>
                            <Input
                                label="Keterangan"
                                maxLength={2000}
                                value={draft.keterangan}
                                onChange={(event) =>
                                    setDraft({
                                        ...draft,
                                        keterangan: event.target.value,
                                    })
                                }
                            />
                        </DialogBody>
                    )}
                    <DialogFooter>
                        <DialogAction
                            type="button"
                            disabled={busy || !draft?.aset_id || !draft?.mulai}
                            onClick={() => void save()}
                        >
                            {busy ? 'Menyimpan…' : 'Simpan'}
                        </DialogAction>
                        <DialogCancel type="button">Batal</DialogCancel>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
