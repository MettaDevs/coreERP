import { FileText, Plus, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
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
import EditShield from '../../_shared/EditShield';
import { ApiError, api, errorMessage, newIdempotencyKey } from '../../api';
import { optionLabel, useMasterOptions } from '../../master/useMasterOptions';
import { requestPrint } from '../../print';
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
    mutasiId,
    mode: modeAwal,
}: {
    context: Context;
    permissions: string[];
    mutasiId?: string;
    mode: Mode;
}) {
    const can = izin(permissions);
    const canMutate = bolehMemindahkan(permissions);
    const [record, setRecord] = useState<EditableMutasi>(emptyMutasi);
    const [tersimpan, setTersimpan] = useState<Mutasi | null>(null);
    const [memuat, setMemuat] = useState(modeAwal !== 'create');
    const [menyimpan, setMenyimpan] = useState(false);
    const [galat, setGalat] = useState<Record<string, string[]>>({});
    const [konfirmasiSelesai, setKonfirmasiSelesai] = useState(false);
    const [konfirmasiArsip, setKonfirmasiArsip] = useState(false);
    const [aset, setAset] = useState<Aset[]>([]);
    const lokasi = useMasterOptions('lokasi-aset');
    const kondisi = useMasterOptions('kondisi-aset');
    // Unit kerja dan orang milik Core. Keduanya dibaca lewat endpoint referensi module,
    // bukan diketik sebagai ULID: tidak ada pengguna yang mengenali rekannya dari ULID.
    const unitKerja = useMasterOptions('reference-data/unit-kerja');
    const anggota = useMasterOptions('reference-data/anggota');
    const panelRef = useRef<HTMLDivElement>(null);

    const mode = modeAwal;
    const readOnly = mode === 'view';
    const selesai = tersimpan?.status === 'selesai';

    useEffect(() => {
        api<{ data: Aset[] }>('/aset')
            .then((result) => setAset(result.data))
            .catch((caught) =>
                toast.error(errorMessage(caught, 'Aset belum dapat dimuat.')),
            );
    }, []);

    useEffect(() => {
        if (!mutasiId) {
            return;
        }

        let dibatalkan = false;
        api<{ data: Mutasi }>(`/mutasi-aset/${mutasiId}`)
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
            .catch((caught) =>
                toast.error(errorMessage(caught, 'Mutasi belum dapat dimuat.')),
            )
            .finally(() => {
                if (!dibatalkan) {
                    setMemuat(false);
                }
            });

        return () => {
            dibatalkan = true;
        };
    }, [mutasiId]);

    // Unit tujuan mengikuti unit yang dipetakan pada lokasi tujuan bila ada — padanan
    // toggle "Update asset dimension" pada functional location type di F&O — dan jatuh
    // kembali ke unit kerja aktif bila lokasinya belum dipetakan.
    const unitDariLokasi = useMemo(() => {
        const dipilih = lokasi.options.find(
            (option) => option.id === record.tujuan_lokasi_id,
        );

        return typeof dipilih?.org_unit_id === 'string'
            ? dipilih.org_unit_id
            : null;
    }, [lokasi.options, record.tujuan_lokasi_id]);

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
                'Pilih entitas legal aktif terlebih dahulu pada header CoreERP.',
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
                bukaMutasi(hasil.data.id);

                return;
            }

            await api<{ data: Mutasi }>(`/mutasi-aset/${mutasiId}`, {
                method: 'PATCH',
                body: JSON.stringify({
                    ...payload,
                    version: tersimpan?.version,
                }),
            });
            toast.success('Perubahan mutasi disimpan.');
            bukaMutasi(String(mutasiId));
        } catch (caught) {
            if (caught instanceof ApiError) {
                setGalat(caught.validationErrors);
            }

            toast.error(errorMessage(caught, 'Mutasi belum dapat disimpan.'));
        } finally {
            setMenyimpan(false);
        }
    }

    async function selesaikan() {
        setKonfirmasiSelesai(false);
        setMenyimpan(true);

        try {
            await api(`/mutasi-aset/${mutasiId}/selesaikan`, {
                method: 'POST',
                body: JSON.stringify({ version: tersimpan?.version }),
            });
            toast.success(
                'Serah terima diselesaikan. Penempatan aset sudah berpindah.',
            );
            bukaMutasi(String(mutasiId));
        } catch (caught) {
            if (caught instanceof ApiError) {
                setGalat(caught.validationErrors);
            }

            toast.error(
                errorMessage(caught, 'Mutasi belum dapat diselesaikan.'),
            );
        } finally {
            setMenyimpan(false);
        }
    }

    async function arsipkan() {
        setKonfirmasiArsip(false);

        try {
            await api(`/mutasi-aset/${mutasiId}`, {
                method: 'DELETE',
                body: JSON.stringify({ version: tersimpan?.version }),
            });
            toast.success('Draf mutasi diarsipkan.');
            bukaDaftar();
        } catch (caught) {
            toast.error(errorMessage(caught, 'Mutasi belum dapat diarsipkan.'));
        }
    }

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
            <RecordActionBar
                title={judul}
                trailing={
                    tersimpan ? (
                        <StatusBadge status={tersimpan.status} />
                    ) : undefined
                }
            >
                {mode === 'view' && !selesai && can('update') && (
                    <ActionButton
                        action="edit"
                        type="button"
                        onClick={() => bukaMutasiUbah(String(mutasiId))}
                    >
                        Ubah
                    </ActionButton>
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
                {mode === 'view' && !selesai && can('archive') && (
                    <ActionButton
                        action="archive"
                        type="button"
                        onClick={() => setKonfirmasiArsip(true)}
                    >
                        Arsipkan
                    </ActionButton>
                )}
                {mode !== 'view' && (
                    <>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() =>
                                mutasiId ? bukaMutasi(mutasiId) : bukaDaftar()
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
                    <p className="text-muted-foreground text-sm">
                        Entitas legal dan unit kerja pembuat dokumen mengikuti
                        konteks aktif Anda. Lokasi asal tiap aset diambil dari
                        keadaan aset itu sekarang, jadi tidak perlu diisi.
                    </p>

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
                                    mutasiId && bukaMutasiUbah(mutasiId)
                                }
                            >
                                <Select
                                    label="Lokasi tujuan"
                                    items={lokasi.options.map(optionLabel)}
                                    value={
                                        lokasi.options
                                            .filter(
                                                (option) =>
                                                    option.id ===
                                                    record.tujuan_lokasi_id,
                                            )
                                            .map(optionLabel)[0] ?? null
                                    }
                                    placeholder="Tanpa lokasi"
                                    searchPlaceholder="Cari lokasi aset"
                                    emptyMessage="Lokasi aset tidak ditemukan."
                                    ariaLabel="Lokasi tujuan"
                                    portalContainer={panelRef}
                                    onValueChange={(item) =>
                                        setRecord({
                                            ...record,
                                            tujuan_lokasi_id:
                                                lokasi.options.find(
                                                    (option) =>
                                                        optionLabel(option) ===
                                                        item,
                                                )?.id ?? '',
                                        })
                                    }
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
                                    mutasiId && bukaMutasiUbah(mutasiId)
                                }
                            >
                                <Select
                                    label="Unit kerja tujuan"
                                    required
                                    items={unitKerja.options.map(optionLabel)}
                                    value={
                                        unitKerja.options
                                            .filter(
                                                (option) =>
                                                    option.id === unitTujuan,
                                            )
                                            .map(optionLabel)[0] ?? null
                                    }
                                    placeholder="Pilih unit kerja"
                                    searchPlaceholder="Cari unit kerja"
                                    emptyMessage="Unit kerja tidak ditemukan."
                                    ariaLabel="Unit kerja tujuan"
                                    portalContainer={panelRef}
                                    onValueChange={(item) =>
                                        setRecord({
                                            ...record,
                                            tujuan_org_unit_id:
                                                unitKerja.options.find(
                                                    (option) =>
                                                        optionLabel(option) ===
                                                        item,
                                                )?.id ?? '',
                                        })
                                    }
                                />
                            </EditShield>
                            <FieldDescription>
                                {unitKerja.error ||
                                    (unitDariLokasi
                                        ? 'Terisi sendiri dari unit yang dipetakan pada lokasi tujuan; masih dapat diganti.'
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
                                    mutasiId && bukaMutasiUbah(mutasiId)
                                }
                            >
                                <Select
                                    label="Diserahkan oleh"
                                    items={anggota.options.map(optionLabel)}
                                    value={
                                        anggota.options
                                            .filter(
                                                (option) =>
                                                    option.id ===
                                                    record.diserahkan_oleh_user_id,
                                            )
                                            .map(optionLabel)[0] ?? null
                                    }
                                    placeholder="Pilih orang"
                                    searchPlaceholder="Cari nama"
                                    emptyMessage="Orang tidak ditemukan."
                                    ariaLabel="Diserahkan oleh"
                                    portalContainer={panelRef}
                                    onValueChange={(item) =>
                                        setRecord({
                                            ...record,
                                            diserahkan_oleh_user_id:
                                                anggota.options.find(
                                                    (option) =>
                                                        optionLabel(option) ===
                                                        item,
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
                                    mutasiId && bukaMutasiUbah(mutasiId)
                                }
                            >
                                <Select
                                    label="Diterima oleh"
                                    items={anggota.options.map(optionLabel)}
                                    value={
                                        anggota.options
                                            .filter(
                                                (option) =>
                                                    option.id ===
                                                    record.diterima_oleh_user_id,
                                            )
                                            .map(optionLabel)[0] ?? null
                                    }
                                    placeholder="Pilih orang"
                                    searchPlaceholder="Cari nama"
                                    emptyMessage="Orang tidak ditemukan."
                                    ariaLabel="Diterima oleh"
                                    portalContainer={panelRef}
                                    onValueChange={(item) =>
                                        setRecord({
                                            ...record,
                                            diterima_oleh_user_id:
                                                anggota.options.find(
                                                    (option) =>
                                                        optionLabel(option) ===
                                                        item,
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

                    <div className="space-y-2">
                        <div className="flex items-center justify-between">
                            <h2 className="text-base font-medium">
                                Aset yang diserahkan
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
                                    Tambah baris
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
                                    <TableHead>Lokasi asal</TableHead>
                                    <TableHead>Kondisi</TableHead>
                                    <TableHead>Catatan</TableHead>
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

                                    return (
                                        <TableRow key={baris.id ?? index}>
                                            <TableCell>{index + 1}</TableCell>
                                            <TableCell>
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
                                                        items={aset.map(
                                                            (item) => item.kode,
                                                        )}
                                                        value={
                                                            dipilih?.kode ??
                                                            null
                                                        }
                                                        placeholder="Pilih aset"
                                                        searchPlaceholder="Cari kode aset"
                                                        emptyMessage="Aset tidak ditemukan."
                                                        ariaLabel={`Aset baris ${index + 1}`}
                                                        portalContainer={
                                                            panelRef
                                                        }
                                                        onValueChange={(
                                                            item,
                                                        ) => {
                                                            const dipilih =
                                                                aset.find(
                                                                    (calon) =>
                                                                        calon.kode ===
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
                                                            ubahBaris(index, {
                                                                aset_id:
                                                                    dipilih?.id ??
                                                                    '',
                                                                kondisi_aset_id:
                                                                    dipilih?.kondisi_aset_id ??
                                                                    '',
                                                            });
                                                        }}
                                                    />
                                                )}
                                            </TableCell>
                                            {/* Diturunkan, tidak pernah diketik. */}
                                            <TableCell className="text-muted-foreground">
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
                                            </TableCell>
                                            <TableCell>
                                                {readOnly ? (
                                                    (baris.kondisi_aset_nama ??
                                                    '—')
                                                ) : (
                                                    <Select
                                                        items={kondisi.options.map(
                                                            optionLabel,
                                                        )}
                                                        value={
                                                            kondisi.options
                                                                .filter(
                                                                    (option) =>
                                                                        option.id ===
                                                                        baris.kondisi_aset_id,
                                                                )
                                                                .map(
                                                                    optionLabel,
                                                                )[0] ?? null
                                                        }
                                                        placeholder="Tidak dicatat"
                                                        searchPlaceholder="Cari kondisi"
                                                        emptyMessage="Kondisi tidak ditemukan."
                                                        ariaLabel={`Kondisi baris ${index + 1}`}
                                                        portalContainer={
                                                            panelRef
                                                        }
                                                        onValueChange={(item) =>
                                                            ubahBaris(index, {
                                                                kondisi_aset_id:
                                                                    kondisi.options.find(
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
                                            <TableCell>
                                                {readOnly ? (
                                                    baris.catatan || '—'
                                                ) : (
                                                    <Input
                                                        aria-label={`Catatan baris ${index + 1}`}
                                                        value={
                                                            baris.catatan ?? ''
                                                        }
                                                        onChange={(event) =>
                                                            ubahBaris(index, {
                                                                catatan:
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
                                                        aria-label={`Hapus baris ${index + 1}`}
                                                        disabled={
                                                            record.details
                                                                .length === 1
                                                        }
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
                    </div>

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
                        <AlertDialogTitle>Arsipkan draf ini?</AlertDialogTitle>
                        <AlertDialogDescription>
                            Draf disembunyikan dari daftar dan nomornya tidak
                            dipakai ulang. Aset tidak berpindah ke mana pun
                            karena serah terimanya memang belum pernah
                            diselesaikan.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Batal</AlertDialogCancel>
                        <AlertDialogAction onClick={() => void arsipkan()}>
                            Arsipkan
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
}
