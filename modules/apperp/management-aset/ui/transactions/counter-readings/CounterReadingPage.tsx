import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { useWorkDate } from '@/hooks/use-work-date';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import { Checkbox } from '@apperp/ui/checkbox';
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
import { Field, FieldDescription } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { Select } from '@apperp/ui/select';
import {
    ApiError,
    api,
    errorMessage,
    newIdempotencyKey,
    toastSaveError,
} from '../../api';
import type { MasterOption } from '../../master/useMasterOptions';
import { optionLabel, useMasterOptions } from '../../master/useMasterOptions';

type Reading = {
    id: string;
    version: number;
    aset_id: string;
    aset_kode: string;
    aset_nama: string | null;
    jenis_counter_id: string;
    jenis_counter_nama: string;
    satuan: string | null;
    dibaca_pada: string;
    nilai: string;
    nilai_total: string;
    reset: boolean;
    keterangan: string | null;
    dicatat_oleh_nama: string | null;
};

type AssetCounter = {
    jenis_counter_id: string;
    kode: string;
    nama: string;
    satuan: string | null;
    terakhir: {
        dibaca_pada: string;
        nilai: string;
        nilai_total: string;
    } | null;
};

type Draft = {
    aset_id: string;
    jenis_counter_id: string;
    tanggal: string;
    jam: string;
    nilai: string;
    reset: boolean;
    keterangan: string;
};

/** Angka desimal dari server ("490.00") tanpa nol yang tidak perlu. */
const plain = (value: string | null | undefined) =>
    value === null || value === undefined ? '—' : String(Number(value));

/**
 * Waktu baca tersimpan apa adanya seperti diketik petugas (jam setempat), jadi ditampilkan tanpa
 * konversi zona waktu.
 */
const showReadAt = (value: string) => {
    const [date, time] = value.split(' ');
    const [year, month, day] = date.split('-');

    return `${day}/${month}/${year} ${time?.slice(0, 5) ?? ''}`.trim();
};

/**
 * Pembacaan counter aset; padanan *Asset counters* F&O.
 *
 * Petugas mencatat angka yang tertera di meter. Total pemakaian dihitung server dari pembacaan
 * sebelumnya, dan rencana pemeliharaan berbasis counter membaca total itu.
 */
export default function CounterReadingPage({
    permissions,
}: {
    context: {
        legal_entity_id: string | null;
        org_unit_id: string | null;
        user_id: string;
    };
    permissions: string[];
}) {
    const canCreate = permissions.includes(
        'management-aset.pembacaan-counter.create',
    );
    const canArchive = permissions.includes(
        'management-aset.pembacaan-counter.archive',
    );
    const { date: workDate } = useWorkDate();
    const [rows, setRows] = useState<Reading[]>([]);
    const [loading, setLoading] = useState(true);
    const [reload, setReload] = useState(0);
    const [filterAset, setFilterAset] = useState('');
    const [filterCounter, setFilterCounter] = useState('');
    const [assets, setAssets] = useState<MasterOption[]>([]);
    const [assetError, setAssetError] = useState('');
    const counterTypes = useMasterOptions('jenis-counter');
    const [recording, setRecording] = useState(false);
    const [draft, setDraft] = useState<Draft | null>(null);
    const [assetCounters, setAssetCounters] = useState<{
        asetId: string;
        counters: AssetCounter[];
    } | null>(null);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [busy, setBusy] = useState(false);
    const [archiving, setArchiving] = useState<Reading | null>(null);
    const dialogRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        let cancelled = false;
        api<{ data: MasterOption[] }>('/aset')
            .then((result) => !cancelled && setAssets(result.data))
            .catch(
                () =>
                    !cancelled &&
                    setAssetError(
                        'Daftar aset belum dapat dimuat. Anda memerlukan akses lihat register aset.',
                    ),
            );

        return () => {
            cancelled = true;
        };
    }, []);

    useEffect(() => {
        let cancelled = false;
        const query = new URLSearchParams(
            Object.entries({
                aset_id: filterAset,
                jenis_counter_id: filterCounter,
            }).filter(([, value]) => Boolean(value)),
        ).toString();

        api<{ data: Reading[] }>(
            `/pembacaan-counter${query ? `?${query}` : ''}`,
        )
            .then((result) => !cancelled && setRows(result.data))
            .catch(
                (caught) =>
                    !cancelled &&
                    toast.error(
                        errorMessage(
                            caught,
                            'Pembacaan counter belum dapat dimuat.',
                        ),
                    ),
            )
            .finally(() => !cancelled && setLoading(false));

        return () => {
            cancelled = true;
        };
    }, [filterAset, filterCounter, reload]);

    // Counter yang berlaku untuk aset terpilih, beserta pembacaan terakhirnya.
    const draftAset = draft?.aset_id ?? '';
    useEffect(() => {
        if (!draftAset) {
            return;
        }

        let cancelled = false;
        api<{ data: AssetCounter[] }>(
            `/pembacaan-counter/counter-aset?aset_id=${draftAset}`,
        )
            .then(
                (result) =>
                    !cancelled &&
                    setAssetCounters({
                        asetId: draftAset,
                        counters: result.data,
                    }),
            )
            .catch((caught) => {
                if (!cancelled) {
                    setAssetCounters({ asetId: draftAset, counters: [] });
                    toast.error(
                        errorMessage(
                            caught,
                            'Counter aset belum dapat dimuat.',
                        ),
                    );
                }
            });

        return () => {
            cancelled = true;
        };
    }, [draftAset]);

    const counters =
        assetCounters && assetCounters.asetId === draftAset
            ? assetCounters.counters
            : [];
    const chosenCounter = counters.find(
        (counter) => counter.jenis_counter_id === draft?.jenis_counter_id,
    );
    const message = (field: string) => errors[field]?.[0];
    const refresh = () => {
        setLoading(true);
        setReload((current) => current + 1);
    };

    // Jam tidak diisi dari jam perangkat (standar module: jam peramban bisa salah zona); petugas
    // mengetik jam yang tertera saat ia membaca meter.
    function openRecord() {
        setDraft({
            aset_id: filterAset,
            jenis_counter_id: filterCounter,
            tanggal: workDate,
            jam: '',
            nilai: '',
            reset: false,
            keterangan: '',
        });
        setErrors({});
        setRecording(true);
    }

    async function record() {
        if (!draft) {
            return;
        }

        setBusy(true);
        setErrors({});

        try {
            await api('/pembacaan-counter', {
                method: 'POST',
                headers: { 'Idempotency-Key': newIdempotencyKey() },
                body: JSON.stringify({
                    aset_id: draft.aset_id,
                    jenis_counter_id: draft.jenis_counter_id,
                    dibaca_pada: `${draft.tanggal} ${draft.jam}:00`,
                    nilai: draft.nilai === '' ? null : Number(draft.nilai),
                    reset: draft.reset,
                    keterangan: draft.keterangan || null,
                }),
            });
            toast.success('Pembacaan counter dicatat.');
            setRecording(false);
            refresh();
        } catch (caught) {
            if (caught instanceof ApiError) {
                setErrors(caught.validationErrors);
            }

            toastSaveError(caught, 'Pembacaan counter belum dapat dicatat.');
        } finally {
            setBusy(false);
        }
    }

    async function archive(reading: Reading) {
        setArchiving(null);

        try {
            await api(`/pembacaan-counter/${reading.id}`, {
                method: 'DELETE',
                body: JSON.stringify({ version: reading.version }),
            });
            toast.success('Pembacaan diarsipkan.');
            refresh();
        } catch (caught) {
            toastSaveError(caught, 'Pembacaan belum dapat diarsipkan.');
        }
    }

    const assetChoices = assets.map((asset) => ({
        value: asset.id,
        label: optionLabel(asset),
    }));
    const counterChoices = counterTypes.options.map((counter) => ({
        value: counter.id,
        label: optionLabel(counter),
    }));

    const columns: DataTableColumn<Reading>[] = [
        {
            id: 'waktu',
            header: 'Waktu baca',
            cell: (row) => showReadAt(row.dibaca_pada),
            sortValue: (row) => row.dibaca_pada,
            width: 160,
        },
        {
            id: 'aset',
            header: 'Aset',
            cell: (row) =>
                [row.aset_kode, row.aset_nama].filter(Boolean).join(' · '),
            sortValue: (row) => row.aset_kode,
            minWidth: 180,
            width: 240,
        },
        {
            id: 'counter',
            header: 'Counter',
            cell: (row) => row.jenis_counter_nama,
            sortValue: (row) => row.jenis_counter_nama,
            width: 160,
        },
        {
            id: 'nilai',
            header: 'Angka meter',
            cell: (row) => (
                <span className="flex items-center gap-2">
                    {plain(row.nilai)}
                    {row.reset && (
                        <Badge variant="outline">Meter diganti</Badge>
                    )}
                </span>
            ),
            align: 'right',
            width: 170,
        },
        {
            id: 'total',
            header: 'Total pemakaian',
            cell: (row) =>
                `${plain(row.nilai_total)}${row.satuan ? ` ${row.satuan}` : ''}`,
            sortValue: (row) => Number(row.nilai_total),
            align: 'right',
            width: 160,
        },
        {
            id: 'oleh',
            header: 'Dicatat oleh',
            cell: (row) => row.dicatat_oleh_nama ?? '—',
            width: 160,
        },
        {
            id: 'keterangan',
            header: 'Keterangan',
            cell: (row) => row.keterangan ?? '—',
            minWidth: 160,
            width: 220,
        },
    ];

    const actions: DataTableRowAction[] = canArchive
        ? [{ id: 'archive', label: 'Arsipkan', destructive: true }]
        : [];

    return (
        <div>
            <RecordActionBar title="Pembacaan counter">
                {canCreate && (
                    <Button type="button" onClick={openRecord}>
                        Catat pembacaan
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
                        onValueChange={(value) => {
                            setLoading(true);
                            setFilterAset(value ?? '');
                        }}
                    />
                </div>
                <div className="w-full sm:w-64">
                    <Select
                        label="Counter"
                        items={[
                            { value: '', label: 'Semua counter' },
                            ...counterChoices,
                        ]}
                        value={filterCounter || null}
                        placeholder="Semua counter"
                        ariaLabel="Saring berdasarkan counter"
                        onValueChange={(value) => {
                            setLoading(true);
                            setFilterCounter(value ?? '');
                        }}
                    />
                </div>
                {assetError && (
                    <p className="text-muted-foreground text-sm">
                        {assetError}
                    </p>
                )}
            </div>

            {!loading && rows.length === 0 ? (
                <Empty>
                    <EmptyHeader>
                        <EmptyTitle>Belum ada pembacaan counter</EmptyTitle>
                        <EmptyDescription>
                            Catat angka meter aset secara berkala, misalnya jam
                            operasi atau jumlah tindakan, supaya pemeliharaan
                            berbasis pemakaian dapat dijadwalkan.
                        </EmptyDescription>
                    </EmptyHeader>
                </Empty>
            ) : (
                <DataTable
                    columns={columns}
                    data={rows}
                    getRowKey={(row) => row.id}
                    getRowLabel={(row) =>
                        `${row.aset_kode} ${showReadAt(row.dibaca_pada)}`
                    }
                    actions={actions}
                    onRowAction={(action, row) => {
                        if (action === 'archive') {
                            setArchiving(row);
                        }
                    }}
                />
            )}

            <Dialog open={recording} onOpenChange={setRecording}>
                <DialogContent size="compact" ref={dialogRef}>
                    <DialogHeader>
                        <DialogTitle>Catat pembacaan counter</DialogTitle>
                        <DialogDescription>
                            Ketik angka yang tertera di meter. Total pemakaian
                            dihitung otomatis.
                        </DialogDescription>
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
                                        setDraft({
                                            ...draft,
                                            aset_id: value ?? '',
                                            jenis_counter_id: '',
                                        })
                                    }
                                />
                                {message('aset_id') && (
                                    <FieldDescription>
                                        {message('aset_id')}
                                    </FieldDescription>
                                )}
                            </Field>
                            <Field>
                                <Select
                                    label="Counter"
                                    required
                                    items={counters.map((counter) => ({
                                        value: counter.jenis_counter_id,
                                        label: counter.satuan
                                            ? `${counter.nama} (${counter.satuan})`
                                            : counter.nama,
                                    }))}
                                    value={draft.jenis_counter_id || null}
                                    placeholder={
                                        draft.aset_id
                                            ? 'Pilih counter'
                                            : 'Pilih aset lebih dahulu'
                                    }
                                    ariaLabel="Counter"
                                    portalContainer={dialogRef}
                                    onValueChange={(value) =>
                                        setDraft({
                                            ...draft,
                                            jenis_counter_id: value ?? '',
                                        })
                                    }
                                />
                                <FieldDescription>
                                    {message('jenis_counter_id') ||
                                        (draft.aset_id &&
                                        assetCounters?.asetId ===
                                            draft.aset_id &&
                                        counters.length === 0
                                            ? 'Belum ada counter yang berlaku untuk jenis aset ini. Atur di Jenis counter atau pada Jenis aset.'
                                            : chosenCounter?.terakhir
                                              ? `Terakhir: ${plain(chosenCounter.terakhir.nilai)} ${chosenCounter.satuan ?? ''} pada ${showReadAt(chosenCounter.terakhir.dibaca_pada)}.`
                                              : chosenCounter
                                                ? 'Belum pernah dibaca.'
                                                : '')}
                                </FieldDescription>
                            </Field>
                            <div className="grid grid-cols-2 gap-3">
                                <Input
                                    label="Tanggal baca"
                                    type="date"
                                    required
                                    value={draft.tanggal}
                                    onChange={(event) =>
                                        setDraft({
                                            ...draft,
                                            tanggal: event.target.value,
                                        })
                                    }
                                />
                                <Input
                                    label="Jam baca"
                                    type="time"
                                    required
                                    value={draft.jam}
                                    onChange={(event) =>
                                        setDraft({
                                            ...draft,
                                            jam: event.target.value,
                                        })
                                    }
                                />
                            </div>
                            {message('dibaca_pada') && (
                                <p className="text-destructive text-sm">
                                    {message('dibaca_pada')}
                                </p>
                            )}
                            <Field>
                                <Input
                                    label="Angka meter"
                                    type="number"
                                    min="0"
                                    step="any"
                                    required
                                    value={draft.nilai}
                                    onChange={(event) =>
                                        setDraft({
                                            ...draft,
                                            nilai: event.target.value,
                                        })
                                    }
                                />
                                {message('nilai') && (
                                    <FieldDescription>
                                        {message('nilai')}
                                    </FieldDescription>
                                )}
                            </Field>
                            <label className="flex items-start gap-2 text-sm">
                                <Checkbox
                                    checked={draft.reset}
                                    onCheckedChange={(checked) =>
                                        setDraft({
                                            ...draft,
                                            reset: checked === true,
                                        })
                                    }
                                />
                                <span>
                                    Meter diganti atau direset
                                    <span className="text-muted-foreground block">
                                        Angka ini angka awal meter baru: tidak
                                        menambah total, dan boleh lebih kecil
                                        dari bacaan sebelumnya.
                                    </span>
                                </span>
                            </label>
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
                            disabled={
                                busy ||
                                !draft?.aset_id ||
                                !draft?.jenis_counter_id ||
                                !draft?.jam ||
                                draft?.nilai === ''
                            }
                            onClick={() => void record()}
                        >
                            {busy ? 'Menyimpan…' : 'Catat'}
                        </DialogAction>
                        <DialogCancel type="button">Batal</DialogCancel>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog
                open={archiving !== null}
                onOpenChange={(open) => !open && setArchiving(null)}
            >
                <DialogContent size="compact">
                    <DialogHeader>
                        <DialogTitle>Arsipkan pembacaan ini?</DialogTitle>
                        <DialogDescription>
                            Hanya pembacaan terakhir sebuah counter yang dapat
                            diarsipkan, karena total pembacaan sesudahnya
                            dihitung dari pembacaan ini. Catat ulang angka yang
                            benar sesudahnya.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <DialogAction
                            type="button"
                            variant="destructive"
                            onClick={() => archiving && void archive(archiving)}
                        >
                            Arsipkan
                        </DialogAction>
                        <DialogCancel type="button">Batal</DialogCancel>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
