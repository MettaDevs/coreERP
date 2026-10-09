import { Eye, Plus, Send, Trash2 } from 'lucide-react';
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
import { Field, FieldDescription } from '@apperp/ui/field';
import { Input } from '@apperp/ui/input';
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { Select } from '@apperp/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@apperp/ui/table';
import { Textarea } from '@apperp/ui/textarea';
import {
    ApiError,
    api,
    errorMessage,
    newIdempotencyKey,
    toastSaveError,
} from '../../api';
import { optionLabel, useMasterOptions } from '../../master/useMasterOptions';
import { CancellationAction } from '../_shared/CancellationAction';
import { CancellationStatus } from '../_shared/CancellationStatus';
import { PostingPreviewPanel } from '../_shared/PostingPreviewPanel';
import type { PostingPreview } from '../_shared/PostingPreviewPanel';
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
    adjustmentId,
    mode,
}: {
    context: Context;
    permissions: string[];
    adjustmentId?: string;
    mode: Mode;
}) {
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
    const [aset, setAset] = useState<AsetPilihan[]>([]);
    const [pratinjau, setPratinjau] = useState<PostingPreview | null>(null);
    const [konfirmasi, setKonfirmasi] = useState<'posting' | 'arsip' | null>(
        null,
    );
    const buku = useMasterOptions('buku-penyusutan');
    const unitKerja = useMasterOptions('reference-data/unit-kerja');
    const panelRef = useRef<HTMLDivElement>(null);

    const readOnly = mode === 'view';
    const draf = mode === 'create' || tersimpan?.status === 'draft';

    // Daftar aset hanya dibutuhkan untuk menambah baris; aset yang sudah dilepas tidak ditawarkan.
    useEffect(() => {
        if (readOnly) {
            return;
        }

        api<{ data: AsetPilihan[] }>('/aset')
            .then((result) =>
                setAset(
                    result.data.filter(
                        (item) => item.lifecycle_state !== 'disposed',
                    ),
                ),
            )
            .catch(() =>
                toast.error(
                    'Daftar aset tidak dapat dimuat, jadi aset belum bisa ditambahkan.',
                ),
            );
    }, [readOnly]);

    useEffect(() => {
        if (!adjustmentId) {
            return;
        }

        let dibatalkan = false;
        api<{ data: Adjustment }>(`/penyesuaian-nilai-aset/${adjustmentId}`)
            .then((result) => {
                if (!dibatalkan) {
                    setTersimpan(result.data);
                    setRecord(keForm(result.data));
                }
            })
            .catch((caught) =>
                toast.error(
                    errorMessage(caught, 'Penyesuaian belum dapat dimuat.'),
                ),
            )
            .finally(() => {
                if (!dibatalkan) {
                    setMemuat(false);
                }
            });

        return () => {
            dibatalkan = true;
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
                'Pilih entitas legal aktif terlebih dahulu pada header CoreERP.',
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
                bukaPenyesuaian(hasil.data.id);

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
            bukaPenyesuaian(String(adjustmentId));
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

        try {
            await api(`/penyesuaian-nilai-aset/${adjustmentId}`, {
                method: 'DELETE',
                body: JSON.stringify({ version: tersimpan?.version }),
            });
            toast.success('Draf penyesuaian diarsipkan.');
            bukaDaftar();
        } catch (caught) {
            toastSaveError(caught, 'Penyesuaian belum dapat diarsipkan.');
        }
    }

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
            <RecordActionBar
                title={judul}
                trailing={
                    tersimpan ? (
                        <StatusBadge status={tersimpan.status} />
                    ) : undefined
                }
            >
                {readOnly && draf && can('update') && (
                    <ActionButton
                        action="edit"
                        type="button"
                        onClick={() =>
                            adjustmentId && bukaPenyesuaianUbah(adjustmentId)
                        }
                    >
                        Ubah
                    </ActionButton>
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
                {readOnly && draf && can('archive') && (
                    <ActionButton
                        action="archive"
                        type="button"
                        onClick={() => setKonfirmasi('arsip')}
                    >
                        Arsipkan
                    </ActionButton>
                )}
                {!readOnly && (
                    <>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() =>
                                adjustmentId
                                    ? bukaPenyesuaian(adjustmentId)
                                    : bukaDaftar()
                            }
                        >
                            Batal
                        </Button>
                        <Button
                            type="button"
                            onClick={() => void simpan()}
                            disabled={menyimpan}
                        >
                            {menyimpan ? 'Menyimpan…' : 'Simpan draf'}
                        </Button>
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
                            onComplete={() => setReload((value) => value + 1)}
                        />
                    )}
            </RecordActionBar>
            <CancellationStatus summary={tersimpan?.cancellation} />

            <div ref={panelRef} className="min-h-0 flex-1 overflow-y-auto">
                <div className="space-y-5 p-5">
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
                                    items={KINDS.map((kind) => kind.label)}
                                    value={kindLabel(record.jenis)}
                                    ariaLabel="Jenis penyesuaian"
                                    portalContainer={panelRef}
                                    onValueChange={(value) =>
                                        setRecord({
                                            ...record,
                                            jenis:
                                                KINDS.find(
                                                    (kind) =>
                                                        kind.label === value,
                                                )?.value ?? 'write_down',
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
                                    value={tersimpan?.buku_nama ?? '—'}
                                />
                            ) : (
                                <Select
                                    label="Buku penyusutan"
                                    required
                                    items={buku.options.map(optionLabel)}
                                    value={pilihDari(
                                        buku.options,
                                        record.buku_id,
                                    )}
                                    placeholder="Pilih buku yang nilainya disesuaikan"
                                    searchPlaceholder="Cari buku penyusutan"
                                    emptyMessage="Buku penyusutan tidak ditemukan."
                                    ariaLabel="Buku penyusutan"
                                    portalContainer={panelRef}
                                    onValueChange={(item) =>
                                        setRecord({
                                            ...record,
                                            buku_id:
                                                buku.options.find(
                                                    (option) =>
                                                        optionLabel(option) ===
                                                        item,
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
                                    label="Unit penanggung jawab"
                                    required
                                    items={unitKerja.options.map(optionLabel)}
                                    value={pilihDari(
                                        unitKerja.options,
                                        record.responsible_org_unit_id,
                                    )}
                                    placeholder="Pilih unit kerja"
                                    searchPlaceholder="Cari unit kerja"
                                    emptyMessage="Unit kerja tidak ditemukan."
                                    ariaLabel="Unit penanggung jawab"
                                    portalContainer={panelRef}
                                    onValueChange={(item) =>
                                        setRecord({
                                            ...record,
                                            responsible_org_unit_id:
                                                unitKerja.options.find(
                                                    (option) =>
                                                        optionLabel(option) ===
                                                        item,
                                                )?.id ?? '',
                                        })
                                    }
                                />
                            )}
                            {pesan('responsible_org_unit_id') && (
                                <FieldDescription>
                                    {pesan('responsible_org_unit_id')}
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

                    <div className="space-y-2">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h2 className="text-base font-medium">
                                Aset yang disesuaikan
                            </h2>
                            {!readOnly && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        setRecord({
                                            ...record,
                                            details: [
                                                ...record.details,
                                                emptyLine(),
                                            ],
                                        })
                                    }
                                >
                                    <Plus />
                                    Tambah aset
                                </Button>
                            )}
                        </div>
                        {pesan('details') && (
                            <p className="text-destructive text-sm">
                                {pesan('details')}
                            </p>
                        )}
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-12">No</TableHead>
                                    <TableHead>Aset</TableHead>
                                    <TableHead className="text-right">
                                        Nilai penyesuaian
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Nilai buku sebelum
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Nilai buku sesudah
                                    </TableHead>
                                    <TableHead>Keterangan</TableHead>
                                    {!readOnly && (
                                        <TableHead className="w-12" />
                                    )}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {record.details.map((baris, index) => {
                                    const dipilih = aset.find(
                                        (item) => item.id === baris.aset_id,
                                    );
                                    const masalah = masalahBaris(index);

                                    return (
                                        <TableRow
                                            key={baris.id ?? `baru-${index}`}
                                        >
                                            <TableCell>
                                                {baris.line_number ?? index + 1}
                                            </TableCell>
                                            <TableCell>
                                                {!baris.id && !readOnly ? (
                                                    <Select
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
                                                            panelRef
                                                        }
                                                        onValueChange={(item) =>
                                                            ubahBaris(index, {
                                                                aset_id:
                                                                    aset.find(
                                                                        (
                                                                            calon,
                                                                        ) =>
                                                                            labelAset(
                                                                                calon,
                                                                            ) ===
                                                                            item,
                                                                    )?.id ?? '',
                                                            })
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
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {readOnly ? (
                                                    uang(baris.nilai)
                                                ) : (
                                                    <Input
                                                        aria-label={`Nilai penyesuaian baris ${index + 1}`}
                                                        type="number"
                                                        min="0"
                                                        step="0.01"
                                                        value={baris.nilai}
                                                        onChange={(event) =>
                                                            ubahBaris(index, {
                                                                nilai: event
                                                                    .target
                                                                    .value,
                                                            })
                                                        }
                                                    />
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {uang(baris.nilai_buku_sebelum)}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {uang(baris.nilai_buku_sesudah)}
                                            </TableCell>
                                            <TableCell>
                                                {readOnly ? (
                                                    baris.keterangan || '—'
                                                ) : (
                                                    <Input
                                                        aria-label={`Keterangan baris ${index + 1}`}
                                                        value={baris.keterangan}
                                                        onChange={(event) =>
                                                            ubahBaris(index, {
                                                                keterangan:
                                                                    event.target
                                                                        .value,
                                                            })
                                                        }
                                                    />
                                                )}
                                            </TableCell>
                                            {!readOnly && (
                                                <TableCell>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon"
                                                        aria-label={`Keluarkan baris ${index + 1}`}
                                                        onClick={() =>
                                                            setRecord({
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
                                                            })
                                                        }
                                                    >
                                                        <Trash2 />
                                                    </Button>
                                                </TableCell>
                                            )}
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </Table>
                        {readOnly && draf && (
                            <p className="text-muted-foreground text-sm">
                                Nilai buku sebelum adalah nilai buku saat ini di
                                buku {tersimpan?.buku_nama ?? 'ini'}; angka
                                pastinya dikunci saat diposting.
                            </p>
                        )}
                    </div>

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
                                ? 'Arsipkan draf ini?'
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
                            {konfirmasi === 'arsip' ? 'Arsipkan' : 'Posting'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
}
