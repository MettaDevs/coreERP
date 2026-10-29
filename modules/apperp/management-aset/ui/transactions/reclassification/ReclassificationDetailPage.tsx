import { Eye, Send, Trash2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { useWorkDate } from '@/hooks/use-work-date';
import { ActionButton } from '@apperp/ui/action-button';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@apperp/ui/alert-dialog';
import { Button } from '@apperp/ui/button';
import {
    CollapsibleSection,
    CollapsibleSectionGroup,
} from '@apperp/ui/collapsible-section';
import { DataTable } from '@apperp/ui/data-table';
import type { DataTableColumn } from '@apperp/ui/data-table';
import { DatePicker } from '@apperp/ui/date-picker';
import { FieldError } from '@apperp/ui/field';

import { FormControl, FormGrid } from '@apperp/ui/form-control';
import { Input } from '@apperp/ui/input';
import { usePageDialogRecord } from '@apperp/ui/page-dialog';
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { Select } from '@apperp/ui/select';

import { Textarea } from '@apperp/ui/textarea';
import { RecordLookup } from '../../_shared/RecordLookup';
import { ApiError, api, errorMessage, newIdempotencyKey } from '../../api';
import { useMasterOptions } from '../../master/useMasterOptions';
import { useRecordOptions } from '../../use-record-list';
import { useSaveFeedback } from '../../use-save-feedback';
import { PostingPreviewPanel } from '../_shared/PostingPreviewPanel';
import type { PostingPreview } from '../_shared/PostingPreviewPanel';
import { RecordCardHeader } from '../_shared/record-card';
import type { RecordCardControls } from '../_shared/record-card';
import { useRecordCardDirty } from '../_shared/use-record-card-dirty';
import type {
    Context,
    EditableReclassification,
    Reclassification,
    ReclassificationLine,
} from './reclassification';
import {
    KINDS,
    StatusBadge,
    bukaDaftar,
    bukaReklasifikasi,
    bukaReklasifikasiUbah,
    emptyLine,
    izin,
    kindLabel,
    postingLabel,
    tanggalTampil,
    uang,
} from './reclassification';

type AsetPilihan = {
    id: string;
    kode: string;
    nama: string | null;
    lifecycle_state: string;
    group_aset_id?: string | null;
};

/** Pemindahan satu baris di pratinjau: yang berpindah per buku. */
type Move = {
    line_number: number;
    aset_kode: string;
    journal: boolean;
    nilai_perolehan_aset: string;
    books: {
        book_code: string;
        nilai_perolehan: string;
        akumulasi_penyusutan: string;
        penurunan_nilai: string;
        kenaikan_nilai: string;
        nilai_buku: string;
    }[];
};

type Mode = 'create' | 'view' | 'edit';

/** Pilihan group tujuan pecah yang berarti tetap di group aset asal. */
const GROUP_ASAL = 'Tetap di group aset asal';

const labelAset = (aset: { kode: string; nama: string | null }) =>
    aset.nama ? `${aset.kode} · ${aset.nama}` : aset.kode;

/** Dari jawaban server ke bentuk form: nilai kosong dijadikan string kosong yang terkendali. */
const keForm = (data: Reclassification): EditableReclassification => ({
    jenis: data.jenis,
    tanggal: data.tanggal?.slice(0, 10) ?? '',
    keterangan: data.keterangan ?? '',
    responsible_org_unit_id: data.responsible_org_unit_id,
    details: (data.details ?? []).map((baris) => ({
        ...baris,
        group_aset_tujuan_id: baris.group_aset_tujuan_id ?? '',
        persen: baris.persen ? String(Number(baris.persen)) : '',
        nilai_perolehan: baris.nilai_perolehan ?? '',
        nama_aset_baru: baris.nama_aset_baru ?? '',
        keterangan: baris.keterangan ?? '',
    })),
});

/**
 * Rincian satu reklasifikasi aset, alur *FA Reclass. Journal* Business Central: draf disusun, *Pratinjau
 * posting* menampilkan yang berpindah per buku dan jurnalnya, lalu *Posting* memindah nilainya — aset pindah
 * group, atau bagian yang dipecah lahir sebagai aset baru — dan mengirim jurnalnya ke aplikasi finance bila
 * group berubah.
 */
export default function ReclassificationDetailPage({
    context,
    permissions,
    card,
    reclassificationId,
    mode,
}: {
    context: Context;
    permissions: string[];
    card?: RecordCardControls;
    reclassificationId?: string;
    mode: Mode;
}) {
    const [feedbackSections, setFeedbackSections] = useState<string[]>([
        'general',
        'lines',
    ]);

    const {
        scopeRef: feedbackScopeRef,
        feedbackSummary,
        reportSaveError,
        clearSaveFeedback,
    } = useSaveFeedback(undefined, reclassificationId, () =>
        setFeedbackSections(['general', 'lines']),
    );

    const embedded = usePageDialogRecord(undefined);

    const can = izin(permissions);
    const { date: workDate } = useWorkDate();
    const [record, setRecord] = useState<EditableReclassification>(() => ({
        jenis: 'pindah_group',
        tanggal: workDate,
        keterangan: '',
        responsible_org_unit_id: context.org_unit_id ?? '',
        details: [emptyLine()],
    }));
    const [tersimpan, setTersimpan] = useState<Reclassification | null>(null);
    const [memuat, setMemuat] = useState(mode !== 'create');
    const [menyimpan, setMenyimpan] = useState(false);
    const [galat, setGalat] = useState<Record<string, string[]>>({});
    const [pratinjau, setPratinjau] = useState<
        (PostingPreview & { moves: Move[] }) | null
    >(null);
    const [konfirmasi, setKonfirmasi] = useState<'posting' | 'arsip' | null>(
        null,
    );
    const grup = useMasterOptions('group-aset');
    const unitKerja = useMasterOptions(
        'reference-data/unit-kerja',
        '',
        record.responsible_org_unit_id,
    );
    const panelRef = useRef<HTMLDivElement>(null);
    const lookupPortal = card?.portalContainer ?? panelRef;
    const onBusyChange = card?.onBusyChange;
    useEffect(() => {
        onBusyChange?.(menyimpan);

        return () => onBusyChange?.(false);
    }, [menyimpan, onBusyChange]);
    const viewRecord = (id: string) => {
        if (!menyimpan) {
            (card?.onRequestView ?? bukaReklasifikasi)(id);
        }
    };
    const editRecord = (id: string) => {
        if (!menyimpan) {
            (card?.onRequestEdit ?? bukaReklasifikasiUbah)(id);
        }
    };
    const closeRecord = () => {
        if (!menyimpan) {
            (card?.onClose ?? bukaDaftar)();
        }
    };
    const saveCompleted = (id: string) =>
        (card?.onSaved ?? bukaReklasifikasi)(id);
    const deleteCompleted = () => {
        if (card?.onClose) {
            card.onSaved?.(String(reclassificationId));
            card.onClose();
        } else {
            bukaDaftar();
        }
    };

    const readOnly = mode === 'view';
    const draf = mode === 'create' || tersimpan?.status === 'draft';
    const pecah = record.jenis === 'pecah';

    // Daftar aset hanya dibutuhkan untuk menambah baris; aset yang sudah dilepas tidak ditawarkan.

    const assetSource = useRecordOptions<AsetPilihan>(
        '/aset?exclude_state=disposed',
        (item) => (item.nama ? `${item.kode} \u00b7 ${item.nama}` : item.kode),
        record.details.map((row) => row.aset_id),
    );
    const aset = assetSource.options;

    useEffect(() => {
        if (!reclassificationId) {
            return;
        }

        const request = new AbortController();
        let dibatalkan = false;
        api<{ data: Reclassification }>(
            `/reklasifikasi-aset/${reclassificationId}`,
            { signal: request.signal },
        )
            .then((result) => {
                if (!dibatalkan) {
                    setTersimpan(result.data);
                    setRecord(keForm(result.data));
                }
            })
            .catch((caught) => {
                if (!request.signal.aborted) {
                    toast.error(
                        errorMessage(
                            caught,
                            'Reklasifikasi belum dapat dimuat.',
                        ),
                    );
                }
            })
            .finally(() => {
                if (!dibatalkan) {
                    setMemuat(false);
                }
            });

        return () => {
            dibatalkan = true;
            request.abort();
        };
    }, [reclassificationId]);

    const pesan = (field: string) => galat[field]?.[0];

    function ubahBaris(index: number, patch: Partial<ReclassificationLine>) {
        setRecord((sebelumnya) => ({
            ...sebelumnya,
            details: sebelumnya.details.map((baris, posisi) =>
                posisi === index ? { ...baris, ...patch } : baris,
            ),
        }));
    }

    async function simpan() {
        const legalEntity =
            tersimpan?.legal_entity_id ?? context.legal_entity_id;

        if (!legalEntity) {
            reportSaveError(
                null,
                'Pilih perusahaan aktif terlebih dahulu pada header CoreERP.',
            );

            return;
        }

        setMenyimpan(true);
        setGalat({});
        const payload = {
            legal_entity_id: legalEntity,
            responsible_org_unit_id: record.responsible_org_unit_id || null,
            jenis: record.jenis,
            tanggal: record.tanggal,
            keterangan: record.keterangan,
            details: record.details
                .filter((baris) => baris.aset_id)
                .map((baris) => ({
                    id: baris.id ?? null,
                    aset_id: baris.aset_id,
                    group_aset_tujuan_id:
                        baris.group_aset_tujuan_id || (pecah ? '' : null),
                    persen: pecah && baris.persen !== '' ? baris.persen : null,
                    nilai_perolehan:
                        pecah && baris.nilai_perolehan !== ''
                            ? baris.nilai_perolehan
                            : null,
                    nama_aset_baru: pecah ? baris.nama_aset_baru || null : null,
                    keterangan: baris.keterangan || null,
                })),
        };

        try {
            if (mode === 'create') {
                const hasil = await api<{ data: Reclassification }>(
                    '/reklasifikasi-aset',
                    {
                        method: 'POST',
                        headers: { 'Idempotency-Key': newIdempotencyKey() },
                        body: JSON.stringify(payload),
                    },
                );
                clearSaveFeedback();
                toast.success(
                    `Reklasifikasi ${hasil.data.kode} disimpan sebagai draf.`,
                );
                saveCompleted(hasil.data.id);

                return;
            }

            await api(`/reklasifikasi-aset/${reclassificationId}`, {
                method: 'PATCH',
                body: JSON.stringify({
                    ...payload,
                    version: tersimpan?.version,
                }),
            });
            clearSaveFeedback();
            toast.success('Perubahan reklasifikasi disimpan.');
            saveCompleted(String(reclassificationId));
        } catch (caught) {
            if (caught instanceof ApiError) {
                setGalat(caught.validationErrors);
            }

            reportSaveError(caught, 'Reklasifikasi belum dapat disimpan.');
        } finally {
            setMenyimpan(false);
        }
    }

    async function muatPratinjau() {
        setMenyimpan(true);

        try {
            const hasil = await api<{
                data: PostingPreview & { moves: Move[] };
            }>(`/reklasifikasi-aset/${reclassificationId}/pratinjau-posting`);
            setPratinjau(hasil.data);
        } catch (caught) {
            toast.error(
                errorMessage(caught, 'Pratinjau posting belum dapat disusun.'),
            );
        } finally {
            setMenyimpan(false);
        }
    }

    async function posting() {
        setKonfirmasi(null);
        setMenyimpan(true);
        setGalat({});

        try {
            const hasil = await api<{ data: Reclassification }>(
                `/reklasifikasi-aset/${reclassificationId}/posting`,
                {
                    method: 'POST',
                    body: JSON.stringify({ version: tersimpan?.version }),
                },
            );
            setTersimpan(hasil.data);
            setRecord(keForm(hasil.data));
            setPratinjau(null);
            clearSaveFeedback();
            toast.success(
                `Nilai aset dipindahkan. Jurnal: ${postingLabel(hasil.data.posting?.status)}.`,
            );
            card?.onSaved?.(String(reclassificationId));
        } catch (caught) {
            if (caught instanceof ApiError) {
                setGalat(caught.validationErrors);
            }

            reportSaveError(caught, 'Reklasifikasi belum dapat diposting.');
        } finally {
            setMenyimpan(false);
        }
    }

    async function arsipkan() {
        setKonfirmasi(null);

        setMenyimpan(true);

        try {
            await api(`/reklasifikasi-aset/${reclassificationId}`, {
                method: 'DELETE',
                body: JSON.stringify({ version: tersimpan?.version }),
            });
            clearSaveFeedback();
            toast.success('Draf reklasifikasi diarsipkan.');
            deleteCompleted();
        } catch (caught) {
            reportSaveError(caught, 'Reklasifikasi belum dapat diarsipkan.');
        } finally {
            setMenyimpan(false);
        }
    }

    useRecordCardDirty({
        value: record,
        ready: !memuat,
        scopeKey: JSON.stringify([
            reclassificationId,
            mode,
            tersimpan?.version,
        ]),
        onDirtyChange: card?.onDirtyChange,
    });

    if (memuat) {
        return (
            <div className="text-muted-foreground p-5 text-sm">
                Memuat reklasifikasi…
            </div>
        );
    }

    const judul =
        mode === 'create'
            ? 'Reklasifikasi aset baru'
            : (tersimpan?.kode ?? 'Reklasifikasi aset');

    const masalahBaris = (index: number) =>
        pesan(`details.${index}.aset_id`) ??
        pesan(`details.${index}.group_aset_tujuan_id`) ??
        pesan(`details.${index}.persen`) ??
        pesan(`details.${index}.nilai_perolehan`);

    return (
        <div
            ref={feedbackScopeRef}
            className="flex h-full min-h-0 flex-col overflow-hidden"
        >
            <RecordCardHeader
                recordTitle={tersimpan?.kode ?? 'Baru'}
                editing={!readOnly}
                recordUrl={
                    reclassificationId
                        ? `/management-aset/reklasifikasi-aset/${reclassificationId}`
                        : undefined
                }
                onEdit={
                    readOnly && draf && can('update')
                        ? () =>
                              reclassificationId &&
                              editRecord(reclassificationId)
                        : undefined
                }
                onDelete={
                    readOnly && draf && can('archive')
                        ? () => setKonfirmasi('arsip')
                        : undefined
                }
            />
            <RecordActionBar
                feedback={feedbackSummary}
                title={embedded ? undefined : judul}
                trailing={
                    tersimpan ? (
                        <StatusBadge status={tersimpan.status} />
                    ) : undefined
                }
            >
                {readOnly && draf && can('update') && !embedded && (
                    <ActionButton
                        action="edit"
                        type="button"
                        onClick={() =>
                            reclassificationId && editRecord(reclassificationId)
                        }
                    />
                )}
                {readOnly && draf && (can('post') || can('update')) && (
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => void muatPratinjau()}
                        disabled={menyimpan}
                    >
                        <Eye />
                        Pratinjau posting
                    </Button>
                )}
                {readOnly && draf && can('post') && (
                    <Button
                        type="button"
                        onClick={() => setKonfirmasi('posting')}
                        disabled={menyimpan}
                    >
                        <Send />
                        Posting
                    </Button>
                )}
                {readOnly && draf && can('archive') && !embedded && (
                    <ActionButton
                        action="archive"
                        type="button"
                        onClick={() => setKonfirmasi('arsip')}
                    />
                )}
                {!readOnly && (
                    <>
                        <ActionButton
                            action="save"
                            type="button"
                            onClick={() => void simpan()}
                            disabled={menyimpan}
                            pending={menyimpan}
                        />
                        <ActionButton
                            action="cancel"
                            type="button"
                            onClick={() =>
                                reclassificationId
                                    ? viewRecord(reclassificationId)
                                    : closeRecord()
                            }
                        />
                    </>
                )}
            </RecordActionBar>

            <div
                data-page-dialog-body
                ref={panelRef}
                className="min-h-0 flex-1 overflow-y-auto"
            >
                <div className="space-y-5 p-5">
                    <CollapsibleSectionGroup
                        value={feedbackSections}
                        onValueChange={setFeedbackSections}
                    >
                        <CollapsibleSection
                            value="general"
                            title="Umum"
                            summary={`${kindLabel(record.jenis)} · ${tanggalTampil(record.tanggal)}`}
                        >
                            <div className="space-y-4">
                                <FormGrid>
                                    <FormControl
                                        data-error-field="jenis"
                                        error={pesan('jenis')}
                                        hint={
                                            pecah
                                                ? 'Sebagian nilai aset dipindah ke aset baru yang lahir saat diposting.'
                                                : 'Aset yang sama pindah seluruhnya ke group aset lain.'
                                        }
                                    >
                                        {readOnly || mode === 'edit' ? (
                                            <Input
                                                label="Jenis reklasifikasi"
                                                readOnly
                                                value={kindLabel(record.jenis)}
                                            />
                                        ) : (
                                            <Select
                                                label="Jenis reklasifikasi"
                                                required
                                                items={KINDS.map(
                                                    (kind) => kind.label,
                                                )}
                                                value={kindLabel(record.jenis)}
                                                ariaLabel="Jenis reklasifikasi"
                                                portalContainer={lookupPortal}
                                                onValueChange={(value) =>
                                                    setRecord({
                                                        ...record,
                                                        jenis:
                                                            KINDS.find(
                                                                (kind) =>
                                                                    kind.label ===
                                                                    value,
                                                            )?.value ??
                                                            'pindah_group',
                                                        details: [emptyLine()],
                                                    })
                                                }
                                            />
                                        )}
                                    </FormControl>
                                    <FormControl
                                        data-error-field="tanggal"
                                        error={pesan('tanggal')}
                                        hint={
                                            'Tanggal jurnal. Penyusutan sampai tanggal ini harus sudah difinalkan.'
                                        }
                                    >
                                        <DatePicker
                                            label="Tanggal reklasifikasi"

                                            required
                                            readOnly={readOnly}
                                            value={record.tanggal}
                                            onValueChange={(value) =>
                                                setRecord({
                                                    ...record,
                                                    tanggal: value,
                                                })
                                            }
                                        />
                                    </FormControl>
                                    <FormControl data-error-field="responsible_org_unit_id">
                                        {readOnly ? (
                                            <Input
                                                label="Unit penanggung jawab"
                                                readOnly
                                                value={
                                                    tersimpan?.responsible_org_unit_nama ??
                                                    '—'
                                                }
                                            />
                                        ) : (
                                            <RecordLookup
                                                source={unitKerja}
                                                label="Unit penanggung jawab"
                                                required
                                                items={unitKerja.options.map(
                                                    (record) => ({
                                                        value: record.id,
                                                        label: unitKerja.getLabel(
                                                            record,
                                                        ),
                                                    }),
                                                )}
                                                value={
                                                    record.responsible_org_unit_id ||
                                                    null
                                                }
                                                placeholder="Pilih unit kerja"
                                                searchPlaceholder="Cari unit kerja"
                                                emptyMessage="Unit kerja tidak ditemukan."
                                                ariaLabel="Unit penanggung jawab"
                                                portalContainer={lookupPortal}
                                                onValueChange={(item) =>
                                                    setRecord({
                                                        ...record,
                                                        responsible_org_unit_id:
                                                            item ?? '',
                                                    })
                                                }
                                            />
                                        )}
                                        {pesan('responsible_org_unit_id') && (
                                            <FieldError>
                                                {pesan(
                                                    'responsible_org_unit_id',
                                                )}
                                            </FieldError>
                                        )}
                                    </FormControl>
                                </FormGrid>
                                <FormControl
                                    data-error-field="keterangan"
                                    error={pesan('keterangan')}
                                    hint={
                                        'Ikut ke keterangan jurnal di aplikasi finance, misalnya salah klasifikasi saat penerimaan.'
                                    }
                                >
                                    <Textarea
                                        label="Alasan"
                                        required
                                        rows={2}
                                        maxLength={250}
                                        readOnly={readOnly}
                                        value={record.keterangan}
                                        onChange={(event) =>
                                            setRecord({
                                                ...record,
                                                keterangan: event.target.value,
                                            })
                                        }
                                    />
                                </FormControl>
                            </div>
                        </CollapsibleSection>
                        <CollapsibleSection
                            value="lines"
                            title={
                                pecah ? 'Aset yang dipecah' : 'Aset yang pindah'
                            }
                            summary={`${record.details.length} baris`}
                        >
                            <div className="space-y-4">
                                <div className="space-y-2">
                                    <div className="flex flex-wrap items-center justify-end gap-2">
                                        {!readOnly && (
                                            <ActionButton
                                                action="add"
                                                type="button"
                                                onClick={() =>
                                                    setRecord({
                                                        ...record,
                                                        details: [
                                                            ...record.details,
                                                            emptyLine(),
                                                        ],
                                                    })
                                                }
                                            />
                                        )}
                                    </div>
                                    {pesan('details') && (
                                        <p className="text-destructive text-sm">
                                            {pesan('details')}
                                        </p>
                                    )}
                                    {(() => {
                                        const sdkRows = record.details.map(
                                            (baris, index) => {
                                                const dipilih = aset.find(
                                                    (item) =>
                                                        item.id ===
                                                        baris.aset_id,
                                                );
                                                const masalah =
                                                    masalahBaris(index);

                                                return {
                                                    baris,
                                                    index,
                                                    dipilih,
                                                    masalah,
                                                };
                                            },
                                        );
                                        const sdkColumns: DataTableColumn<
                                            (typeof sdkRows)[number]
                                        >[] = [
                                            {
                                                id: 'column-0',
                                                header: 'No',
                                                width: 48,
                                                cell: ({ baris, index }) => (
                                                    <>
                                                        {baris.line_number ??
                                                            index + 1}
                                                    </>
                                                ),
                                            } satisfies DataTableColumn<
                                                (typeof sdkRows)[number]
                                            >,
                                            {
                                                id: 'column-1',
                                                header: 'Aset',
                                                content: 'control',
                                                cell: ({
                                                    baris,
                                                    index,
                                                    dipilih,
                                                    masalah,
                                                }) => (
                                                    <>
                                                        {!baris.id &&
                                                        !readOnly ? (
                                                            <FormControl>
                                                                <RecordLookup
                                                                    label="Pilih aset"
                                                                    source={
                                                                        assetSource
                                                                    }
                                                                    items={aset.map(
                                                                        (
                                                                            record,
                                                                        ) => ({
                                                                            value: record.id,
                                                                            label: assetSource.getLabel(
                                                                                record,
                                                                            ),
                                                                        }),
                                                                    )}
                                                                    value={
                                                                        dipilih?.id ??
                                                                        null
                                                                    }
                                                                    placeholder="Pilih aset"
                                                                    searchPlaceholder="Cari kode atau nama aset"
                                                                    emptyMessage="Aset tidak ditemukan."
                                                                    ariaLabel={`Aset baris ${index + 1}`}
                                                                    portalContainer={
                                                                        lookupPortal
                                                                    }
                                                                    onValueChange={(
                                                                        item,
                                                                    ) =>
                                                                        ubahBaris(
                                                                            index,
                                                                            {
                                                                                aset_id:
                                                                                    item ??
                                                                                    '',
                                                                            },
                                                                        )
                                                                    }
                                                                />
                                                            </FormControl>
                                                        ) : (
                                                            <span>
                                                                {labelAset({
                                                                    kode:
                                                                        baris.aset_kode ??
                                                                        dipilih?.kode ??
                                                                        '—',
                                                                    nama:
                                                                        baris.aset_nama ??
                                                                        dipilih?.nama ??
                                                                        null,
                                                                })}
                                                            </span>
                                                        )}
                                                        {baris.group_aset_asal_nama && (
                                                            <span className="text-muted-foreground block text-xs">
                                                                Dari group{' '}
                                                                {
                                                                    baris.group_aset_asal_nama
                                                                }
                                                                {baris.nilai_perolehan_aset &&
                                                                    ` · harga perolehan ${uang(baris.nilai_perolehan_aset)}`}
                                                            </span>
                                                        )}
                                                        {masalah && (
                                                            <span className="text-destructive block text-xs">
                                                                {masalah}
                                                            </span>
                                                        )}
                                                    </>
                                                ),
                                            } satisfies DataTableColumn<
                                                (typeof sdkRows)[number]
                                            >,
                                            {
                                                id: 'column-2',
                                                header: 'Group tujuan',
                                                content: 'control',
                                                cell: ({ baris, index }) => (
                                                    <>
                                                        {readOnly ? (
                                                            (baris.group_aset_tujuan_nama ??
                                                            (pecah
                                                                ? GROUP_ASAL
                                                                : '—'))
                                                        ) : (
                                                            <FormControl>
                                                                <RecordLookup
                                                                    label="Pilih group tujuan"
                                                                    source={
                                                                        grup
                                                                    }
                                                                    items={[
                                                                        ...(pecah
                                                                            ? [
                                                                                  {
                                                                                      value: '',
                                                                                      label: GROUP_ASAL,
                                                                                  },
                                                                              ]
                                                                            : []),
                                                                        ...grup.options.map(
                                                                            (
                                                                                record,
                                                                            ) => ({
                                                                                value: record.id,
                                                                                label: grup.getLabel(
                                                                                    record,
                                                                                ),
                                                                            }),
                                                                        ),
                                                                    ]}
                                                                    value={
                                                                        baris.group_aset_tujuan_id ||
                                                                        (pecah
                                                                            ? ''
                                                                            : null)
                                                                    }
                                                                    placeholder="Pilih group tujuan"
                                                                    searchPlaceholder="Cari group aset"
                                                                    emptyMessage="Group aset tidak ditemukan."
                                                                    ariaLabel={`Group tujuan baris ${index + 1}`}
                                                                    portalContainer={
                                                                        lookupPortal
                                                                    }
                                                                    onValueChange={(
                                                                        item,
                                                                    ) =>
                                                                        ubahBaris(
                                                                            index,
                                                                            {
                                                                                group_aset_tujuan_id:
                                                                                    item ??
                                                                                    '',
                                                                            },
                                                                        )
                                                                    }
                                                                />
                                                            </FormControl>
                                                        )}
                                                    </>
                                                ),
                                            } satisfies DataTableColumn<
                                                (typeof sdkRows)[number]
                                            >,
                                            ...(pecah
                                                ? [
                                                      {
                                                          id: 'column-3',
                                                          header: 'Persen',
                                                          align: 'right',
                                                          content: 'control',
                                                          cell: ({
                                                              baris,
                                                              index,
                                                          }) => (
                                                              <div className="text-right">
                                                                  {readOnly ? (
                                                                      baris.persen ? (
                                                                          `${baris.persen}%`
                                                                      ) : (
                                                                          '—'
                                                                      )
                                                                  ) : (
                                                                      <FormControl>
                                                                          <Input
                                                                              aria-label={`Persen baris ${index + 1}`}
                                                                              type="number"
                                                                              min="0"
                                                                              max="100"
                                                                              step="0.01"
                                                                              value={
                                                                                  baris.persen
                                                                              }
                                                                              disabled={
                                                                                  baris.nilai_perolehan !==
                                                                                  ''
                                                                              }
                                                                              onChange={(
                                                                                  event,
                                                                              ) =>
                                                                                  ubahBaris(
                                                                                      index,
                                                                                      {
                                                                                          persen: event
                                                                                              .target
                                                                                              .value,
                                                                                      },
                                                                                  )
                                                                              }
                                                                          />
                                                                      </FormControl>
                                                                  )}
                                                              </div>
                                                          ),
                                                      } satisfies DataTableColumn<
                                                          (typeof sdkRows)[number]
                                                      >,
                                                  ]
                                                : []),
                                            ...(pecah
                                                ? [
                                                      {
                                                          id: 'column-4',
                                                          header: 'Atau nilai perolehan',
                                                          align: 'right',
                                                          content: 'control',
                                                          cell: ({
                                                              baris,
                                                              index,
                                                          }) => (
                                                              <div className="text-right">
                                                                  {readOnly ? (
                                                                      uang(
                                                                          baris.nilai_perolehan_dipindah ??
                                                                              baris.nilai_perolehan,
                                                                      )
                                                                  ) : (
                                                                      <FormControl>
                                                                          <Input
                                                                              aria-label={`Nilai perolehan baris ${index + 1}`}
                                                                              type="number"
                                                                              min="0"
                                                                              step="0.01"
                                                                              value={
                                                                                  baris.nilai_perolehan
                                                                              }
                                                                              disabled={
                                                                                  baris.persen !==
                                                                                  ''
                                                                              }
                                                                              onChange={(
                                                                                  event,
                                                                              ) =>
                                                                                  ubahBaris(
                                                                                      index,
                                                                                      {
                                                                                          nilai_perolehan:
                                                                                              event
                                                                                                  .target
                                                                                                  .value,
                                                                                      },
                                                                                  )
                                                                              }
                                                                          />
                                                                      </FormControl>
                                                                  )}
                                                              </div>
                                                          ),
                                                      } satisfies DataTableColumn<
                                                          (typeof sdkRows)[number]
                                                      >,
                                                  ]
                                                : []),
                                            ...(pecah
                                                ? [
                                                      {
                                                          id: 'column-5',
                                                          header: 'Aset baru',
                                                          content: 'control',
                                                          cell: ({
                                                              baris,
                                                              index,
                                                          }) => (
                                                              <>
                                                                  {readOnly ? (
                                                                      (baris.aset_baru_kode ??
                                                                      (baris.nama_aset_baru ||
                                                                          '—'))
                                                                  ) : (
                                                                      <FormControl>
                                                                          <Input
                                                                              aria-label={`Nama aset baru baris ${index + 1}`}
                                                                              placeholder="Sama dengan aset asal"
                                                                              value={
                                                                                  baris.nama_aset_baru
                                                                              }
                                                                              onChange={(
                                                                                  event,
                                                                              ) =>
                                                                                  ubahBaris(
                                                                                      index,
                                                                                      {
                                                                                          nama_aset_baru:
                                                                                              event
                                                                                                  .target
                                                                                                  .value,
                                                                                      },
                                                                                  )
                                                                              }
                                                                          />
                                                                      </FormControl>
                                                                  )}
                                                              </>
                                                          ),
                                                      } satisfies DataTableColumn<
                                                          (typeof sdkRows)[number]
                                                      >,
                                                  ]
                                                : []),
                                            {
                                                id: 'column-6',
                                                header: 'Keterangan',
                                                content: 'control',
                                                cell: ({ baris, index }) => (
                                                    <>
                                                        {readOnly ? (
                                                            baris.keterangan ||
                                                            '—'
                                                        ) : (
                                                            <FormControl>
                                                                <Input
                                                                    aria-label={`Keterangan baris ${index + 1}`}
                                                                    value={
                                                                        baris.keterangan
                                                                    }
                                                                    onChange={(
                                                                        event,
                                                                    ) =>
                                                                        ubahBaris(
                                                                            index,
                                                                            {
                                                                                keterangan:
                                                                                    event
                                                                                        .target
                                                                                        .value,
                                                                            },
                                                                        )
                                                                    }
                                                                />
                                                            </FormControl>
                                                        )}
                                                    </>
                                                ),
                                            } satisfies DataTableColumn<
                                                (typeof sdkRows)[number]
                                            >,
                                            ...(!readOnly
                                                ? [
                                                      {
                                                          id: 'column-7',
                                                          header: '',
                                                          width: 48,
                                                          cell: ({ index }) => (
                                                              <>
                                                                  <Button
                                                                      type="button"
                                                                      variant="ghost"
                                                                      size="icon"
                                                                      aria-label={`Keluarkan baris ${index + 1}`}
                                                                      onClick={() =>
                                                                          setRecord(
                                                                              {
                                                                                  ...record,
                                                                                  details:
                                                                                      record.details.filter(
                                                                                          (
                                                                                              _,
                                                                                              posisi,
                                                                                          ) =>
                                                                                              posisi !==
                                                                                              index,
                                                                                      ),
                                                                              },
                                                                          )
                                                                      }
                                                                  >
                                                                      <Trash2 />
                                                                  </Button>
                                                              </>
                                                          ),
                                                      } satisfies DataTableColumn<
                                                          (typeof sdkRows)[number]
                                                      >,
                                                  ]
                                                : []),
                                        ];

                                        return (
                                            <DataTable
                                                scrollMode="inline"
                                                showRowNumbers={false}
                                                serverSort
                                                data={sdkRows}
                                                columns={sdkColumns}
                                                getRowKey={({ baris, index }) =>
                                                    baris.id ?? `baru-${index}`
                                                }
                                            />
                                        );
                                    })()}
                                    {!readOnly && pecah && (
                                        <p className="text-muted-foreground text-sm">
                                            Isi persen atau nilai perolehan,
                                            salah satu. Akumulasi penyusutan,
                                            penurunan nilai, dan kenaikan nilai
                                            setiap buku ikut dipindah dengan
                                            perbandingan yang sama, beserta umur
                                            yang sudah berjalan.
                                        </p>
                                    )}
                                </div>
                            </div>
                        </CollapsibleSection>
                    </CollapsibleSectionGroup>
                    {tersimpan?.status === 'posted' && (
                        <p className="text-sm">
                            Diposting pada{' '}
                            {tanggalTampil(tersimpan.diposting_pada)}. Jurnal:{' '}
                            {postingLabel(tersimpan.posting?.status)}.
                        </p>
                    )}
                    {pratinjau && (
                        <div className="space-y-3 rounded-md border px-4 py-3">
                            <p className="text-sm font-medium">
                                Pratinjau posting
                            </p>
                            {pratinjau.moves.length > 0 &&
                                (() => {
                                    const sdkRows = pratinjau.moves.flatMap(
                                        (pindah) =>
                                            pindah.books.map(
                                                (buku, rowIndex) => {
                                                    return {
                                                        pindah,
                                                        buku,
                                                        rowIndex,
                                                    };
                                                },
                                            ),
                                    );
                                    const sdkColumns: DataTableColumn<
                                        (typeof sdkRows)[number]
                                    >[] = [
                                        {
                                            id: 'column-0',
                                            header: 'Aset',
                                            cell: ({ pindah }) => (
                                                <>{pindah.aset_kode}</>
                                            ),
                                        } satisfies DataTableColumn<
                                            (typeof sdkRows)[number]
                                        >,
                                        {
                                            id: 'column-1',
                                            header: 'Buku',
                                            cell: ({ buku }) => (
                                                <>{buku.book_code}</>
                                            ),
                                        } satisfies DataTableColumn<
                                            (typeof sdkRows)[number]
                                        >,
                                        {
                                            id: 'column-2',
                                            header: 'Harga perolehan',
                                            align: 'right',
                                            cell: ({ buku }) => (
                                                <div className="text-right">
                                                    {uang(buku.nilai_perolehan)}
                                                </div>
                                            ),
                                        } satisfies DataTableColumn<
                                            (typeof sdkRows)[number]
                                        >,
                                        {
                                            id: 'column-3',
                                            header: 'Akumulasi penyusutan',
                                            align: 'right',
                                            cell: ({ buku }) => (
                                                <div className="text-right">
                                                    {uang(
                                                        buku.akumulasi_penyusutan,
                                                    )}
                                                </div>
                                            ),
                                        } satisfies DataTableColumn<
                                            (typeof sdkRows)[number]
                                        >,
                                        {
                                            id: 'column-4',
                                            header: 'Penurunan nilai',
                                            align: 'right',
                                            cell: ({ buku }) => (
                                                <div className="text-right">
                                                    {uang(buku.penurunan_nilai)}
                                                </div>
                                            ),
                                        } satisfies DataTableColumn<
                                            (typeof sdkRows)[number]
                                        >,
                                        {
                                            id: 'column-5',
                                            header: 'Kenaikan nilai',
                                            align: 'right',
                                            cell: ({ buku }) => (
                                                <div className="text-right">
                                                    {uang(buku.kenaikan_nilai)}
                                                </div>
                                            ),
                                        } satisfies DataTableColumn<
                                            (typeof sdkRows)[number]
                                        >,
                                        {
                                            id: 'column-6',
                                            header: 'Nilai buku dipindah',
                                            align: 'right',
                                            cell: ({ buku }) => (
                                                <div className="text-right">
                                                    {uang(buku.nilai_buku)}
                                                </div>
                                            ),
                                        } satisfies DataTableColumn<
                                            (typeof sdkRows)[number]
                                        >,
                                    ];

                                    return (
                                        <DataTable
                                            scrollMode="inline"
                                            showRowNumbers={false}
                                            serverSort
                                            data={sdkRows}
                                            columns={sdkColumns}
                                            getRowKey={({ pindah, buku }) =>
                                                `${pindah.line_number}-${buku.book_code}`
                                            }
                                        />
                                    );
                                })()}
                            <PostingPreviewPanel
                                preview={pratinjau}
                                currencyCode="IDR"
                            />
                        </div>
                    )}
                </div>
            </div>

            <AlertDialog
                open={konfirmasi !== null}
                onOpenChange={(open) => !open && setKonfirmasi(null)}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            {konfirmasi === 'arsip'
                                ? 'Delete draf ini?'
                                : 'Posting reklasifikasi ini?'}
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {konfirmasi === 'arsip'
                                ? 'Draf disembunyikan dari daftar dan nomornya tidak dipakai ulang. Aset tidak terpengaruh.'
                                : pecah
                                  ? `Bagian yang dipecah dari ${record.details.length} baris lahir sebagai aset baru per ${tanggalTampil(record.tanggal)}, dan jurnalnya dikirim ke aplikasi finance bila group-nya berbeda. Setelah diposting, reklasifikasi tidak dapat diubah.`
                                  : `${record.details.length} aset pindah group per ${tanggalTampil(record.tanggal)}, dan jurnal pemindahan saldonya dikirim ke aplikasi finance. Setelah diposting, reklasifikasi tidak dapat diubah.`}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Batal</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={() =>
                                konfirmasi === 'arsip'
                                    ? void arsipkan()
                                    : void posting()
                            }
                        >
                            {konfirmasi === 'arsip' && (
                                <Trash2 aria-hidden="true" />
                            )}
                            {konfirmasi === 'arsip' ? 'Delete' : 'Posting'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
}
