import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { useWorkDate } from '@/hooks/use-work-date';
import { ActionButton } from '@apperp/ui/action-button';
import { Badge } from '@apperp/ui/badge';

import { Checkbox } from '@apperp/ui/checkbox';
import { DataTable } from '@apperp/ui/data-table';
import type {
    DataTableColumn,
    DataTableRowAction,
} from '@apperp/ui/data-table';
import { DatePicker } from '@apperp/ui/date-picker';
import {
    DialogClose,
    Dialog,
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
import { FieldError } from '@apperp/ui/field';
import { FormControl, FormGrid } from '@apperp/ui/form-control';
import { InlineLookup } from '@apperp/ui/inline-lookup';
import { Input } from '@apperp/ui/input';
import {
    PageDialog,
    PageDialogContent,
    PageDialogToolbar,
    PageDialogClose,
    PageDialogBody,
} from '@apperp/ui/page-dialog';
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { useRecordTransition } from '@apperp/ui/use-record-transition';
import { RecordLookup } from '../../_shared/RecordLookup';
import { ApiError, api, errorMessage, newIdempotencyKey } from '../../api';
import { optionLabel, useMasterOptions } from '../../master/useMasterOptions';
import { useRecordList } from '../../use-record-list';
import { useSaveFeedback } from '../../use-save-feedback';
import { useRecordCardDirty } from '../_shared/use-record-card-dirty';

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
    const {
        scopeRef: pageFeedbackScopeRef,
        feedbackSummary: pageFeedbackSummary,
        reportSaveError: pageFeedbackReportSaveError,
        clearSaveFeedback: pageFeedbackClearSaveFeedback,
    } = useSaveFeedback();
    const pageFeedback = {
        reportSaveError: pageFeedbackReportSaveError,
        clearSaveFeedback: pageFeedbackClearSaveFeedback,
    };

    const canCreate = permissions.includes(
        'management-aset.pembacaan-counter.create',
    );
    const canArchive = permissions.includes(
        'management-aset.pembacaan-counter.archive',
    );
    const { date: workDate } = useWorkDate();
    const [reload, setReload] = useState(0);
    const [filterAset, setFilterAset] = useState('');
    const [filterCounter, setFilterCounter] = useState('');
    const assetSource = useMasterOptions('aset');
    const assets = assetSource.options;
    const assetError = assetSource.error;
    const counterTypes = useMasterOptions('jenis-counter');
    const [recording, setRecording] = useState(false);
    const [draft, setDraft] = useState<Draft | null>(null);
    const [assetCounters, setAssetCounters] = useState<{
        asetId: string;
        counters: AssetCounter[];
    } | null>(null);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [busy, setBusy] = useState(false);
    const [draftDirty, setDraftDirty] = useState(false);
    const [archiving, setArchiving] = useState<Reading | null>(null);
    const dialogRef = useRef<HTMLDivElement>(null);
    const {
        feedbackSummary: formFeedbackSummary,
        reportSaveError: formFeedbackReportSaveError,
        clearSaveFeedback: formFeedbackClearSaveFeedback,
    } = useSaveFeedback(dialogRef, recording ? 'reading' : 'closed');
    const formFeedback = {
        reportSaveError: formFeedbackReportSaveError,
        clearSaveFeedback: formFeedbackClearSaveFeedback,
    };

    const list = useRecordList<Reading>('/pembacaan-counter', '', {
        aset_id: filterAset,
        jenis_counter_id: filterCounter,
        refresh: String(reload),
    });
    const rows = list.items;
    const loading = list.loading;

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
        setReload((current) => current + 1);
    };

    // Jam tidak diisi dari jam perangkat (standar module: jam peramban bisa salah zona); petugas
    // mengetik jam yang tertera saat ia membaca meter.
    function openRecord() {
        formFeedback.clearSaveFeedback();

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
            formFeedback.clearSaveFeedback();
            toast.success('Pembacaan counter dicatat.');
            setRecording(false);
            refresh();
        } catch (caught) {
            if (caught instanceof ApiError) {
                setErrors(caught.validationErrors);
            }

            formFeedback.reportSaveError(
                caught,
                'Pembacaan counter belum dapat dicatat.',
            );
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
            pageFeedback.clearSaveFeedback();
            toast.success('Pembacaan diarsipkan.');
            refresh();
        } catch (caught) {
            pageFeedback.reportSaveError(
                caught,
                'Pembacaan belum dapat diarsipkan.',
            );
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
        ? [{ id: 'archive', label: 'Delete', destructive: true }]
        : [];

    useRecordCardDirty({
        value: draft,
        ready: recording && draft !== null,
        scopeKey: 'new-reading',
        onDirtyChange: setDraftDirty,
    });
    const {
        request: requestReadingTransition,
        confirmation: readingTransitionConfirmation,
    } = useRecordTransition(draftDirty, busy);
    const closeReading = () =>
        requestReadingTransition(() => setRecording(false));

    return (
        <div ref={pageFeedbackScopeRef}>
            {readingTransitionConfirmation}
            <RecordActionBar
                feedback={pageFeedbackSummary}
                title="Pembacaan counter"
            >
                {canCreate && (
                    <ActionButton
                        action="create"
                        type="button"
                        onClick={openRecord}
                    />
                )}
            </RecordActionBar>

            <div className="flex flex-col gap-3 border-b px-5 py-3 sm:flex-row sm:flex-wrap sm:items-end">
                <div className="w-full sm:w-80">
                    <FormControl>
                        <RecordLookup
                            source={assetSource}
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
                                setFilterAset(value ?? '');
                            }}
                        />
                    </FormControl>
                </div>
                <div className="w-full sm:w-64">
                    <FormControl>
                        <RecordLookup
                            source={counterTypes}
                            label="Counter"
                            items={[
                                { value: '', label: 'Semua counter' },
                                ...counterChoices,
                            ]}
                            value={filterCounter || null}
                            placeholder="Semua counter"
                            ariaLabel="Saring berdasarkan counter"
                            onValueChange={(value) => {
                                setFilterCounter(value ?? '');
                            }}
                        />
                    </FormControl>
                </div>
                {assetError && (
                    <p className="text-muted-foreground text-sm">
                        {assetError}
                    </p>
                )}
            </div>

            {!loading && rows.length === 0 && !list.error ? (
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
                    incremental={list}
                    sort={list.sort}
                    onSortChange={list.onSortChange}
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

            <PageDialog
                open={recording}
                onOpenChange={(open) => {
                    if (!open) {
                        closeReading();
                    }
                }}
            >
                <PageDialogContent
                    ref={dialogRef}
                    title={'Pembacaan counter'}
                    description={
                        'Ketik angka yang tertera di meter. Total pemakaian dihitung otomatis.'
                    }
                    busy={busy}
                >
                    <PageDialogToolbar>
                        <ActionButton
                            action="save"
                            type="button"
                            disabled={
                                busy ||
                                !draft?.aset_id ||
                                !draft?.jenis_counter_id ||
                                !draft?.jam ||
                                draft?.nilai === ''
                            }
                            onClick={() => void record()}
                            pending={busy}
                        />
                        <PageDialogClose asChild>
                            <ActionButton action="cancel" type="button" />
                        </PageDialogClose>
                    </PageDialogToolbar>
                    {formFeedbackSummary}
                    {draft && (
                        <PageDialogBody className="space-y-4">
                            <FormControl data-error-field="aset_id">
                                <RecordLookup
                                    source={assetSource}
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
                                    <FieldError>
                                        {message('aset_id')}
                                    </FieldError>
                                )}
                            </FormControl>
                            <FormControl
                                data-error-field="jenis_counter_id"
                                hint={
                                    chosenCounter?.terakhir
                                        ? `Terakhir: ${plain(chosenCounter.terakhir.nilai)} ${chosenCounter.satuan ?? ''} pada ${showReadAt(chosenCounter.terakhir.dibaca_pada)}.`
                                        : chosenCounter
                                          ? 'Belum pernah dibaca.'
                                          : undefined
                                }
                                error={
                                    message('jenis_counter_id') ||
                                    (draft.aset_id &&
                                    assetCounters?.asetId === draft.aset_id &&
                                    counters.length === 0
                                        ? 'Belum ada counter yang berlaku untuk jenis aset ini. Atur di Jenis counter atau pada Jenis aset.'
                                        : undefined)
                                }
                            >
                                <InlineLookup
                                    label="Counter"
                                    required
                                    items={counters}
                                    columns={[
                                        {
                                            id: 'kode',
                                            header: 'Kode',
                                            cell: (counter) => counter.kode,
                                            width: 120,
                                        },
                                        {
                                            id: 'nama',
                                            header: 'Nama',
                                            cell: (counter) => counter.nama,
                                        },
                                        {
                                            id: 'satuan',
                                            header: 'Satuan',
                                            cell: (counter) =>
                                                counter.satuan ?? '—',
                                            width: 100,
                                        },
                                    ]}
                                    value={
                                        counters.find(
                                            (counter) =>
                                                counter.jenis_counter_id ===
                                                draft.jenis_counter_id,
                                        ) ?? null
                                    }
                                    getItemValue={(counter) =>
                                        counter.jenis_counter_id
                                    }
                                    getItemLabel={(counter) =>
                                        counter.satuan
                                            ? `${counter.nama} (${counter.satuan})`
                                            : counter.nama
                                    }
                                    placeholder={
                                        draft.aset_id
                                            ? 'Pilih counter'
                                            : 'Pilih aset lebih dahulu'
                                    }
                                    portalContainer={dialogRef}
                                    onValueChange={(value) =>
                                        setDraft({
                                            ...draft,
                                            jenis_counter_id:
                                                value.jenis_counter_id,
                                        })
                                    }
                                />
                            </FormControl>
                            <FormGrid>
                                <FormControl data-error-field="tanggal">
                                    <DatePicker
                                        portalContainer={dialogRef}
                                        label="Tanggal baca"

                                        required
                                        value={draft.tanggal}
                                        onValueChange={(value) =>
                                            setDraft({
                                                ...draft,
                                                tanggal: value,
                                            })
                                        }
                                    />
                                </FormControl>
                                <FormControl data-error-field="jam">
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
                                </FormControl>
                            </FormGrid>
                            {message('dibaca_pada') && (
                                <p className="text-destructive text-sm">
                                    {message('dibaca_pada')}
                                </p>
                            )}
                            <FormControl data-error-field="nilai">
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
                                    <FieldError>{message('nilai')}</FieldError>
                                )}
                            </FormControl>
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
                            <FormControl data-error-field="keterangan">
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
                            </FormControl>
                        </PageDialogBody>
                    )}
                </PageDialogContent>
            </PageDialog>

            <Dialog
                open={archiving !== null}
                onOpenChange={(open) => !open && setArchiving(null)}
            >
                <DialogContent size="compact">
                    <DialogHeader>
                        <DialogTitle>Delete pembacaan ini?</DialogTitle>
                        <DialogDescription>
                            Hanya pembacaan terakhir sebuah counter yang dapat
                            diarsipkan, karena total pembacaan sesudahnya
                            dihitung dari pembacaan ini. Catat ulang angka yang
                            benar sesudahnya.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <ActionButton
                            action="archive"
                            type="button"
                            onClick={() => archiving && void archive(archiving)}
                        />
                        <DialogClose asChild>
                            <ActionButton action="cancel" type="button" />
                        </DialogClose>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
