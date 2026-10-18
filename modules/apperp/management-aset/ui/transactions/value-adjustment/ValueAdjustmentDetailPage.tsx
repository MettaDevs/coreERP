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
import { Field, FieldDescription } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { usePageDialogRecord } from '@apperp/ui/page-dialog';
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { Select } from '@apperp/ui/select';

import { Textarea } from '@apperp/ui/textarea';
import {
    ApiError,
    api,
    errorMessage,
    newIdempotencyKey,
    toastSaveError,
} from '../../api';
import { optionLabel, useMasterOptions } from '../../master/useMasterOptions';
import { useRecordOptions } from '../../use-record-list';
import { CancellationAction } from '../_shared/CancellationAction';
import { CancellationStatus } from '../_shared/CancellationStatus';
import { PostingPreviewPanel } from '../_shared/PostingPreviewPanel';
import type { PostingPreview } from '../_shared/PostingPreviewPanel';
import { RecordCardHeader } from '../_shared/record-card';
import type { RecordCardControls } from '../_shared/record-card';
import { useRecordCardDirty } from '../_shared/use-record-card-dirty';
import type {
    Adjustment,
    AdjustmentLine,
    Context,
    EditableAdjustment,
} from './valueAdjustment';
import {
    KINDS,
    StatusBadge,
    bukaDaftar,
    bukaPenyesuaian,
    bukaPenyesuaianUbah,
    emptyLine,
    izin,
    kindLabel,
    postingLabel,
    tanggalTampil,
    uang,
} from './valueAdjustment';

type AsetPilihan = {
    id: string;
    kode: string;
    nama: string | null;
    lifecycle_state: string;
};

type Mode = 'create' | 'view' | 'edit';

const labelAset = (aset: { kode: string; nama: string | null }) =>
    aset.nama ? `${aset.kode} · ${aset.nama}` : aset.kode;

/** Dari jawaban server ke bentuk form: nilai kosong dijadikan string kosong yang terkendali. */
const keForm = (data: Adjustment): EditableAdjustment => ({
    jenis: data.jenis,
    buku_id: data.buku_id,
    tanggal: data.tanggal?.slice(0, 10) ?? '',
    keterangan: data.keterangan ?? '',
    responsible_org_unit_id: data.responsible_org_unit_id,
    details: (data.details ?? []).map((baris) => ({
        ...baris,
        nilai: baris.nilai ?? '',
        keterangan: baris.keterangan ?? '',
    })),
});

/**
 * Rincian satu penyesuaian nilai aset, alur jurnal aset tetap Business Central: draf disusun, *Pratinjau
 * posting* menampilkan jurnalnya, lalu *Posting* mengubah nilai buku aset dan mengirim jurnalnya ke
 * aplikasi finance. Penyusutan berikutnya dihitung dari nilai buku yang baru.
 */
export default function ValueAdjustmentDetailPage({
    context,
    permissions,
    card,
    adjustmentId,
    mode,
}: {
    context: Context;
    permissions: string[];
    card?: RecordCardControls;
    adjustmentId?: string;
    mode: Mode;
}) {
    const embedded = usePageDialogRecord(undefined);

    const can = izin(permissions);
    const { date: workDate } = useWorkDate();
    const [record, setRecord] = useState<EditableAdjustment>(() => ({
        jenis: 'write_down',
        buku_id: '',
        tanggal: workDate,
        keterangan: '',
        responsible_org_unit_id: context.org_unit_id ?? '',
        details: [emptyLine()],
    }));
    const [reload, setReload] = useState(0);
    const [tersimpan, setTersimpan] = useState<Adjustment | null>(null);
    const [memuat, setMemuat] = useState(mode !== 'create');
    const [menyimpan, setMenyimpan] = useState(false);
    const [galat, setGalat] = useState<Record<string, string[]>>({});
    const [pratinjau, setPratinjau] = useState<PostingPreview | null>(null);
    const [konfirmasi, setKonfirmasi] = useState<'posting' | 'arsip' | null>(
        null,
    );
    const buku = useMasterOptions('buku-penyusutan', '', record.buku_id);
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
            (card?.onRequestView ?? bukaPenyesuaian)(id);
        }
    };
    const editRecord = (id: string) => {
        if (!menyimpan) {
            (card?.onRequestEdit ?? bukaPenyesuaianUbah)(id);
        }
    };
    const closeRecord = () => {
        if (!menyimpan) {
            (card?.onClose ?? bukaDaftar)();
        }
    };
    const saveCompleted = (id: string) =>
        (card?.onSaved ?? bukaPenyesuaian)(id);
    const deleteCompleted = () => {
        if (card?.onClose) {
            card.onSaved?.(String(adjustmentId));
            card.onClose();
        } else {
            bukaDaftar();
        }
    };

    const readOnly = mode === 'view';
    const draf = mode === 'create' || tersimpan?.status === 'draft';

    // Daftar aset hanya dibutuhkan untuk menambah baris; aset yang sudah dilepas tidak ditawarkan.

    const assetSource = useRecordOptions<AsetPilihan>(
        '/aset?exclude_state=disposed',
        (item) => (item.nama ? `${item.kode} \u00b7 ${item.nama}` : item.kode),
        record.details.map((row) => row.aset_id),
    );
    const aset = assetSource.options;

    useEffect(() => {
        if (!adjustmentId) {
            return;
        }

        const request = new AbortController();
        let dibatalkan = false;
        api<{ data: Adjustment }>(`/penyesuaian-nilai-aset/${adjustmentId}`, {
            signal: request.signal,
        })
            .then((result) => {
                if (!dibatalkan) {
                    setTersimpan(result.data);
                    setRecord(keForm(result.data));
                }
            })
            .catch((caught) => {
                if (!request.signal.aborted) {
                    toast.error(
                        errorMessage(caught, 'Penyesuaian belum dapat dimuat.'),
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
    }, [adjustmentId, reload]);

    const pesan = (field: string) => galat[field]?.[0];

    function ubahBaris(index: number, patch: Partial<AdjustmentLine>) {
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
            toast.error(
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
            buku_id: record.buku_id || null,
            tanggal: record.tanggal,
            keterangan: record.keterangan,
            details: record.details
                .filter((baris) => baris.aset_id)
                .map((baris) => ({
                    aset_id: baris.aset_id,
                    nilai: baris.nilai,
                    keterangan: baris.keterangan || null,
                })),
        };

        try {
            if (mode === 'create') {
                const hasil = await api<{ data: Adjustment }>(
                    '/penyesuaian-nilai-aset',
                    {
                        method: 'POST',
                        headers: { 'Idempotency-Key': newIdempotencyKey() },
                        body: JSON.stringify(payload),
                    },
                );
                toast.success(
                    `Penyesuaian ${hasil.data.kode} disimpan sebagai draf.`,
                );
                saveCompleted(hasil.data.id);

                return;
            }

            await api(`/penyesuaian-nilai-aset/${adjustmentId}`, {
                method: 'PATCH',
                body: JSON.stringify({
                    ...payload,
                    version: tersimpan?.version,
                }),
            });
            toast.success('Perubahan penyesuaian disimpan.');
            saveCompleted(String(adjustmentId));
        } catch (caught) {
            if (caught instanceof ApiError) {
                setGalat(caught.validationErrors);
            }

            toastSaveError(caught, 'Penyesuaian belum dapat disimpan.');
        } finally {
            setMenyimpan(false);
        }
    }

    async function muatPratinjau() {
        setMenyimpan(true);

        try {
            const hasil = await api<{ data: PostingPreview }>(
                `/penyesuaian-nilai-aset/${adjustmentId}/pratinjau-posting`,
            );
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
            const hasil = await api<{ data: Adjustment }>(
                `/penyesuaian-nilai-aset/${adjustmentId}/posting`,
                {
                    method: 'POST',
                    body: JSON.stringify({ version: tersimpan?.version }),
                },
            );
            setTersimpan(hasil.data);
            setRecord(keForm(hasil.data));
            setPratinjau(null);
            toast.success(
                `Nilai buku diperbarui. Jurnal: ${postingLabel(hasil.data.posting?.status)}.`,
            );
            card?.onSaved?.(String(adjustmentId));
        } catch (caught) {
            if (caught instanceof ApiError) {
                setGalat(caught.validationErrors);
            }

            toastSaveError(caught, 'Penyesuaian belum dapat diposting.');
        } finally {
            setMenyimpan(false);
        }
    }

    async function arsipkan() {
        setKonfirmasi(null);

        setMenyimpan(true);

        try {
            await api(`/penyesuaian-nilai-aset/${adjustmentId}`, {
                method: 'DELETE',
                body: JSON.stringify({ version: tersimpan?.version }),
            });
            toast.success('Draf penyesuaian diarsipkan.');
            deleteCompleted();
        } catch (caught) {
            toastSaveError(caught, 'Penyesuaian belum dapat diarsipkan.');
        } finally {
            setMenyimpan(false);
        }
    }

    useRecordCardDirty({
        value: record,
        ready: !memuat,
        scopeKey: JSON.stringify([adjustmentId, mode, tersimpan?.version]),
        onDirtyChange: card?.onDirtyChange,
    });

    if (memuat) {
        return (
            <div className="text-muted-foreground p-5 text-sm">
                Memuat penyesuaian…
            </div>
        );
    }

    const judul =
        mode === 'create'
            ? 'Penyesuaian nilai aset baru'
            : (tersimpan?.kode ?? 'Penyesuaian nilai aset');
    const pilihDari = (
        options: { id: string }[],
        id: string | null | undefined,
    ) => {
        const dipilih = options.find((option) => option.id === id);

        return dipilih ? optionLabel(dipilih as never) : null;
    };
    const masalahBaris = (index: number) =>
        pesan(`details.${index}.aset_id`) ?? pesan(`details.${index}.nilai`);

    return (
        <div className="flex h-full min-h-0 flex-col overflow-hidden">
            <RecordCardHeader
                recordTitle={tersimpan?.kode ?? 'Baru'}
                editing={!readOnly}
                recordUrl={
                    adjustmentId
                        ? `/management-aset/penyesuaian-nilai-aset/${adjustmentId}`
                        : undefined
                }
                onEdit={
                    readOnly && draf && can('update')
                        ? () => adjustmentId && editRecord(adjustmentId)
                        : undefined
                }
                onDelete={
                    readOnly && draf && can('archive')
                        ? () => setKonfirmasi('arsip')
                        : undefined
                }
            />
            <RecordActionBar
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
                        onClick={() => adjustmentId && editRecord(adjustmentId)}
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
                                adjustmentId
                                    ? viewRecord(adjustmentId)
                                    : closeRecord()
                            }
                        />
                    </>
                )}
                {mode === 'view' &&
                    tersimpan?.status === 'posted' &&
                    adjustmentId && (
                        <CancellationAction
                            resource="penyesuaian-nilai-aset"
                            documentId={adjustmentId}
                            permissions={permissions}
                            awaitingApproval={
                                tersimpan.cancellation?.status === 'pending'
                            }
                            onComplete={() => {
                                setReload((value) => value + 1);

                                if (adjustmentId) {
                                    card?.onSaved?.(adjustmentId);
                                }
                            }}
                        />
                    )}
            </RecordActionBar>
            <CancellationStatus summary={tersimpan?.cancellation} />

            <div
                data-page-dialog-body
                ref={panelRef}
                className="min-h-0 flex-1 overflow-y-auto"
            >
                <div className="space-y-5 p-5">
                    <CollapsibleSectionGroup
                        defaultValue={['general', 'lines']}
                    >
                        <CollapsibleSection
                            value="general"
                            title="Umum"
                            summary={tanggalTampil(record.tanggal)}
                        >
                            <div className="space-y-4">
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Field>
                                        {readOnly ? (
                                            <Input
                                                label="Jenis penyesuaian"
                                                readOnly
                                                value={kindLabel(record.jenis)}
                                            />
                                        ) : (
                                            <Select
                                                label="Jenis penyesuaian"
                                                required
                                                items={KINDS.map(
                                                    (kind) => kind.label,
                                                )}
                                                value={kindLabel(record.jenis)}
                                                ariaLabel="Jenis penyesuaian"
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
                                                            'write_down',
                                                    })
                                                }
                                            />
                                        )}
                                        {pesan('jenis') && (
                                            <FieldDescription>
                                                {pesan('jenis')}
                                            </FieldDescription>
                                        )}
                                    </Field>
                                    <Field>
                                        {readOnly ? (
                                            <Input
                                                label="Buku penyusutan"
                                                readOnly
                                                value={
                                                    tersimpan?.buku_nama ?? '—'
                                                }
                                            />
                                        ) : (
                                            <Select
                                                {...buku.selectProps}
                                                label="Buku penyusutan"
                                                required
                                                items={buku.options.map(
                                                    optionLabel,
                                                )}
                                                value={pilihDari(
                                                    buku.options,
                                                    record.buku_id,
                                                )}
                                                placeholder="Pilih buku yang nilainya disesuaikan"
                                                searchPlaceholder="Cari buku penyusutan"
                                                emptyMessage="Buku penyusutan tidak ditemukan."
                                                ariaLabel="Buku penyusutan"
                                                portalContainer={lookupPortal}
                                                onValueChange={(item) =>
                                                    setRecord({
                                                        ...record,
                                                        buku_id:
                                                            buku.options.find(
                                                                (option) =>
                                                                    optionLabel(
                                                                        option,
                                                                    ) === item,
                                                            )?.id ?? '',
                                                    })
                                                }
                                            />
                                        )}
                                        <FieldDescription>
                                            {pesan('buku_id') ||
                                                buku.error ||
                                                'Jurnal hanya dikirim ke aplikasi finance bila buku ini yang membawa aset ke sana; buku lain, misalnya fiskal, hanya berubah di catatan aset.'}
                                        </FieldDescription>
                                    </Field>
                                    <Field>
                                        <Input
                                            label="Tanggal penyesuaian"
                                            type="date"
                                            required
                                            readOnly={readOnly}
                                            value={record.tanggal}
                                            onChange={(event) =>
                                                setRecord({
                                                    ...record,
                                                    tanggal: event.target.value,
                                                })
                                            }
                                        />
                                        <FieldDescription>
                                            {pesan('tanggal') ||
                                                'Tanggal jurnal. Penyusutan sampai tanggal ini harus sudah difinalkan.'}
                                        </FieldDescription>
                                    </Field>
                                    <Field>
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
                                            <Select
                                                {...unitKerja.selectProps}
                                                label="Unit penanggung jawab"
                                                required
                                                items={unitKerja.options.map(
                                                    optionLabel,
                                                )}
                                                value={pilihDari(
                                                    unitKerja.options,
                                                    record.responsible_org_unit_id,
                                                )}
                                                placeholder="Pilih unit kerja"
                                                searchPlaceholder="Cari unit kerja"
                                                emptyMessage="Unit kerja tidak ditemukan."
                                                ariaLabel="Unit penanggung jawab"
                                                portalContainer={lookupPortal}
                                                onValueChange={(item) =>
                                                    setRecord({
                                                        ...record,
                                                        responsible_org_unit_id:
                                                            unitKerja.options.find(
                                                                (option) =>
                                                                    optionLabel(
                                                                        option,
                                                                    ) === item,
                                                            )?.id ?? '',
                                                    })
                                                }
                                            />
                                        )}
                                        {pesan('responsible_org_unit_id') && (
                                            <FieldDescription>
                                                {pesan(
                                                    'responsible_org_unit_id',
                                                )}
                                            </FieldDescription>
                                        )}
                                    </Field>
                                </div>
                                <Field>
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
                                    <FieldDescription>
                                        {pesan('keterangan') ||
                                            'Ikut ke keterangan jurnal di aplikasi finance, misalnya hasil penilaian atau kerusakan yang ditemukan.'}
                                    </FieldDescription>
                                </Field>
                            </div>
                        </CollapsibleSection>
                        <CollapsibleSection
                            value="lines"
                            title="Aset yang disesuaikan"
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
                                                cell: ({
                                                    baris,
                                                    index,
                                                    dipilih,
                                                    masalah,
                                                }) => (
                                                    <>
                                                        {!baris.id &&
                                                        !readOnly ? (
                                                            <Select
                                                                {...assetSource.selectProps}
                                                                items={aset.map(
                                                                    labelAset,
                                                                )}
                                                                value={
                                                                    dipilih
                                                                        ? labelAset(
                                                                              dipilih,
                                                                          )
                                                                        : null
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
                                                                                aset.find(
                                                                                    (
                                                                                        calon,
                                                                                    ) =>
                                                                                        labelAset(
                                                                                            calon,
                                                                                        ) ===
                                                                                        item,
                                                                                )
                                                                                    ?.id ??
                                                                                '',
                                                                        },
                                                                    )
                                                                }
                                                            />
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
                                                header: 'Nilai penyesuaian',
                                                align: 'right',
                                                cell: ({ baris, index }) => (
                                                    <div className="text-right">
                                                        {readOnly ? (
                                                            uang(baris.nilai)
                                                        ) : (
                                                            <Input
                                                                aria-label={`Nilai penyesuaian baris ${index + 1}`}
                                                                type="number"
                                                                min="0"
                                                                step="0.01"
                                                                value={
                                                                    baris.nilai
                                                                }
                                                                onChange={(
                                                                    event,
                                                                ) =>
                                                                    ubahBaris(
                                                                        index,
                                                                        {
                                                                            nilai: event
                                                                                .target
                                                                                .value,
                                                                        },
                                                                    )
                                                                }
                                                            />
                                                        )}
                                                    </div>
                                                ),
                                            } satisfies DataTableColumn<
                                                (typeof sdkRows)[number]
                                            >,
                                            {
                                                id: 'column-3',
                                                header: 'Nilai buku sebelum',
                                                align: 'right',
                                                cell: ({ baris }) => (
                                                    <div className="text-right">
                                                        {uang(
                                                            baris.nilai_buku_sebelum,
                                                        )}
                                                    </div>
                                                ),
                                            } satisfies DataTableColumn<
                                                (typeof sdkRows)[number]
                                            >,
                                            {
                                                id: 'column-4',
                                                header: 'Nilai buku sesudah',
                                                align: 'right',
                                                cell: ({ baris }) => (
                                                    <div className="text-right">
                                                        {uang(
                                                            baris.nilai_buku_sesudah,
                                                        )}
                                                    </div>
                                                ),
                                            } satisfies DataTableColumn<
                                                (typeof sdkRows)[number]
                                            >,
                                            {
                                                id: 'column-5',
                                                header: 'Keterangan',
                                                cell: ({ baris, index }) => (
                                                    <>
                                                        {readOnly ? (
                                                            baris.keterangan ||
                                                            '—'
                                                        ) : (
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
                                                        )}
                                                    </>
                                                ),
                                            } satisfies DataTableColumn<
                                                (typeof sdkRows)[number]
                                            >,
                                            ...(!readOnly
                                                ? [
                                                      {
                                                          id: 'column-6',
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
                                    {readOnly && draf && (
                                        <p className="text-muted-foreground text-sm">
                                            Nilai buku sebelum adalah nilai buku
                                            saat ini di buku{' '}
                                            {tersimpan?.buku_nama ?? 'ini'};
                                            angka pastinya dikunci saat
                                            diposting.
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
                            Penyusutan berikutnya dihitung dari nilai buku yang
                            baru.
                        </p>
                    )}
                    {pratinjau && (
                        <div className="space-y-3 rounded-md border px-4 py-3">
                            <p className="text-sm font-medium">
                                Pratinjau posting
                            </p>
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
                                : 'Posting penyesuaian ini?'}
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {konfirmasi === 'arsip'
                                ? 'Draf disembunyikan dari daftar dan nomornya tidak dipakai ulang. Nilai buku aset tidak terpengaruh.'
                                : `Nilai buku ${record.details.length} aset di buku ${tersimpan?.buku_nama ?? 'ini'} berubah per ${tanggalTampil(record.tanggal)}, dan jurnalnya dikirim ke aplikasi finance bila buku ini membawa asetnya ke sana. Setelah diposting, penyesuaian tidak dapat diubah.`}
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
