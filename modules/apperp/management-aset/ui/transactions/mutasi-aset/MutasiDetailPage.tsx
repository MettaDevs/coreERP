import { FileText, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
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
import EditShield from '../../_shared/EditShield';
import {
    ApiError,
    api,
    errorMessage,
    newIdempotencyKey,
    toastSaveError,
} from '../../api';
import AssetConditionSelect from '../../master/AssetConditionSelect';
import { defaultDepartmentOf } from '../../master/locationDefaults';
import { optionLabel, useMasterOptions } from '../../master/useMasterOptions';
import { requestPrint } from '../../print';
import { useRecordOptions } from '../../use-record-list';
import { RecordCardHeader } from '../_shared/record-card';
import type { RecordCardControls } from '../_shared/record-card';
import { useRecordCardDirty } from '../_shared/use-record-card-dirty';
import type { Context, EditableMutasi, Mutasi, MutasiLine } from './mutasi';
import {
    StatusBadge,
    bukaDaftar,
    bukaMutasi,
    bukaMutasiUbah,
    bolehMemindahkan,
    emptyLine,
    emptyMutasi,
    izin,
    tanggalTampil,
} from './mutasi';

type Aset = {
    id: string;
    kode: string;
    nama: string | null;
    lifecycle_state: string;
    /** Kondisi aset yang tercatat sekarang; menjadi nilai awal baris mutasi. */
    kondisi_aset_id: string | null;
};

type Mode = 'create' | 'view' | 'edit';

/**
 * Rincian satu berita acara serah terima aset.
 *
 * **Lokasi asal tidak diketik.** Ia diturunkan dari aset yang dipilih, sama seperti dialog
 * `Install asset at location` di Dynamics 365 F&O yang mengisi field `Functional location`
 * sendiri begitu asetnya dipilih. Membiarkan orang mengetik asal berarti membiarkan mereka
 * mengetik asal yang salah, dan berita acara yang salah asalnya tidak dapat dibetulkan
 * setelah ditandatangani.
 *
 * **Tujuan ada di header, bukan di baris.** Satu serah terima adalah satu perpindahan
 * antara dua pihak; barang yang pindah ke tempat lain adalah berita acara lain.
 */
export default function MutasiDetailPage({
    context,
    permissions,
    card,
    mutasiId,
    mode: modeAwal,
}: {
    context: Context;
    permissions: string[];
    card?: RecordCardControls;
    mutasiId?: string;
    mode: Mode;
}) {
    const embedded = usePageDialogRecord(undefined);

    const can = izin(permissions);
    const canMutate = bolehMemindahkan(permissions);
    const { date: workDate } = useWorkDate();
    const [record, setRecord] = useState<EditableMutasi>(() =>
        emptyMutasi(workDate),
    );
    const [tersimpan, setTersimpan] = useState<Mutasi | null>(null);
    const [memuat, setMemuat] = useState(modeAwal !== 'create');
    const [menyimpan, setMenyimpan] = useState(false);
    const [galat, setGalat] = useState<Record<string, string[]>>({});
    const [konfirmasiSelesai, setKonfirmasiSelesai] = useState(false);
    const [konfirmasiArsip, setKonfirmasiArsip] = useState(false);
    const lokasi = useMasterOptions('lokasi-aset');
    // Unit kerja dan orang milik Core. Keduanya dibaca lewat endpoint referensi module,
    // bukan diketik sebagai ULID: tidak ada pengguna yang mengenali rekannya dari ULID.
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
            (card?.onRequestView ?? bukaMutasi)(id);
        }
    };
    const editRecord = (id: string) => {
        if (!menyimpan) {
            (card?.onRequestEdit ?? bukaMutasiUbah)(id);
        }
    };
    const closeRecord = () => {
        if (!menyimpan) {
            (card?.onClose ?? bukaDaftar)();
        }
    };
    const saveCompleted = (id: string) => (card?.onSaved ?? bukaMutasi)(id);
    const deleteCompleted = () => {
        if (card?.onClose) {
            card.onSaved?.(String(mutasiId));
            card.onClose();
        } else {
            bukaDaftar();
        }
    };

    const mode = modeAwal;
    const readOnly = mode === 'view';
    const selesai = tersimpan?.status === 'selesai';

    const assetSource = useRecordOptions<Aset>(
        '/aset',
        (item) => (item.nama ? `${item.kode} \u00b7 ${item.nama}` : item.kode),
        record.details.map((row) => row.aset_id),
    );
    const aset = assetSource.options;

    useEffect(() => {
        if (!mutasiId) {
            return;
        }

        const request = new AbortController();
        let dibatalkan = false;
        api<{ data: Mutasi }>(`/mutasi-aset/${mutasiId}`, {
            signal: request.signal,
        })
            .then((result) => {
                if (dibatalkan) {
                    return;
                }

                setTersimpan(result.data);
                setRecord({
                    ...result.data,
                    tanggal: result.data.tanggal?.slice(0, 10) ?? '',
                    tujuan_lokasi_id: result.data.tujuan_lokasi_id ?? '',
                    diserahkan_oleh_user_id:
                        result.data.diserahkan_oleh_user_id ?? '',
                    diterima_oleh_user_id:
                        result.data.diterima_oleh_user_id ?? '',
                    keterangan: result.data.keterangan ?? '',
                    details: (result.data.details ?? []).map((baris) => ({
                        ...baris,
                        kondisi_aset_id: baris.kondisi_aset_id ?? '',
                        catatan: baris.catatan ?? '',
                    })),
                });
            })
            .catch((caught) => {
                if (!request.signal.aborted) {
                    toast.error(
                        errorMessage(caught, 'Mutasi belum dapat dimuat.'),
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
    }, [mutasiId]);

    // Unit tujuan mengikuti unit kerja bawaan lokasi tujuan (termasuk warisan lokasi induknya)
    // bila ada, dan jatuh kembali ke unit kerja aktif bila lokasinya tidak punya. Memilih lokasi
    // mengisi unit tujuan; pengguna tetap boleh menggantinya sesudah itu.
    const unitDariLokasi = useMemo(
        () =>
            defaultDepartmentOf(
                lokasi.options.find(
                    (option) => option.id === record.tujuan_lokasi_id,
                ),
            ),
        [lokasi.options, record.tujuan_lokasi_id],
    );

    const unitTujuan =
        record.tujuan_org_unit_id ||
        unitDariLokasi ||
        context.org_unit_id ||
        '';

    const pesan = (field: string) => galat[field]?.[0];

    function ubahBaris(index: number, patch: Partial<MutasiLine>) {
        setRecord((sebelumnya) => ({
            ...sebelumnya,
            details: sebelumnya.details.map((baris, posisi) =>
                posisi === index ? { ...baris, ...patch } : baris,
            ),
        }));
    }

    async function simpan() {
        if (!context.legal_entity_id) {
            toast.error(
                'Pilih perusahaan aktif terlebih dahulu pada header CoreERP.',
            );

            return;
        }

        setMenyimpan(true);
        setGalat({});
        const payload = {
            legal_entity_id: context.legal_entity_id,
            responsible_org_unit_id: context.org_unit_id,
            tanggal: record.tanggal,
            tujuan_lokasi_id: record.tujuan_lokasi_id || null,
            tujuan_org_unit_id: unitTujuan,
            diserahkan_oleh_user_id: record.diserahkan_oleh_user_id || null,
            diterima_oleh_user_id: record.diterima_oleh_user_id || null,
            alasan: record.alasan,
            keterangan: record.keterangan || null,
            details: record.details
                .filter((baris) => baris.aset_id)
                .map((baris) => ({
                    aset_id: baris.aset_id,
                    kondisi_aset_id: baris.kondisi_aset_id || null,
                    catatan: baris.catatan || null,
                })),
        };

        try {
            if (mode === 'create') {
                const hasil = await api<{ data: Mutasi }>('/mutasi-aset', {
                    method: 'POST',
                    headers: { 'Idempotency-Key': newIdempotencyKey() },
                    body: JSON.stringify(payload),
                });
                toast.success(
                    `Mutasi ${hasil.data.kode} disimpan sebagai draf.`,
                );
                saveCompleted(hasil.data.id);

                return;
            }

            const hasil = await api<{ data: Mutasi }>(
                `/mutasi-aset/${mutasiId}`,
                {
                    method: 'PATCH',
                    body: JSON.stringify({
                        ...payload,
                        version: tersimpan?.version,
                    }),
                },
            );
            setTersimpan(hasil.data);
            toast.success('Perubahan mutasi disimpan.');
            saveCompleted(String(mutasiId));
        } catch (caught) {
            if (caught instanceof ApiError) {
                setGalat(caught.validationErrors);
            }

            toastSaveError(caught, 'Mutasi belum dapat disimpan.');
        } finally {
            setMenyimpan(false);
        }
    }

    async function selesaikan() {
        setKonfirmasiSelesai(false);
        setMenyimpan(true);

        try {
            const hasil = await api<{ data: Mutasi }>(
                `/mutasi-aset/${mutasiId}/selesaikan`,
                {
                    method: 'POST',
                    body: JSON.stringify({ version: tersimpan?.version }),
                },
            );
            setTersimpan(hasil.data);
            toast.success(
                'Serah terima diselesaikan. Penempatan aset sudah berpindah.',
            );
            saveCompleted(String(mutasiId));
        } catch (caught) {
            if (caught instanceof ApiError) {
                setGalat(caught.validationErrors);
            }

            toastSaveError(caught, 'Mutasi belum dapat diselesaikan.');
        } finally {
            setMenyimpan(false);
        }
    }

    async function arsipkan() {
        setKonfirmasiArsip(false);

        setMenyimpan(true);

        try {
            await api(`/mutasi-aset/${mutasiId}`, {
                method: 'DELETE',
                body: JSON.stringify({ version: tersimpan?.version }),
            });
            toast.success('Draf mutasi diarsipkan.');
            deleteCompleted();
        } catch (caught) {
            toastSaveError(caught, 'Mutasi belum dapat diarsipkan.');
        } finally {
            setMenyimpan(false);
        }
    }

    useRecordCardDirty({
        value: record,
        ready: !memuat,
        scopeKey: JSON.stringify([mutasiId, modeAwal, tersimpan?.version]),
        onDirtyChange: card?.onDirtyChange,
    });

    if (memuat) {
        return (
            <div className="text-muted-foreground p-5 text-sm">
                Memuat mutasi…
            </div>
        );
    }

    const judul =
        mode === 'create'
            ? 'Mutasi aset baru'
            : (tersimpan?.kode ?? 'Mutasi aset');

    return (
        <div className="flex h-full min-h-0 flex-col overflow-hidden">
            <RecordCardHeader
                recordTitle={tersimpan?.kode ?? 'Baru'}
                editing={!readOnly}
                recordUrl={
                    mutasiId
                        ? `/management-aset/mutasi-aset/${mutasiId}`
                        : undefined
                }
                onEdit={
                    mode === 'view' && !selesai && can('update')
                        ? () => editRecord(String(mutasiId))
                        : undefined
                }
                onDelete={
                    mode === 'view' && !selesai && can('archive')
                        ? () => setKonfirmasiArsip(true)
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
                {mode === 'view' && !selesai && can('update') && !embedded && (
                    <ActionButton
                        action="edit"
                        type="button"
                        onClick={() => editRecord(String(mutasiId))}
                    />
                )}
                {mode === 'view' && !selesai && canMutate && (
                    <Button
                        type="button"
                        onClick={() => setKonfirmasiSelesai(true)}
                        disabled={menyimpan}
                    >
                        Selesaikan serah terima
                    </Button>
                )}
                {mode === 'view' && selesai && (
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() =>
                            requestPrint({
                                report: 'berita-acara-serah-terima',
                                title: `Cetak berita acara ${tersimpan?.kode}`,
                                parameters: { id: mutasiId },
                            })
                        }
                    >
                        <FileText />
                        Cetak berita acara
                    </Button>
                )}
                {mode === 'view' && !selesai && can('archive') && !embedded && (
                    <ActionButton
                        action="archive"
                        type="button"
                        onClick={() => setKonfirmasiArsip(true)}
                    />
                )}
                {mode !== 'view' && (
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
                                mutasiId ? viewRecord(mutasiId) : closeRecord()
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
                    <p className="text-muted-foreground text-sm">
                        Perusahaan dan unit kerja pembuat dokumen mengikuti
                        konteks aktif Anda. Lokasi asal tiap aset diambil dari
                        keadaan aset itu sekarang, jadi tidak perlu diisi.
                    </p>
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
                                        <Input
                                            label="Tanggal serah terima"
                                            type="date"
                                            required
                                            readOnly={readOnly}
                                            value={record.tanggal ?? ''}
                                            onChange={(event) =>
                                                setRecord({
                                                    ...record,
                                                    tanggal: event.target.value,
                                                })
                                            }
                                        />
                                        {pesan('tanggal') && (
                                            <FieldDescription>
                                                {pesan('tanggal')}
                                            </FieldDescription>
                                        )}
                                    </Field>

                                    <Field>
                                        <EditShield
                                            active={readOnly}
                                            label="lokasi tujuan"
                                            onActivate={() =>
                                                mutasiId && editRecord(mutasiId)
                                            }
                                        >
                                            <Select
                                                {...lokasi.selectProps}
                                                label="Lokasi tujuan"
                                                items={lokasi.options.map(
                                                    optionLabel,
                                                )}
                                                value={
                                                    lokasi.options
                                                        .filter(
                                                            (option) =>
                                                                option.id ===
                                                                record.tujuan_lokasi_id,
                                                        )
                                                        .map(optionLabel)[0] ??
                                                    null
                                                }
                                                placeholder="Tanpa lokasi"
                                                searchPlaceholder="Cari lokasi aset"
                                                emptyMessage="Lokasi aset tidak ditemukan."
                                                ariaLabel="Lokasi tujuan"
                                                portalContainer={lookupPortal}
                                                onValueChange={(item) => {
                                                    const dipilih =
                                                        lokasi.options.find(
                                                            (option) =>
                                                                optionLabel(
                                                                    option,
                                                                ) === item,
                                                        );
                                                    setRecord({
                                                        ...record,
                                                        tujuan_lokasi_id:
                                                            dipilih?.id ?? '',
                                                        tujuan_org_unit_id:
                                                            defaultDepartmentOf(
                                                                dipilih,
                                                            ) ??
                                                            record.tujuan_org_unit_id,
                                                    });
                                                }}
                                            />
                                        </EditShield>
                                        <FieldDescription>
                                            {lokasi.error ||
                                                'Seluruh aset pada berita acara ini pindah ke lokasi yang sama.'}
                                        </FieldDescription>
                                    </Field>

                                    <Field>
                                        <EditShield
                                            active={readOnly}
                                            label="unit kerja tujuan"
                                            onActivate={() =>
                                                mutasiId && editRecord(mutasiId)
                                            }
                                        >
                                            <Select
                                                {...unitKerja.selectProps}
                                                label="Unit kerja tujuan"
                                                required
                                                items={unitKerja.options.map(
                                                    optionLabel,
                                                )}
                                                value={
                                                    unitKerja.options
                                                        .filter(
                                                            (option) =>
                                                                option.id ===
                                                                unitTujuan,
                                                        )
                                                        .map(optionLabel)[0] ??
                                                    null
                                                }
                                                placeholder="Pilih unit kerja"
                                                searchPlaceholder="Cari unit kerja"
                                                emptyMessage="Unit kerja tidak ditemukan."
                                                ariaLabel="Unit kerja tujuan"
                                                portalContainer={lookupPortal}
                                                onValueChange={(item) =>
                                                    setRecord({
                                                        ...record,
                                                        tujuan_org_unit_id:
                                                            unitKerja.options.find(
                                                                (option) =>
                                                                    optionLabel(
                                                                        option,
                                                                    ) === item,
                                                            )?.id ?? '',
                                                    })
                                                }
                                            />
                                        </EditShield>
                                        <FieldDescription>
                                            {unitKerja.error ||
                                                (unitDariLokasi
                                                    ? 'Terisi sendiri dari unit kerja bawaan lokasi tujuan; masih dapat diganti.'
                                                    : 'Unit kerja yang menanggung aset setelah serah terima.')}
                                        </FieldDescription>
                                    </Field>

                                    <Field>
                                        <Input
                                            label="Alasan mutasi"
                                            required
                                            readOnly={readOnly}
                                            placeholder="Mis. pindah penugasan ke Divisi Implementor"
                                            value={record.alasan ?? ''}
                                            onChange={(event) =>
                                                setRecord({
                                                    ...record,
                                                    alasan: event.target.value,
                                                })
                                            }
                                        />
                                        {pesan('alasan') && (
                                            <FieldDescription>
                                                {pesan('alasan')}
                                            </FieldDescription>
                                        )}
                                    </Field>

                                    <Field>
                                        <EditShield
                                            active={readOnly}
                                            label="yang menyerahkan"
                                            onActivate={() =>
                                                mutasiId && editRecord(mutasiId)
                                            }
                                        >
                                            <Select
                                                {...anggota.selectProps}
                                                label="Diserahkan oleh"
                                                items={anggota.options.map(
                                                    optionLabel,
                                                )}
                                                value={
                                                    anggota.options
                                                        .filter(
                                                            (option) =>
                                                                option.id ===
                                                                record.diserahkan_oleh_user_id,
                                                        )
                                                        .map(optionLabel)[0] ??
                                                    null
                                                }
                                                placeholder="Pilih orang"
                                                searchPlaceholder="Cari nama"
                                                emptyMessage="Orang tidak ditemukan."
                                                ariaLabel="Diserahkan oleh"
                                                portalContainer={lookupPortal}
                                                onValueChange={(item) =>
                                                    setRecord({
                                                        ...record,
                                                        diserahkan_oleh_user_id:
                                                            anggota.options.find(
                                                                (option) =>
                                                                    optionLabel(
                                                                        option,
                                                                    ) === item,
                                                            )?.id ?? '',
                                                    })
                                                }
                                            />
                                        </EditShield>
                                        <FieldDescription>
                                            {anggota.error ||
                                                'Muncul pada blok tanda tangan kiri berita acara.'}
                                        </FieldDescription>
                                    </Field>

                                    <Field>
                                        <EditShield
                                            active={readOnly}
                                            label="yang menerima"
                                            onActivate={() =>
                                                mutasiId && editRecord(mutasiId)
                                            }
                                        >
                                            <Select
                                                {...anggota.selectProps}
                                                label="Diterima oleh"
                                                items={anggota.options.map(
                                                    optionLabel,
                                                )}
                                                value={
                                                    anggota.options
                                                        .filter(
                                                            (option) =>
                                                                option.id ===
                                                                record.diterima_oleh_user_id,
                                                        )
                                                        .map(optionLabel)[0] ??
                                                    null
                                                }
                                                placeholder="Pilih orang"
                                                searchPlaceholder="Cari nama"
                                                emptyMessage="Orang tidak ditemukan."
                                                ariaLabel="Diterima oleh"
                                                portalContainer={lookupPortal}
                                                onValueChange={(item) =>
                                                    setRecord({
                                                        ...record,
                                                        diterima_oleh_user_id:
                                                            anggota.options.find(
                                                                (option) =>
                                                                    optionLabel(
                                                                        option,
                                                                    ) === item,
                                                            )?.id ?? '',
                                                    })
                                                }
                                            />
                                        </EditShield>
                                        <FieldDescription>
                                            {anggota.error ||
                                                'Menjadi penanggung jawab aset setelah serah terima diselesaikan.'}
                                        </FieldDescription>
                                    </Field>
                                </div>
                            </div>
                        </CollapsibleSection>
                        <CollapsibleSection
                            value="lines"
                            title="Aset yang diserahkan"
                            summary={`${record.details.length} baris`}
                        >
                            <div className="space-y-4">
                                <div className="space-y-2">
                                    <div className="flex items-center justify-end">
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

                                                return {
                                                    baris,
                                                    index,
                                                    dipilih,
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
                                                cell: ({ index }) => (
                                                    <>{index + 1}</>
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
                                                }) => (
                                                    <>
                                                        {readOnly ? (
                                                            <span className="font-medium">
                                                                {baris.aset_kode ??
                                                                    dipilih?.kode ??
                                                                    '—'}
                                                                <span className="text-muted-foreground ml-2 font-normal">
                                                                    {baris.aset_nama ??
                                                                        dipilih?.nama}
                                                                </span>
                                                            </span>
                                                        ) : (
                                                            <Select
                                                                {...assetSource.selectProps}
                                                                items={aset.map(
                                                                    (item) => ({
                                                                        value: item.id,
                                                                        label: item.nama
                                                                            ? `${item.nama} — ${item.kode}`
                                                                            : item.kode,
                                                                    }),
                                                                )}
                                                                value={
                                                                    dipilih?.id ??
                                                                    null
                                                                }
                                                                placeholder="Pilih aset"
                                                                searchPlaceholder="Cari nama atau kode aset"
                                                                emptyMessage="Aset tidak ditemukan."
                                                                ariaLabel={`Aset baris ${index + 1}`}
                                                                portalContainer={
                                                                    lookupPortal
                                                                }
                                                                onValueChange={(
                                                                    item,
                                                                ) => {
                                                                    const dipilih =
                                                                        aset.find(
                                                                            (
                                                                                calon,
                                                                            ) =>
                                                                                calon.id ===
                                                                                item,
                                                                        );

                                                                    // Kondisi ikut terisi dari
                                                                    // kondisi aset yang tercatat
                                                                    // sekarang, dan tetap boleh
                                                                    // diubah: serah terima justru
                                                                    // momen barangnya diperiksa
                                                                    // ulang. Membiarkannya kosong
                                                                    // memaksa orang mengetik ulang
                                                                    // fakta yang sudah dimiliki
                                                                    // sistem, dan yang diketik
                                                                    // ulang bisa berbeda tanpa ada
                                                                    // yang benar-benar melihat
                                                                    // barangnya.
                                                                    ubahBaris(
                                                                        index,
                                                                        {
                                                                            aset_id:
                                                                                dipilih?.id ??
                                                                                '',
                                                                            kondisi_aset_id:
                                                                                dipilih?.kondisi_aset_id ??
                                                                                '',
                                                                        },
                                                                    );
                                                                }}
                                                            />
                                                        )}
                                                    </>
                                                ),
                                            } satisfies DataTableColumn<
                                                (typeof sdkRows)[number]
                                            >,
                                            {
                                                id: 'column-2',
                                                header: 'Lokasi asal',
                                                cell: ({ baris }) => (
                                                    <div className="text-muted-foreground">
                                                        <span className="block">
                                                            {baris.asal_lokasi_nama ??
                                                                'Belum ditempatkan'}
                                                        </span>
                                                        <span className="block text-xs">
                                                            {[
                                                                baris.asal_org_unit_nama,
                                                                baris.asal_custodian_nama,
                                                            ]
                                                                .filter(Boolean)
                                                                .join(' · ') ||
                                                                'Tanpa unit atau PIC'}
                                                        </span>
                                                    </div>
                                                ),
                                            } satisfies DataTableColumn<
                                                (typeof sdkRows)[number]
                                            >,
                                            {
                                                id: 'column-3',
                                                header: 'Kondisi',
                                                cell: ({ baris, index }) => (
                                                    <>
                                                        {readOnly ? (
                                                            (baris.kondisi_aset_nama ??
                                                            '—')
                                                        ) : (
                                                            <AssetConditionSelect
                                                                assetId={
                                                                    baris.aset_id
                                                                }
                                                                value={
                                                                    baris.kondisi_aset_id
                                                                }
                                                                label={`Kondisi baris ${index + 1}`}
                                                                portalContainer={
                                                                    lookupPortal
                                                                }
                                                                onChange={(
                                                                    value,
                                                                ) =>
                                                                    ubahBaris(
                                                                        index,
                                                                        {
                                                                            kondisi_aset_id:
                                                                                value,
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
                                            {
                                                id: 'column-4',
                                                header: 'Catatan',
                                                cell: ({ baris, index }) => (
                                                    <>
                                                        {readOnly ? (
                                                            baris.catatan || '—'
                                                        ) : (
                                                            <Input
                                                                aria-label={`Catatan baris ${index + 1}`}
                                                                value={
                                                                    baris.catatan ??
                                                                    ''
                                                                }
                                                                onChange={(
                                                                    event,
                                                                ) =>
                                                                    ubahBaris(
                                                                        index,
                                                                        {
                                                                            catatan:
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
                                                          id: 'column-5',
                                                          header: '',
                                                          width: 48,
                                                          cell: ({ index }) => (
                                                              <>
                                                                  <Button
                                                                      type="button"
                                                                      variant="ghost"
                                                                      size="icon"
                                                                      aria-label={`Hapus baris ${index + 1}`}
                                                                      disabled={
                                                                          record
                                                                              .details
                                                                              .length ===
                                                                          1
                                                                      }
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
                                                    baris.id ?? index
                                                }
                                            />
                                        );
                                    })()}
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
                                <Field>
                                    <Textarea
                                        label="Keterangan"
                                        rows={3}
                                        readOnly={readOnly}
                                        value={record.keterangan ?? ''}
                                        onChange={(event) =>
                                            setRecord({
                                                ...record,
                                                keterangan: event.target.value,
                                            })
                                        }
                                    />
                                </Field>
                            </div>
                        </CollapsibleSection>
                    </CollapsibleSectionGroup>
                    {selesai && (
                        <p className="text-muted-foreground text-sm">
                            Serah terima ini sudah diselesaikan pada{' '}
                            {tanggalTampil(tersimpan?.tanggal)}. Isinya tidak
                            dapat diubah lagi; buat mutasi balik bila perlu
                            dikoreksi.
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
                            Selesaikan serah terima?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {record.details.length} aset akan berpindah ke
                            lokasi dan unit tujuan, dan riwayat penempatannya
                            bertambah satu baris. Setelah ini dokumen tidak
                            dapat diubah lagi — koreksi hanya bisa dilakukan
                            dengan membuat mutasi balik.
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
                            dipakai ulang. Aset tidak berpindah ke mana pun
                            karena serah terimanya memang belum pernah
                            diselesaikan.
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
