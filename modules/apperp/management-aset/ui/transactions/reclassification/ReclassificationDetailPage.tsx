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
import { PostingPreviewPanel } from '../_shared/PostingPreviewPanel';
import type { PostingPreview } from '../_shared/PostingPreviewPanel';
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
    reclassificationId,
    mode,
}: {
    context: Context;
    permissions: string[];
    reclassificationId?: string;
    mode: Mode;
}) {
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
    const [aset, setAset] = useState<AsetPilihan[]>([]);
    const [pratinjau, setPratinjau] = useState<
        (PostingPreview & { moves: Move[] }) | null
    >(null);
    const [konfirmasi, setKonfirmasi] = useState<'posting' | 'arsip' | null>(
        null,
    );
    const grup = useMasterOptions('group-aset');
    const unitKerja = useMasterOptions('reference-data/unit-kerja');
    const panelRef = useRef<HTMLDivElement>(null);

    const readOnly = mode === 'view';
    const draf = mode === 'create' || tersimpan?.status === 'draft';
    const pecah = record.jenis === 'pecah';

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
        if (!reclassificationId) {
            return;
        }

        let dibatalkan = false;
        api<{ data: Reclassification }>(
            `/reklasifikasi-aset/${reclassificationId}`,
        )
            .then((result) => {
                if (!dibatalkan) {
                    setTersimpan(result.data);
                    setRecord(keForm(result.data));
                }
            })
            .catch((caught) =>
                toast.error(
                    errorMessage(caught, 'Reklasifikasi belum dapat dimuat.'),
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
            tanggal: record.tanggal,
            keterangan: record.keterangan,
            details: record.details
                .filter((baris) => baris.aset_id)
                .map((baris) => ({
                    id: baris.id ?? null,
                    aset_id: baris.aset_id,
                    group_aset_tujuan_id: baris.group_aset_tujuan_id || null,
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
                toast.success(
                    `Reklasifikasi ${hasil.data.kode} disimpan sebagai draf.`,
                );
                bukaReklasifikasi(hasil.data.id);

                return;
            }

            await api(`/reklasifikasi-aset/${reclassificationId}`, {
                method: 'PATCH',
                body: JSON.stringify({
                    ...payload,
                    version: tersimpan?.version,
                }),
            });
            toast.success('Perubahan reklasifikasi disimpan.');
            bukaReklasifikasi(String(reclassificationId));
        } catch (caught) {
            if (caught instanceof ApiError) {
                setGalat(caught.validationErrors);
            }

            toastSaveError(caught, 'Reklasifikasi belum dapat disimpan.');
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
            toast.success(
                `Nilai aset dipindahkan. Jurnal: ${postingLabel(hasil.data.posting?.status)}.`,
            );
        } catch (caught) {
            if (caught instanceof ApiError) {
                setGalat(caught.validationErrors);
            }

            toastSaveError(caught, 'Reklasifikasi belum dapat diposting.');
        } finally {
            setMenyimpan(false);
        }
    }

    async function arsipkan() {
        setKonfirmasi(null);

        try {
            await api(`/reklasifikasi-aset/${reclassificationId}`, {
                method: 'DELETE',
                body: JSON.stringify({ version: tersimpan?.version }),
            });
            toast.success('Draf reklasifikasi diarsipkan.');
            bukaDaftar();
        } catch (caught) {
            toastSaveError(caught, 'Reklasifikasi belum dapat diarsipkan.');
        }
    }

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
    const pilihDari = (
        options: { id: string }[],
        id: string | null | undefined,
    ) => {
        const dipilih = options.find((option) => option.id === id);

        return dipilih ? optionLabel(dipilih as never) : null;
    };
    const masalahBaris = (index: number) =>
        pesan(`details.${index}.aset_id`) ??
        pesan(`details.${index}.group_aset_tujuan_id`) ??
        pesan(`details.${index}.persen`) ??
        pesan(`details.${index}.nilai_perolehan`);
    const grupItems = grup.options.map(optionLabel);

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
                            reclassificationId &&
                            bukaReklasifikasiUbah(reclassificationId)
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
                                reclassificationId
                                    ? bukaReklasifikasi(reclassificationId)
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
            </RecordActionBar>

            <div ref={panelRef} className="min-h-0 flex-1 overflow-y-auto">
                <div className="space-y-5 p-5">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field>
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
                                    items={KINDS.map((kind) => kind.label)}
                                    value={kindLabel(record.jenis)}
                                    ariaLabel="Jenis reklasifikasi"
                                    portalContainer={panelRef}
                                    onValueChange={(value) =>
                                        setRecord({
                                            ...record,
                                            jenis:
                                                KINDS.find(
                                                    (kind) =>
                                                        kind.label === value,
                                                )?.value ?? 'pindah_group',
                                            details: [emptyLine()],
                                        })
                                    }
                                />
                            )}
                            <FieldDescription>
                                {pesan('jenis') ||
                                    (pecah
                                        ? 'Sebagian nilai aset dipindah ke aset baru yang lahir saat diposting.'
                                        : 'Aset yang sama pindah seluruhnya ke group aset lain.')}
                            </FieldDescription>
                        </Field>
                        <Field>
                            <Input
                                label="Tanggal reklasifikasi"
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
                                'Ikut ke keterangan jurnal di aplikasi finance, misalnya salah klasifikasi saat penerimaan.'}
                        </FieldDescription>
                    </Field>

                    <div className="space-y-2">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h2 className="text-base font-medium">
                                {pecah
                                    ? 'Aset yang dipecah'
                                    : 'Aset yang pindah'}
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
                                    <TableHead>Group tujuan</TableHead>
                                    {pecah && (
                                        <>
                                            <TableHead className="text-right">
                                                Persen
                                            </TableHead>
                                            <TableHead className="text-right">
                                                Atau nilai perolehan
                                            </TableHead>
                                            <TableHead>Aset baru</TableHead>
                                        </>
                                    )}
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
                                            </TableCell>
                                            <TableCell>
                                                {readOnly ? (
                                                    (baris.group_aset_tujuan_nama ??
                                                    (pecah ? GROUP_ASAL : '—'))
                                                ) : (
                                                    <Select
                                                        items={
                                                            pecah
                                                                ? [
                                                                      GROUP_ASAL,
                                                                      ...grupItems,
                                                                  ]
                                                                : grupItems
                                                        }
                                                        value={
                                                            pilihDari(
                                                                grup.options,
                                                                baris.group_aset_tujuan_id,
                                                            ) ??
                                                            (pecah
                                                                ? GROUP_ASAL
                                                                : null)
                                                        }
                                                        placeholder="Pilih group tujuan"
                                                        searchPlaceholder="Cari group aset"
                                                        emptyMessage="Group aset tidak ditemukan."
                                                        ariaLabel={`Group tujuan baris ${index + 1}`}
                                                        portalContainer={
                                                            panelRef
                                                        }
                                                        onValueChange={(item) =>
                                                            ubahBaris(index, {
                                                                group_aset_tujuan_id:
                                                                    grup.options.find(
                                                                        (
                                                                            option,
                                                                        ) =>
                                                                            optionLabel(
                                                                                option,
                                                                            ) ===
                                                                            item,
                                                                    )?.id ?? '',
                                                            })
                                                        }
                                                    />
                                                )}
                                            </TableCell>
                                            {pecah && (
                                                <>
                                                    <TableCell className="text-right">
                                                        {readOnly ? (
                                                            baris.persen ? (
                                                                `${baris.persen}%`
                                                            ) : (
                                                                '—'
                                                            )
                                                        ) : (
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
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        {readOnly ? (
                                                            uang(
                                                                baris.nilai_perolehan_dipindah ??
                                                                    baris.nilai_perolehan,
                                                            )
                                                        ) : (
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
                                                        )}
                                                    </TableCell>
                                                    <TableCell>
                                                        {readOnly ? (
                                                            (baris.aset_baru_kode ??
                                                            (baris.nama_aset_baru ||
                                                                '—'))
                                                        ) : (
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
                                                        )}
                                                    </TableCell>
                                                </>
                                            )}
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
                        {!readOnly && pecah && (
                            <p className="text-muted-foreground text-sm">
                                Isi persen atau nilai perolehan, salah satu.
                                Akumulasi penyusutan, penurunan nilai, dan
                                kenaikan nilai setiap buku ikut dipindah dengan
                                perbandingan yang sama, beserta umur yang sudah
                                berjalan.
                            </p>
                        )}
                    </div>

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
                            {pratinjau.moves.length > 0 && (
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Aset</TableHead>
                                            <TableHead>Buku</TableHead>
                                            <TableHead className="text-right">
                                                Harga perolehan
                                            </TableHead>
                                            <TableHead className="text-right">
                                                Akumulasi penyusutan
                                            </TableHead>
                                            <TableHead className="text-right">
                                                Penurunan nilai
                                            </TableHead>
                                            <TableHead className="text-right">
                                                Kenaikan nilai
                                            </TableHead>
                                            <TableHead className="text-right">
                                                Nilai buku dipindah
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {pratinjau.moves.flatMap((pindah) =>
                                            pindah.books.map((buku) => (
                                                <TableRow
                                                    key={`${pindah.line_number}-${buku.book_code}`}
                                                >
                                                    <TableCell>
                                                        {pindah.aset_kode}
                                                    </TableCell>
                                                    <TableCell>
                                                        {buku.book_code}
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        {uang(
                                                            buku.nilai_perolehan,
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        {uang(
                                                            buku.akumulasi_penyusutan,
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        {uang(
                                                            buku.penurunan_nilai,
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        {uang(
                                                            buku.kenaikan_nilai,
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        {uang(buku.nilai_buku)}
                                                    </TableCell>
                                                </TableRow>
                                            )),
                                        )}
                                    </TableBody>
                                </Table>
                            )}
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
                            {konfirmasi === 'arsip' ? 'Arsipkan' : 'Posting'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
}
