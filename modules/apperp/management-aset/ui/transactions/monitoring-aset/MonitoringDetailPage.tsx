import { router } from '@inertiajs/react';
import { CheckCheck, ListPlus, Trash2 } from 'lucide-react';
import type { RefObject } from 'react';
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
import { TableCell, TableRow } from '@apperp/ui/table';
import { Textarea } from '@apperp/ui/textarea';
import EditShield from '../../_shared/EditShield';
import { RecordLookup } from '../../_shared/RecordLookup';
import { ApiError, api, errorMessage, newIdempotencyKey } from '../../api';
import AssetConditionSelect from '../../master/AssetConditionSelect';
import { useMasterOptions } from '../../master/useMasterOptions';
import { useRecordOptions } from '../../use-record-list';
import { useSaveFeedback } from '../../use-save-feedback';
import { RecordCardHeader } from '../_shared/record-card';
import type { RecordCardControls } from '../_shared/record-card';
import { useRecordCardDirty } from '../_shared/use-record-card-dirty';
import type {
    Context,
    EditableMonitoring,
    Monitoring,
    MonitoringLine,
} from './monitoring';
import {
    PRESENCE,
    ResultBadge,
    StatusBadge,
    bukaDaftar,
    bukaMonitoring,
    bukaMonitoringUbah,
    emptyLine,
    emptyMonitoring,
    izin,
    presenceValue,
    tanggalTampil,
    uang,
} from './monitoring';

type Aset = { id: string; kode: string; nama: string | null };

type Mode = 'create' | 'view' | 'edit';

/** Dari jawaban server ke bentuk form: nilai kosong dijadikan string kosong yang terkendali. */
const keForm = (data: Monitoring): EditableMonitoring => ({
    ...data,
    tanggal: data.tanggal?.slice(0, 10) ?? '',
    responsible_org_unit_id: data.responsible_org_unit_id ?? '',
    penanggung_jawab_user_id: data.penanggung_jawab_user_id ?? '',
    keterangan: data.keterangan ?? '',
    details: (data.details ?? []).map((baris) => ({
        ...baris,
        ada: baris.ada ?? null,
        kondisi_aset_id: baris.kondisi_aset_id ?? '',
        keterangan: baris.keterangan ?? '',
    })),
});

/**
 * Rincian satu pemeriksaan fisik aset.
 *
 * **Hasil tidak dipilih.** Pemeriksa mencatat ada atau tidak ada; server membandingkannya dengan
 * status aset dan memulangkan hasilnya. Aset yang sudah didekomisioning atau dilepas diharapkan
 * tidak ada lagi di tempatnya.
 *
 * **Menyelesaikan tidak mengubah catatan aset.** Baris yang tidak sesuai ditindaklanjuti lewat
 * mutasi atau dekomisioning; layar hanya menautkan keduanya.
 */
export default function MonitoringDetailPage({
    context,
    permissions,
    card,
    monitoringId,
    mode,
}: {
    context: Context;
    permissions: string[];
    card?: RecordCardControls;
    monitoringId?: string;
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
    } = useSaveFeedback(undefined, monitoringId, () =>
        setFeedbackSections(['general', 'lines', 'notes']),
    );

    const embedded = usePageDialogRecord(undefined);

    const can = izin(permissions);
    const { date: workDate } = useWorkDate();
    const [record, setRecord] = useState<EditableMonitoring>(() => ({
        ...emptyMonitoring(workDate),
        responsible_org_unit_id: context.org_unit_id ?? '',
    }));
    const [tersimpan, setTersimpan] = useState<Monitoring | null>(null);
    const [memuat, setMemuat] = useState(mode !== 'create');
    const [menyimpan, setMenyimpan] = useState(false);
    const [galat, setGalat] = useState<Record<string, string[]>>({});
    const [konfirmasiSelesai, setKonfirmasiSelesai] = useState(false);
    const [konfirmasiArsip, setKonfirmasiArsip] = useState(false);
    const asetGalat = '';
    const lokasi = useMasterOptions('lokasi-aset');
    const unitKerja = useMasterOptions('reference-data/unit-kerja');
    const anggota = useMasterOptions('reference-data/anggota');
    const panelRef = useRef<HTMLDivElement>(null);
    const lookupPortal = card?.portalContainer ?? panelRef;
    const onBusyChange = card?.onBusyChange;
    useEffect(() => {
        onBusyChange?.(menyimpan);

        return () => onBusyChange?.(false);
    }, [menyimpan, onBusyChange]);
    const viewRecord = (id: string) => {
        if (!menyimpan) {
            (card?.onRequestView ?? bukaMonitoring)(id);
        }
    };
    const editRecord = (id: string) => {
        if (!menyimpan) {
            (card?.onRequestEdit ?? bukaMonitoringUbah)(id);
        }
    };
    const closeRecord = () => {
        if (!menyimpan) {
            (card?.onClose ?? bukaDaftar)();
        }
    };
    const saveCompleted = (id: string) => (card?.onSaved ?? bukaMonitoring)(id);
    const deleteCompleted = () => {
        if (card?.onClose) {
            card.onSaved?.(String(monitoringId));
            card.onClose();
        } else {
            bukaDaftar();
        }
    };

    const readOnly = mode === 'view';
    const selesai = tersimpan?.status === 'selesai';

    // Daftar aset hanya dibutuhkan untuk menambah baris dengan tangan.

    useEffect(() => {
        if (!monitoringId) {
            return;
        }

        const request = new AbortController();
        let dibatalkan = false;
        api<{ data: Monitoring }>(`/monitoring-aset/${monitoringId}`, {
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
                        errorMessage(caught, 'Monitoring belum dapat dimuat.'),
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
    }, [monitoringId]);

    const pesan = (field: string) => galat[field]?.[0];

    function ubahBaris(index: number, patch: Partial<MonitoringLine>) {
        setRecord((sebelumnya) => ({
            ...sebelumnya,
            details: sebelumnya.details.map((baris, posisi) =>
                posisi === index ? { ...baris, ...patch } : baris,
            ),
        }));
    }

    async function simpan() {
        if (!context.legal_entity_id) {
            reportSaveError(
                null,
                'Pilih perusahaan aktif terlebih dahulu pada header CoreERP.',
            );

            return;
        }

        setMenyimpan(true);
        setGalat({});
        const payload = {
            legal_entity_id:
                tersimpan?.legal_entity_id ?? context.legal_entity_id,
            responsible_org_unit_id: record.responsible_org_unit_id || null,
            penanggung_jawab_user_id: record.penanggung_jawab_user_id || null,
            lokasi_aset_id: record.lokasi_aset_id,
            tanggal: record.tanggal,
            keterangan: record.keterangan || null,
            details: record.details
                .filter((baris) => baris.aset_id)
                .map((baris) => ({
                    aset_id: baris.aset_id,
                    ada: baris.ada,
                    kondisi_aset_id: baris.kondisi_aset_id || null,
                    keterangan: baris.keterangan || null,
                })),
        };

        try {
            if (mode === 'create') {
                const hasil = await api<{ data: Monitoring }>(
                    '/monitoring-aset',
                    {
                        method: 'POST',
                        headers: { 'Idempotency-Key': newIdempotencyKey() },
                        body: JSON.stringify(payload),
                    },
                );
                clearSaveFeedback();
                toast.success(
                    `Monitoring ${hasil.data.kode} disimpan sebagai draf.`,
                );
                saveCompleted(hasil.data.id);

                return;
            }

            await api<{ data: Monitoring }>(
                `/monitoring-aset/${monitoringId}`,
                {
                    method: 'PATCH',
                    body: JSON.stringify({
                        ...payload,
                        version: tersimpan?.version,
                    }),
                },
            );
            clearSaveFeedback();
            toast.success('Perubahan monitoring disimpan.');
            saveCompleted(String(monitoringId));
        } catch (caught) {
            if (caught instanceof ApiError) {
                setGalat(caught.validationErrors);
            }

            reportSaveError(caught, 'Monitoring belum dapat disimpan.');
        } finally {
            setMenyimpan(false);
        }
    }

    async function isiOtomatis() {
        setMenyimpan(true);

        try {
            const hasil = await api<{
                data: Monitoring;
                meta?: { ditambahkan?: number };
            }>(`/monitoring-aset/${monitoringId}/isi-otomatis`, {
                method: 'POST',
                body: JSON.stringify({ version: tersimpan?.version }),
            });
            setTersimpan(hasil.data);
            setRecord(keForm(hasil.data));
            const jumlah = hasil.meta?.ditambahkan ?? 0;
            clearSaveFeedback();
            toast.success(
                jumlah > 0
                    ? `${jumlah} aset di lokasi ini ditambahkan ke daftar periksa.`
                    : 'Semua aset di lokasi ini sudah ada di daftar periksa.',
            );
        } catch (caught) {
            reportSaveError(caught, 'Daftar aset belum dapat diisi.');
        } finally {
            setMenyimpan(false);
        }
    }

    async function selesaikan() {
        setKonfirmasiSelesai(false);
        setMenyimpan(true);
        setGalat({});

        try {
            const hasil = await api<{ data: Monitoring }>(
                `/monitoring-aset/${monitoringId}/selesaikan`,
                {
                    method: 'POST',
                    body: JSON.stringify({ version: tersimpan?.version }),
                },
            );
            setTersimpan(hasil.data);
            setRecord(keForm(hasil.data));
            clearSaveFeedback();
            toast.success('Monitoring diselesaikan. Hasilnya sudah dikunci.');
            card?.onSaved?.(String(monitoringId));
        } catch (caught) {
            if (caught instanceof ApiError) {
                setGalat(caught.validationErrors);
            }

            reportSaveError(caught, 'Monitoring belum dapat diselesaikan.');
        } finally {
            setMenyimpan(false);
        }
    }

    async function arsipkan() {
        setKonfirmasiArsip(false);

        setMenyimpan(true);

        try {
            await api(`/monitoring-aset/${monitoringId}`, {
                method: 'DELETE',
                body: JSON.stringify({ version: tersimpan?.version }),
            });
            clearSaveFeedback();
            toast.success('Draf monitoring diarsipkan.');
            deleteCompleted();
        } catch (caught) {
            reportSaveError(caught, 'Monitoring belum dapat diarsipkan.');
        } finally {
            setMenyimpan(false);
        }
    }

    useRecordCardDirty({
        value: record,
        ready: !memuat,
        scopeKey: JSON.stringify([monitoringId, mode, tersimpan?.version]),
        onDirtyChange: card?.onDirtyChange,
    });

    if (memuat) {
        return (
            <div className="text-muted-foreground p-5 text-sm">
                Memuat monitoring…
            </div>
        );
    }

    const judul =
        mode === 'create'
            ? 'Monitoring aset baru'
            : (tersimpan?.kode ?? 'Monitoring aset');
    const belumDiperiksa = record.details.filter(
        (baris) => baris.aset_id && baris.ada === null,
    ).length;
    const tidakSesuai = record.details.filter(
        (baris) => baris.hasil === 'tidak_sesuai',
    ).length;
    const ubah = () => monitoringId && editRecord(monitoringId);

    return (
        <div
            ref={feedbackScopeRef}
            className="flex h-full min-h-0 flex-col overflow-hidden"
        >
            <RecordCardHeader
                recordTitle={tersimpan?.kode ?? 'Baru'}
                editing={!readOnly}
                recordUrl={
                    monitoringId
                        ? `/management-aset/monitoring-aset/${monitoringId}`
                        : undefined
                }
                onEdit={
                    readOnly && !selesai && can('update') ? ubah : undefined
                }
                onDelete={
                    readOnly && !selesai && can('archive')
                        ? () => setKonfirmasiArsip(true)
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
                {readOnly && !selesai && can('update') && (
                    <>
                        {!embedded && (
                            <ActionButton
                                action="edit"
                                type="button"
                                onClick={ubah}
                            />
                        )}
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => void isiOtomatis()}
                            disabled={menyimpan}
                        >
                            <ListPlus />
                            Isi otomatis
                        </Button>
                    </>
                )}
                {readOnly && !selesai && can('complete') && (
                    <Button
                        type="button"
                        onClick={() => setKonfirmasiSelesai(true)}
                        disabled={menyimpan}
                    >
                        Selesaikan monitoring
                    </Button>
                )}
                {readOnly && !selesai && can('archive') && !embedded && (
                    <ActionButton
                        action="archive"
                        type="button"
                        onClick={() => setKonfirmasiArsip(true)}
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
                                monitoringId
                                    ? viewRecord(monitoringId)
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
                            summary={tanggalTampil(record.tanggal)}
                        >
                            <div className="space-y-4">
                                <FormGrid>
                                    <FormControl data-error-field="tanggal">
                                        <DatePicker
                                            label="Tanggal monitoring"

                                            required
                                            readOnly={readOnly}
                                            value={record.tanggal ?? ''}
                                            onValueChange={(value) =>
                                                setRecord({
                                                    ...record,
                                                    tanggal: value,
                                                })
                                            }
                                        />
                                        {pesan('tanggal') && (
                                            <FieldError>
                                                {pesan('tanggal')}
                                            </FieldError>
                                        )}
                                    </FormControl>

                                    <FormControl
                                        data-error-field="lokasi_aset_id"
                                        error={
                                            pesan('lokasi_aset_id') ||
                                            lokasi.error
                                        }
                                        hint={
                                            readOnly &&
                                            tersimpan?.lokasi_aset_nama
                                                ? tersimpan.lokasi_aset_nama
                                                : 'Satu monitoring memeriksa satu lokasi.'
                                        }
                                    >
                                        <EditShield
                                            active={readOnly && !selesai}
                                            label="lokasi aset"
                                            onActivate={ubah}
                                        >
                                            <RecordLookup
                                                source={lokasi}
                                                label="Lokasi aset"
                                                required
                                                items={lokasi.options.map(
                                                    (record) => ({
                                                        value: record.id,
                                                        label: lokasi.getLabel(
                                                            record,
                                                        ),
                                                    }),
                                                )}
                                                value={
                                                    record.lokasi_aset_id ||
                                                    null
                                                }
                                                placeholder="Pilih lokasi yang diperiksa"
                                                searchPlaceholder="Cari lokasi aset"
                                                emptyMessage="Lokasi aset tidak ditemukan."
                                                ariaLabel="Lokasi aset"
                                                portalContainer={lookupPortal}
                                                onValueChange={(item) =>
                                                    setRecord({
                                                        ...record,
                                                        lokasi_aset_id:
                                                            item ?? '',
                                                    })
                                                }
                                            />
                                        </EditShield>
                                    </FormControl>

                                    <FormControl
                                        data-error-field="responsible_org_unit_id"
                                        error={
                                            pesan('responsible_org_unit_id') ||
                                            unitKerja.error
                                        }
                                        hint={
                                            'Bila diisi, Isi otomatis hanya mengambil aset milik unit ini.'
                                        }
                                    >
                                        <EditShield
                                            active={readOnly && !selesai}
                                            label="unit organisasi"
                                            onActivate={ubah}
                                        >
                                            <RecordLookup
                                                source={unitKerja}
                                                label="Unit organisasi"
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
                                                placeholder="Semua unit"
                                                searchPlaceholder="Cari unit kerja"
                                                emptyMessage="Unit kerja tidak ditemukan."
                                                ariaLabel="Unit organisasi"
                                                portalContainer={lookupPortal}
                                                onValueChange={(item) =>
                                                    setRecord({
                                                        ...record,
                                                        responsible_org_unit_id:
                                                            item ?? '',
                                                    })
                                                }
                                            />
                                        </EditShield>
                                    </FormControl>

                                    <FormControl
                                        data-error-field="penanggung_jawab_user_id"
                                        error={anggota.error}
                                        hint={
                                            'Bila diisi, Isi otomatis hanya mengambil aset yang dipegang orang ini.'
                                        }
                                    >
                                        <EditShield
                                            active={readOnly && !selesai}
                                            label="penanggung jawab"
                                            onActivate={ubah}
                                        >
                                            <RecordLookup
                                                source={anggota}
                                                label="Penanggung jawab"
                                                items={anggota.options.map(
                                                    (record) => ({
                                                        value: record.id,
                                                        label: anggota.getLabel(
                                                            record,
                                                        ),
                                                    }),
                                                )}
                                                value={
                                                    record.penanggung_jawab_user_id ||
                                                    null
                                                }
                                                placeholder="Semua orang"
                                                searchPlaceholder="Cari nama"
                                                emptyMessage="Orang tidak ditemukan."
                                                ariaLabel="Penanggung jawab"
                                                portalContainer={lookupPortal}
                                                onValueChange={(item) =>
                                                    setRecord({
                                                        ...record,
                                                        penanggung_jawab_user_id:
                                                            item ?? '',
                                                    })
                                                }
                                            />
                                        </EditShield>
                                    </FormControl>
                                </FormGrid>
                            </div>
                        </CollapsibleSection>
                        <CollapsibleSection
                            value="lines"
                            title="Aset yang diperiksa"
                            summary={`${record.details.length} aset, ${belumDiperiksa} belum diperiksa, ${tidakSesuai} tidak sesuai`}
                        >
                            <div className="space-y-4">
                                <div className="space-y-2">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <p className="text-muted-foreground text-sm">
                                            {record.details.length} aset
                                            {belumDiperiksa > 0 &&
                                                `, ${belumDiperiksa} belum diperiksa`}
                                            {tidakSesuai > 0 &&
                                                `, ${tidakSesuai} tidak sesuai`}
                                        </p>
                                        {!readOnly && (
                                            <div className="flex flex-wrap gap-2">
                                                {belumDiperiksa > 0 && (
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() =>
                                                            setRecord({
                                                                ...record,
                                                                details:
                                                                    record.details.map(
                                                                        (
                                                                            baris,
                                                                        ) =>
                                                                            baris.ada ===
                                                                            null
                                                                                ? {
                                                                                      ...baris,
                                                                                      ada: true,
                                                                                  }
                                                                                : baris,
                                                                    ),
                                                            })
                                                        }
                                                    >
                                                        <CheckCheck />
                                                        Tandai yang belum
                                                        sebagai ada
                                                    </Button>
                                                )}
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
                                            </div>
                                        )}
                                    </div>
                                    {pesan('details') && (
                                        <p className="text-destructive text-sm">
                                            {pesan('details')}
                                        </p>
                                    )}
                                    {!readOnly && asetGalat && (
                                        <p className="text-muted-foreground text-sm">
                                            {asetGalat}
                                        </p>
                                    )}

                                    {record.details.length === 0 ? (
                                        <p className="text-muted-foreground rounded-md border border-dashed p-4 text-sm">
                                            {mode === 'create'
                                                ? 'Simpan draf dulu, lalu pakai Isi otomatis untuk memasukkan semua aset yang tercatat di lokasi ini. Aset juga bisa ditambahkan satu per satu.'
                                                : 'Belum ada aset di daftar periksa. Pakai Isi otomatis untuk memasukkan semua aset yang tercatat di lokasi ini.'}
                                        </p>
                                    ) : (
                                        (() => {
                                            const sdkRows = record.details.map(
                                                (baris, index) => {
                                                    return { baris, index };
                                                },
                                            );
                                            const sdkColumns: DataTableColumn<
                                                (typeof sdkRows)[number]
                                            >[] = [
                                                {
                                                    id: 'column-0',
                                                    header: 'No',
                                                    width: 48,
                                                    cell: () => null,
                                                } satisfies DataTableColumn<
                                                    (typeof sdkRows)[number]
                                                >,
                                                {
                                                    id: 'column-1',
                                                    header: 'Aset',
                                                    cell: () => null,
                                                } satisfies DataTableColumn<
                                                    (typeof sdkRows)[number]
                                                >,
                                                {
                                                    id: 'column-2',
                                                    header: 'Di catatan aset',
                                                    cell: () => null,
                                                } satisfies DataTableColumn<
                                                    (typeof sdkRows)[number]
                                                >,
                                                {
                                                    id: 'column-3',
                                                    header: 'Keberadaan',
                                                    cell: () => null,
                                                } satisfies DataTableColumn<
                                                    (typeof sdkRows)[number]
                                                >,
                                                {
                                                    id: 'column-4',
                                                    header: 'Kondisi fisik',
                                                    cell: () => null,
                                                } satisfies DataTableColumn<
                                                    (typeof sdkRows)[number]
                                                >,
                                                {
                                                    id: 'column-5',
                                                    header: 'Hasil',
                                                    cell: () => null,
                                                } satisfies DataTableColumn<
                                                    (typeof sdkRows)[number]
                                                >,
                                                {
                                                    id: 'column-6',
                                                    header: 'Keterangan',
                                                    cell: () => null,
                                                } satisfies DataTableColumn<
                                                    (typeof sdkRows)[number]
                                                >,
                                                {
                                                    id: 'column-7',
                                                    header: 'Nilai buku',
                                                    align: 'right',
                                                    cell: () => null,
                                                } satisfies DataTableColumn<
                                                    (typeof sdkRows)[number]
                                                >,
                                                ...(readOnly
                                                    ? [
                                                          {
                                                              id: 'column-8',
                                                              header: 'Tindak lanjut',
                                                              cell: () => null,
                                                          } satisfies DataTableColumn<
                                                              (typeof sdkRows)[number]
                                                          >,
                                                      ]
                                                    : []),
                                                ...(!readOnly
                                                    ? [
                                                          {
                                                              id: 'column-9',
                                                              header: '',
                                                              width: 48,
                                                              cell: () => null,
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
                                                    getRowKey={({
                                                        baris,
                                                        index,
                                                    }) =>
                                                        baris.id ??
                                                        `baru-${index}`
                                                    }
                                                    renderRows={() => (
                                                        <>
                                                            {record.details.map(
                                                                (
                                                                    baris,
                                                                    index,
                                                                ) => (
                                                                    <MonitoringRow
                                                                        key={
                                                                            baris.id ??
                                                                            `baru-${index}`
                                                                        }
                                                                        baris={
                                                                            baris
                                                                        }
                                                                        index={
                                                                            index
                                                                        }
                                                                        readOnly={
                                                                            readOnly
                                                                        }
                                                                        lokasiDokumen={
                                                                            tersimpan?.lokasi_aset_id ??
                                                                            record.lokasi_aset_id ??
                                                                            ''
                                                                        }
                                                                        permissions={
                                                                            permissions
                                                                        }
                                                                        panelRef={
                                                                            lookupPortal
                                                                        }
                                                                        onChange={(
                                                                            patch,
                                                                        ) =>
                                                                            ubahBaris(
                                                                                index,
                                                                                patch,
                                                                            )
                                                                        }
                                                                        onRemove={() =>
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
                                                                    />
                                                                ),
                                                            )}
                                                        </>
                                                    )}
                                                />
                                            );
                                        })()
                                    )}
                                </div>
                            </div>
                        </CollapsibleSection>
                        <CollapsibleSection
                            value="notes"
                            title="Keterangan"
                            summary={
                                record.keterangan || 'Belum ada keterangan'
                            }
                        >
                            <div className="space-y-4">
                                <FormControl data-error-field="keterangan">
                                    <Textarea
                                        label="Keterangan"
                                        rows={2}
                                        readOnly={readOnly}
                                        value={record.keterangan ?? ''}
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
                    </CollapsibleSectionGroup>
                    {selesai && (
                        <p className="text-muted-foreground text-sm">
                            Monitoring ini sudah diselesaikan pada{' '}
                            {tanggalTampil(tersimpan?.diselesaikan_pada)}.
                            Hasilnya dikunci dan tidak mengubah catatan aset.
                            Buat monitoring baru bila temuannya perlu dikoreksi.
                        </p>
                    )}
                </div>
            </div>

            <AlertDialog
                open={konfirmasiSelesai}
                onOpenChange={setKonfirmasiSelesai}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Selesaikan monitoring?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            Hasil pemeriksaan {record.details.length} aset,
                            beserta status, lokasi, dan nilai bukunya saat ini,
                            dikunci untuk laporan. Catatan aset tidak berubah;
                            aset yang tidak sesuai ditindaklanjuti lewat mutasi
                            atau dekomisioning. Setelah ini monitoring tidak
                            dapat diubah lagi.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Belum</AlertDialogCancel>
                        <AlertDialogAction onClick={() => void selesaikan()}>
                            Selesaikan
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            <AlertDialog
                open={konfirmasiArsip}
                onOpenChange={setKonfirmasiArsip}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete draf ini?</AlertDialogTitle>
                        <AlertDialogDescription>
                            Draf disembunyikan dari daftar dan nomornya tidak
                            dipakai ulang. Catatan aset tidak terpengaruh.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Batal</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={() => void arsipkan()}
                            asChild
                        >
                            <ActionButton action="archive" />
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
}

/** Satu baris daftar periksa, dalam mode baca maupun sunting. */
function MonitoringRow({
    baris,
    index,
    readOnly,
    lokasiDokumen,
    permissions,
    panelRef,
    onChange,
    onRemove,
}: {
    baris: MonitoringLine;
    index: number;
    readOnly: boolean;
    lokasiDokumen: string;
    permissions: string[];
    panelRef: RefObject<HTMLDivElement | null>;
    onChange: (patch: Partial<MonitoringLine>) => void;
    onRemove: () => void;
}) {
    const assetSource = useRecordOptions<Aset>(
        '/aset',
        (item) => (item.nama ? `${item.kode} \u00b7 ${item.nama}` : item.kode),
        baris.aset_id,
    );
    const aset = assetSource.options;
    const baru = !baris.id;
    const dipilih = aset.find((item) => item.id === baris.aset_id);
    const lokasiLain =
        baris.sistem_lokasi_id && baris.sistem_lokasi_id !== lokasiDokumen;
    const bolehMutasi = permissions.includes(
        'management-aset.mutasi-aset.create',
    );
    const bolehDekomisioning = permissions.includes(
        'management-aset.dekomisioning-aset.read',
    );

    return (
        <TableRow>
            <TableCell>{baris.line_number ?? index + 1}</TableCell>
            <TableCell>
                {baru && !readOnly ? (
                    <FormControl>
                        <RecordLookup
                            label="Pilih aset"
                            source={assetSource}
                            items={aset.map((record) => ({
                                value: record.id,
                                label: assetSource.getLabel(record),
                            }))}
                            value={dipilih?.id ?? null}
                            placeholder="Pilih aset"
                            searchPlaceholder="Cari kode atau nama aset"
                            emptyMessage="Aset tidak ditemukan."
                            ariaLabel={`Aset baris ${index + 1}`}
                            portalContainer={panelRef}
                            onValueChange={(item) =>
                                onChange({
                                    aset_id: item ?? '',
                                })
                            }
                        />
                    </FormControl>
                ) : (
                    <div>
                        <span className="font-medium">
                            {baris.aset_kode ?? dipilih?.kode ?? '—'}
                        </span>
                        <span className="text-muted-foreground ml-2">
                            {baris.aset_nama ?? dipilih?.nama}
                        </span>
                        {baris.spesifikasi && baris.spesifikasi !== '—' && (
                            <span className="text-muted-foreground block text-xs">
                                {baris.spesifikasi}
                            </span>
                        )}
                    </div>
                )}
            </TableCell>
            <TableCell className="text-muted-foreground">
                <span className="block">
                    {baris.sistem_lifecycle_label ?? '—'}
                </span>
                {lokasiLain && (
                    <span className="block text-xs">
                        Tercatat di {baris.sistem_lokasi_nama ?? 'lokasi lain'}
                    </span>
                )}
                {(baris.sistem_org_unit_nama ||
                    baris.sistem_custodian_nama) && (
                    <span className="block text-xs">
                        {[
                            baris.sistem_org_unit_nama,
                            baris.sistem_custodian_nama,
                        ]
                            .filter(Boolean)
                            .join(' · ')}
                    </span>
                )}
            </TableCell>
            <TableCell>
                {readOnly ? (
                    baris.ada === null ? (
                        '—'
                    ) : baris.ada ? (
                        'Ada'
                    ) : (
                        'Tidak ada'
                    )
                ) : (
                    <FormControl>
                        <Select
                            items={PRESENCE}
                            value={presenceValue(baris.ada)}
                            placeholder="Belum diperiksa"
                            ariaLabel={`Keberadaan aset baris ${index + 1}`}
                            portalContainer={panelRef}
                            onValueChange={(value) =>
                                onChange({
                                    ada:
                                        value === 'ada'
                                            ? true
                                            : value === 'tidak'
                                              ? false
                                              : null,
                                })
                            }
                        />
                    </FormControl>
                )}
            </TableCell>
            <TableCell>
                {readOnly ? (
                    (baris.kondisi_aset_nama ?? '—')
                ) : (
                    <AssetConditionSelect
                        assetId={baris.aset_id}
                        value={baris.kondisi_aset_id}
                        label={`Kondisi baris ${index + 1}`}
                        portalContainer={panelRef}
                        onChange={(value) =>
                            onChange({ kondisi_aset_id: value })
                        }
                    />
                )}
            </TableCell>
            <TableCell>
                {readOnly ? (
                    <ResultBadge hasil={baris.hasil} />
                ) : (
                    <span className="text-muted-foreground text-xs">
                        Dihitung saat disimpan
                    </span>
                )}
            </TableCell>
            <TableCell>
                {readOnly ? (
                    baris.keterangan || '—'
                ) : (
                    <FormControl>
                        <Input
                            aria-label={`Keterangan baris ${index + 1}`}
                            value={baris.keterangan ?? ''}
                            onChange={(event) =>
                                onChange({ keterangan: event.target.value })
                            }
                        />
                    </FormControl>
                )}
            </TableCell>
            <TableCell className="text-right">
                <span className="block">{uang(baris.nilai_buku)}</span>
                {baris.nilai_perolehan && (
                    <span className="text-muted-foreground block text-xs">
                        dari {uang(baris.nilai_perolehan)}
                    </span>
                )}
            </TableCell>
            {readOnly && (
                <TableCell>
                    {baris.hasil === 'tidak_sesuai' &&
                    (bolehMutasi || bolehDekomisioning) ? (
                        <div className="flex flex-col items-start gap-1">
                            {bolehMutasi && (
                                <Button
                                    type="button"
                                    variant="link"
                                    size="sm"
                                    className="h-auto p-0"
                                    onClick={() =>
                                        router.visit(
                                            '/management-aset/mutasi-aset/baru',
                                        )
                                    }
                                >
                                    Catat mutasi
                                </Button>
                            )}
                            {bolehDekomisioning && (
                                <Button
                                    type="button"
                                    variant="link"
                                    size="sm"
                                    className="h-auto p-0"
                                    onClick={() =>
                                        router.visit(
                                            '/management-aset/dekomisioning-aset',
                                        )
                                    }
                                >
                                    Buka dekomisioning
                                </Button>
                            )}
                        </div>
                    ) : (
                        <span className="text-muted-foreground">—</span>
                    )}
                </TableCell>
            )}
            {!readOnly && (
                <TableCell>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        aria-label={`Keluarkan baris ${index + 1}`}
                        onClick={onRemove}
                    >
                        <Trash2 />
                    </Button>
                </TableCell>
            )}
        </TableRow>
    );
}
