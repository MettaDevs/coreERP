import { Plus, Trash2, TriangleAlert } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
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
import {
    CollapsibleSection,
    CollapsibleSectionGroup,
} from '@apperp/ui/collapsible-section';
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
import type { MasterOption } from '../../master/useMasterOptions';
import { optionLabel, useMasterOptions } from '../../master/useMasterOptions';
import type {
    AsetTerbit,
    BarisPenerimaan,
    Context,
    EditablePenerimaan,
    Penerimaan,
    Ringkasan,
} from './penerimaan';
import {
    StatusBadge,
    barisKosong,
    bolehMendaftarkan,
    bolehMengoreksiAset,
    bukaPenerimaan,
    bukaPenerimaanDaftar,
    bukaPenerimaanUbah,
    izin,
    penerimaanKosong,
    tanggalTampil,
} from './penerimaan';

type Mode = 'create' | 'view' | 'edit';

/**
 * Rincian satu dokumen penerimaan aset.
 *
 * **Kenapa kepala dan baris dipisah begini.** Yang berlaku untuk seluruh kedatangan —
 * tanggal, lokasi awal, unit pengguna, penanggung jawab — ada di kepala, karena satu
 * dokumen adalah satu kedatangan. Yang membedakan barangnya ada di baris, dan `jumlah`
 * yang membuat satu baris menjadi banyak aset.
 *
 * **Nomor seri tidak diminta di sini.** Kardusnya belum dibuka saat dokumen diketik.
 * Setelah dokumen selesai, tab "Aset terbit" mendaftar aset yang lahir darinya supaya
 * nomor serinya diketik berurutan sambil membaca stikernya.
 */
export default function PenerimaanDetailPage({
    context,
    permissions,
    penerimaanId,
    mode,
}: {
    context: Context;
    permissions: string[];
    penerimaanId?: string;
    mode: Mode;
}) {
    const can = izin(permissions);
    const canRegister = bolehMendaftarkan(permissions);
    // Nomor seri mengubah aset, bukan dokumen, jadi izinnya izin koreksi aset. Dokumen
    // yang sudah selesai memang tidak dapat disunting — dan ini bukan pengecualiannya,
    // karena nomor seri tidak pernah menjadi bagian dokumen.
    const canCorrect = bolehMengoreksiAset(permissions);
    const [record, setRecord] = useState<EditablePenerimaan>(() =>
        penerimaanKosong(context),
    );
    const [tersimpan, setTersimpan] = useState<Penerimaan | null>(null);
    const [ringkasan, setRingkasan] = useState<Ringkasan | null>(null);
    const [terbit, setTerbit] = useState<AsetTerbit[]>([]);
    const [memuat, setMemuat] = useState(mode !== 'create');
    const [menyimpan, setMenyimpan] = useState(false);
    const [galat, setGalat] = useState<Record<string, string[]>>({});
    const [konfirmasiSelesai, setKonfirmasiSelesai] = useState(false);
    const [konfirmasiArsip, setKonfirmasiArsip] = useState(false);

    const lokasi = useMasterOptions('lokasi-aset');
    const grup = useMasterOptions('group-aset');
    const jenis = useMasterOptions('jenis-aset');
    const kondisi = useMasterOptions('kondisi-aset');
    const pabrikan = useMasterOptions('pabrikan-aset');
    const model = useMasterOptions('model-aset');
    // Unit kerja dan orang milik Core, dibaca lewat endpoint referensi module: tidak ada
    // pengguna yang mengenali unitnya atau rekannya dari ULID.
    const unitKerja = useMasterOptions('reference-data/unit-kerja');
    const anggota = useMasterOptions('reference-data/anggota');
    const panelRef = useRef<HTMLDivElement>(null);

    const readOnly = mode === 'view';
    const selesai = tersimpan?.status === 'selesai';

    useEffect(() => {
        if (!penerimaanId) {
            return;
        }

        let dibatalkan = false;
        api<{ data: Penerimaan }>(`/penerimaan-aset/${penerimaanId}`)
            .then((result) => {
                if (dibatalkan) {
                    return;
                }

                setTersimpan(result.data);
                setRecord({
                    ...result.data,
                    tanggal: result.data.tanggal?.slice(0, 10) ?? '',
                    tanggal_siap_pakai:
                        result.data.tanggal_siap_pakai?.slice(0, 10) ?? '',
                    receiving_org_unit_id:
                        result.data.receiving_org_unit_id ?? '',
                    diterima_oleh_user_id:
                        result.data.diterima_oleh_user_id ?? '',
                    penanggung_jawab_user_id:
                        result.data.penanggung_jawab_user_id ?? '',
                    lokasi_aset_id: result.data.lokasi_aset_id ?? '',
                    keterangan: result.data.keterangan ?? '',
                    details: (result.data.details ?? []).map((baris) => ({
                        ...barisKosong(),
                        ...baris,
                        kondisi_aset_id: baris.kondisi_aset_id ?? '',
                        pabrikan_aset_id: baris.pabrikan_aset_id ?? '',
                        model_aset_id: baris.model_aset_id ?? '',
                        model_number: baris.model_number ?? '',
                        permintaan_pembelian_detail_id:
                            baris.permintaan_pembelian_detail_id ?? '',
                        keterangan: baris.keterangan ?? '',
                    })),
                });
            })
            .catch((caught) =>
                toast.error(
                    errorMessage(caught, 'Penerimaan belum dapat dimuat.'),
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
    }, [penerimaanId]);

    // Ringkasan dibaca untuk draf saja: begitu dokumen selesai, peringatan ambang
    // kapitalisasi tidak lagi menawarkan keputusan apa pun — nomornya sudah terbit.
    useEffect(() => {
        if (!penerimaanId || selesai) {
            return;
        }

        let dibatalkan = false;
        api<{ data: Ringkasan }>(`/penerimaan-aset/${penerimaanId}/ringkasan`)
            .then((result) => !dibatalkan && setRingkasan(result.data))
            .catch(() => undefined);

        return () => {
            dibatalkan = true;
        };
    }, [penerimaanId, selesai, tersimpan?.version]);

    useEffect(() => {
        if (!penerimaanId || !selesai) {
            return;
        }

        let dibatalkan = false;
        api<{ data: AsetTerbit[] }>(`/penerimaan-aset/${penerimaanId}/aset`)
            .then((result) => !dibatalkan && setTerbit(result.data))
            .catch(() => undefined);

        return () => {
            dibatalkan = true;
        };
    }, [penerimaanId, selesai]);

    const pesan = (field: string) => galat[field]?.[0];

    const idDari = (options: MasterOption[], label: string | null) =>
        options.find((option) => optionLabel(option) === label)?.id ?? '';
    const labelDari = (options: MasterOption[], id: unknown) =>
        options
            .filter((option) => option.id === String(id ?? ''))
            .map(optionLabel)[0] ?? null;

    function ubahBaris(index: number, patch: Partial<BarisPenerimaan>) {
        setRecord((sebelumnya) => ({
            ...sebelumnya,
            details: sebelumnya.details.map((baris, posisi) =>
                posisi === index ? { ...baris, ...patch } : baris,
            ),
        }));
    }

    const totalAset = record.details.reduce(
        (jumlah, baris) => jumlah + (Number(baris.jumlah) || 0),
        0,
    );

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
            responsible_org_unit_id:
                record.responsible_org_unit_id || context.org_unit_id,
            receiving_org_unit_id: record.receiving_org_unit_id || null,
            tanggal: record.tanggal,
            tanggal_siap_pakai: record.tanggal_siap_pakai || null,
            diterima_oleh_user_id: record.diterima_oleh_user_id || null,
            penanggung_jawab_user_id: record.penanggung_jawab_user_id || null,
            lokasi_aset_id: record.lokasi_aset_id || null,
            currency_code: record.currency_code || 'IDR',
            keterangan: record.keterangan || null,
            details: record.details
                .filter((baris) => baris.nama && baris.group_aset_id)
                .map((baris) => ({
                    nama: baris.nama,
                    group_aset_id: baris.group_aset_id,
                    jenis_aset_id: baris.jenis_aset_id,
                    kondisi_aset_id: baris.kondisi_aset_id || null,
                    pabrikan_aset_id: baris.pabrikan_aset_id || null,
                    model_aset_id: baris.model_aset_id || null,
                    model_number: baris.model_number || null,
                    jumlah: Number(baris.jumlah) || 0,
                    nilai_per_unit: Number(baris.nilai_per_unit) || 0,
                    residu_per_unit: Number(baris.residu_per_unit) || 0,
                    permintaan_pembelian_detail_id:
                        baris.permintaan_pembelian_detail_id || null,
                    keterangan: baris.keterangan || null,
                })),
        };

        try {
            if (mode === 'create') {
                const hasil = await api<{ data: Penerimaan }>(
                    '/penerimaan-aset',
                    {
                        method: 'POST',
                        headers: { 'Idempotency-Key': newIdempotencyKey() },
                        body: JSON.stringify(payload),
                    },
                );
                toast.success(
                    `Penerimaan ${hasil.data.kode} disimpan sebagai draf.`,
                );
                bukaPenerimaan(hasil.data.id);

                return;
            }

            await api<{ data: Penerimaan }>(
                `/penerimaan-aset/${penerimaanId}`,
                {
                    method: 'PATCH',
                    body: JSON.stringify({
                        ...payload,
                        version: tersimpan?.version,
                    }),
                },
            );
            toast.success('Perubahan penerimaan disimpan.');
            bukaPenerimaan(String(penerimaanId));
        } catch (caught) {
            if (caught instanceof ApiError) {
                setGalat(caught.validationErrors);
            }

            toast.error(
                errorMessage(caught, 'Penerimaan belum dapat disimpan.'),
            );
        } finally {
            setMenyimpan(false);
        }
    }

    async function selesaikan() {
        setKonfirmasiSelesai(false);
        setMenyimpan(true);

        try {
            await api(`/penerimaan-aset/${penerimaanId}/selesaikan`, {
                method: 'POST',
                body: JSON.stringify({ version: tersimpan?.version }),
            });
            toast.success(
                `Penerimaan diselesaikan. ${totalAset} aset terdaftar dengan kodenya masing-masing.`,
            );
            bukaPenerimaan(String(penerimaanId));
        } catch (caught) {
            if (caught instanceof ApiError) {
                setGalat(caught.validationErrors);
            }

            toast.error(
                errorMessage(caught, 'Penerimaan belum dapat diselesaikan.'),
            );
        } finally {
            setMenyimpan(false);
        }
    }

    /**
     * Menyimpan nomor seri seluruh aset dokumen ini sekaligus.
     *
     * Satu permintaan, bukan dua puluh: kegagalan di tengah pada pengiriman satu per satu
     * meninggalkan separuh terisi tanpa ada yang tahu separuh mana.
     */
    async function simpanNomorSeri() {
        setMenyimpan(true);

        try {
            const hasil = await api<{ data: AsetTerbit[] }>(
                `/penerimaan-aset/${penerimaanId}/aset`,
                {
                    method: 'PUT',
                    body: JSON.stringify({
                        serial: terbit.map((aset) => ({
                            aset_id: aset.id,
                            serial_number: aset.serial_number || null,
                        })),
                    }),
                },
            );
            setTerbit(hasil.data);
            toast.success('Nomor seri disimpan.');
        } catch (caught) {
            toast.error(
                errorMessage(caught, 'Nomor seri belum dapat disimpan.'),
            );
        } finally {
            setMenyimpan(false);
        }
    }

    async function arsipkan() {
        setKonfirmasiArsip(false);

        try {
            await api(`/penerimaan-aset/${penerimaanId}`, {
                method: 'DELETE',
                body: JSON.stringify({ version: tersimpan?.version }),
            });
            toast.success('Draf penerimaan diarsipkan.');
            bukaPenerimaanDaftar();
        } catch (caught) {
            toast.error(
                errorMessage(caught, 'Penerimaan belum dapat diarsipkan.'),
            );
        }
    }

    if (memuat) {
        return (
            <div className="text-muted-foreground p-5 text-sm">
                Memuat penerimaan…
            </div>
        );
    }

    const judul =
        mode === 'create'
            ? 'Penerimaan aset baru'
            : (tersimpan?.kode ?? 'Penerimaan aset');

    /** Satu Select penunjuk master, dipakai berkali-kali pada kepala dan baris. */
    const pilihan = (
        label: string,
        sumber: { options: MasterOption[]; error?: string | null },
        nilai: unknown,
        onPilih: (id: string) => void,
        opsi: { required?: boolean; placeholder?: string } = {},
    ) => (
        <EditShield
            active={readOnly}
            label={label}
            onActivate={() =>
                penerimaanId && !selesai && bukaPenerimaanUbah(penerimaanId)
            }
        >
            <Select
                label={label}
                required={opsi.required}
                items={sumber.options.map(optionLabel)}
                value={labelDari(sumber.options, nilai)}
                placeholder={opsi.placeholder ?? `Pilih ${label.toLowerCase()}`}
                searchPlaceholder={`Cari ${label.toLowerCase()}`}
                emptyMessage={`${label} tidak ditemukan.`}
                ariaLabel={label}
                portalContainer={panelRef}
                onValueChange={(item) => onPilih(idDari(sumber.options, item))}
            />
        </EditShield>
    );

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
                        onClick={() => bukaPenerimaanUbah(String(penerimaanId))}
                    >
                        Ubah
                    </ActionButton>
                )}
                {mode === 'view' && !selesai && canRegister && (
                    <Button
                        type="button"
                        onClick={() => setKonfirmasiSelesai(true)}
                        disabled={menyimpan}
                    >
                        Selesaikan penerimaan
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
                                penerimaanId
                                    ? bukaPenerimaan(penerimaanId)
                                    : bukaPenerimaanDaftar()
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
                        Entitas legal mengikuti konteks aktif Anda. Satu dokumen
                        adalah satu kedatangan: tiap unit yang datang menjadi
                        satu aset dengan kodenya sendiri saat dokumen
                        diselesaikan.
                    </p>

                    {ringkasan && ringkasan.peringatan.length > 0 && (
                        <div className="border-destructive/40 bg-destructive/5 space-y-2 rounded-md border px-4 py-3">
                            <p className="flex items-center gap-2 text-sm font-medium">
                                <TriangleAlert className="size-4" />
                                Di bawah ambang kapitalisasi
                            </p>
                            {ringkasan.peringatan.map((baris) => (
                                <p
                                    key={baris.line_number}
                                    className="text-muted-foreground text-sm"
                                >
                                    Baris {baris.line_number} ({baris.nama}):{' '}
                                    {baris.pesan}
                                </p>
                            ))}
                        </div>
                    )}

                    <CollapsibleSectionGroup
                        defaultValue={['kedatangan', 'barang']}
                    >
                        <CollapsibleSection
                            value="kedatangan"
                            title="Kedatangan"
                            summary={tanggalTampil(record.tanggal)}
                        >
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field>
                                    <Input
                                        label="Tanggal terima"
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
                                    <Input
                                        label="Tanggal siap dipakai"
                                        type="date"
                                        readOnly={readOnly}
                                        value={record.tanggal_siap_pakai ?? ''}
                                        onChange={(event) =>
                                            setRecord({
                                                ...record,
                                                tanggal_siap_pakai:
                                                    event.target.value,
                                            })
                                        }
                                    />
                                    <FieldDescription>
                                        {pesan('tanggal_siap_pakai') ??
                                            'Penyusutan dimulai dari tanggal ini, bukan dari tanggal barang tiba. Kosongkan bila keduanya sama.'}
                                    </FieldDescription>
                                </Field>

                                <Field>
                                    {pilihan(
                                        'Lokasi awal',
                                        lokasi,
                                        record.lokasi_aset_id,
                                        (id) =>
                                            setRecord({
                                                ...record,
                                                lokasi_aset_id: id,
                                            }),
                                        { placeholder: 'Tanpa lokasi' },
                                    )}
                                    <FieldDescription>
                                        {lokasi.error ||
                                            'Lokasi yang dipetakan ke unit organisasi menentukan dimensi keuangan aset.'}
                                    </FieldDescription>
                                </Field>

                                <Field>
                                    <Input
                                        label="Mata uang"
                                        required
                                        maxLength={3}
                                        readOnly={readOnly}
                                        value={record.currency_code ?? 'IDR'}
                                        onChange={(event) =>
                                            setRecord({
                                                ...record,
                                                currency_code:
                                                    event.target.value.toUpperCase(),
                                            })
                                        }
                                    />
                                    {pesan('currency_code') && (
                                        <FieldDescription>
                                            {pesan('currency_code')}
                                        </FieldDescription>
                                    )}
                                </Field>
                            </div>
                        </CollapsibleSection>

                        <CollapsibleSection
                            value="pihak"
                            title="Unit dan penanggung jawab"
                            summary={
                                tersimpan?.responsible_org_unit_nama ??
                                undefined
                            }
                        >
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field>
                                    {pilihan(
                                        'Unit pengguna',
                                        unitKerja,
                                        record.responsible_org_unit_id,
                                        (id) =>
                                            setRecord({
                                                ...record,
                                                responsible_org_unit_id: id,
                                            }),
                                        { required: true },
                                    )}
                                    <FieldDescription>
                                        {unitKerja.error ||
                                            'Unit kerja yang menanggung seluruh aset pada dokumen ini setelah diterima.'}
                                    </FieldDescription>
                                </Field>

                                <Field>
                                    {pilihan(
                                        'Unit penerima',
                                        unitKerja,
                                        record.receiving_org_unit_id,
                                        (id) =>
                                            setRecord({
                                                ...record,
                                                receiving_org_unit_id: id,
                                            }),
                                    )}
                                    <FieldDescription>
                                        Loket atau gudang yang menerima
                                        fisiknya. Sering berbeda dari unit
                                        pengguna.
                                    </FieldDescription>
                                </Field>

                                <Field>
                                    {pilihan(
                                        'Diterima oleh',
                                        anggota,
                                        record.diterima_oleh_user_id,
                                        (id) =>
                                            setRecord({
                                                ...record,
                                                diterima_oleh_user_id: id,
                                            }),
                                    )}
                                    <FieldDescription>
                                        {anggota.error || ' '}
                                    </FieldDescription>
                                </Field>

                                <Field>
                                    {pilihan(
                                        'Penanggung jawab',
                                        anggota,
                                        record.penanggung_jawab_user_id,
                                        (id) =>
                                            setRecord({
                                                ...record,
                                                penanggung_jawab_user_id: id,
                                            }),
                                    )}
                                    <FieldDescription>
                                        Tercatat sebagai pemegang pertama pada
                                        riwayat penempatan tiap aset.
                                    </FieldDescription>
                                </Field>
                            </div>
                        </CollapsibleSection>

                        <CollapsibleSection
                            value="barang"
                            title="Barang yang diterima"
                            summary={`${record.details.length} baris · ${totalAset} aset`}
                        >
                            <div className="space-y-3">
                                {pesan('details') && (
                                    <p className="text-destructive text-sm">
                                        {pesan('details')}
                                    </p>
                                )}
                                <div className="overflow-x-auto">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead className="w-10">
                                                    #
                                                </TableHead>
                                                <TableHead className="min-w-52">
                                                    Nama barang
                                                </TableHead>
                                                <TableHead className="min-w-44">
                                                    Group aset
                                                </TableHead>
                                                <TableHead className="min-w-44">
                                                    Jenis aset
                                                </TableHead>
                                                <TableHead className="min-w-40">
                                                    Kondisi
                                                </TableHead>
                                                <TableHead className="w-24 text-right">
                                                    Jumlah
                                                </TableHead>
                                                <TableHead className="w-40 text-right">
                                                    Nilai / unit
                                                </TableHead>
                                                <TableHead className="w-40 text-right">
                                                    Residu / unit
                                                </TableHead>
                                                {!readOnly && (
                                                    <TableHead className="w-12" />
                                                )}
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {record.details.map(
                                                (baris, index) => (
                                                    <TableRow
                                                        key={
                                                            baris.id ??
                                                            `baris-${index}`
                                                        }
                                                    >
                                                        <TableCell className="text-muted-foreground">
                                                            {index + 1}
                                                        </TableCell>
                                                        <TableCell>
                                                            <Input
                                                                aria-label="Nama barang"
                                                                maxLength={150}
                                                                readOnly={
                                                                    readOnly
                                                                }
                                                                value={
                                                                    baris.nama
                                                                }
                                                                onChange={(
                                                                    event,
                                                                ) =>
                                                                    ubahBaris(
                                                                        index,
                                                                        {
                                                                            nama: event
                                                                                .target
                                                                                .value,
                                                                        },
                                                                    )
                                                                }
                                                            />
                                                        </TableCell>
                                                        <TableCell>
                                                            {pilihan(
                                                                'Group aset',
                                                                grup,
                                                                baris.group_aset_id,
                                                                (id) =>
                                                                    ubahBaris(
                                                                        index,
                                                                        {
                                                                            group_aset_id:
                                                                                id,
                                                                        },
                                                                    ),
                                                            )}
                                                        </TableCell>
                                                        <TableCell>
                                                            {pilihan(
                                                                'Jenis aset',
                                                                jenis,
                                                                baris.jenis_aset_id,
                                                                (id) =>
                                                                    ubahBaris(
                                                                        index,
                                                                        {
                                                                            jenis_aset_id:
                                                                                id,
                                                                        },
                                                                    ),
                                                            )}
                                                        </TableCell>
                                                        <TableCell>
                                                            {pilihan(
                                                                'Kondisi',
                                                                kondisi,
                                                                baris.kondisi_aset_id,
                                                                (id) =>
                                                                    ubahBaris(
                                                                        index,
                                                                        {
                                                                            kondisi_aset_id:
                                                                                id,
                                                                        },
                                                                    ),
                                                                {
                                                                    placeholder:
                                                                        'Tanpa kondisi',
                                                                },
                                                            )}
                                                        </TableCell>
                                                        <TableCell>
                                                            <Input
                                                                aria-label="Jumlah unit"
                                                                type="number"
                                                                min={1}
                                                                readOnly={
                                                                    readOnly
                                                                }
                                                                value={String(
                                                                    baris.jumlah,
                                                                )}
                                                                onChange={(
                                                                    event,
                                                                ) =>
                                                                    ubahBaris(
                                                                        index,
                                                                        {
                                                                            jumlah: event
                                                                                .target
                                                                                .value,
                                                                        },
                                                                    )
                                                                }
                                                            />
                                                        </TableCell>
                                                        <TableCell>
                                                            <Input
                                                                aria-label="Nilai per unit"
                                                                type="number"
                                                                min={0}
                                                                readOnly={
                                                                    readOnly
                                                                }
                                                                value={String(
                                                                    baris.nilai_per_unit,
                                                                )}
                                                                onChange={(
                                                                    event,
                                                                ) =>
                                                                    ubahBaris(
                                                                        index,
                                                                        {
                                                                            nilai_per_unit:
                                                                                event
                                                                                    .target
                                                                                    .value,
                                                                        },
                                                                    )
                                                                }
                                                            />
                                                        </TableCell>
                                                        <TableCell>
                                                            <Input
                                                                aria-label="Residu per unit"
                                                                type="number"
                                                                min={0}
                                                                readOnly={
                                                                    readOnly
                                                                }
                                                                value={String(
                                                                    baris.residu_per_unit,
                                                                )}
                                                                onChange={(
                                                                    event,
                                                                ) =>
                                                                    ubahBaris(
                                                                        index,
                                                                        {
                                                                            residu_per_unit:
                                                                                event
                                                                                    .target
                                                                                    .value,
                                                                        },
                                                                    )
                                                                }
                                                            />
                                                        </TableCell>
                                                        {!readOnly && (
                                                            <TableCell>
                                                                <Button
                                                                    type="button"
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    aria-label={`Hapus baris ${index + 1}`}
                                                                    onClick={() =>
                                                                        setRecord(
                                                                            (
                                                                                sebelumnya,
                                                                            ) => ({
                                                                                ...sebelumnya,
                                                                                details:
                                                                                    sebelumnya.details.filter(
                                                                                        (
                                                                                            _,
                                                                                            posisi,
                                                                                        ) =>
                                                                                            posisi !==
                                                                                            index,
                                                                                    ),
                                                                            }),
                                                                        )
                                                                    }
                                                                >
                                                                    <Trash2 />
                                                                </Button>
                                                            </TableCell>
                                                        )}
                                                    </TableRow>
                                                ),
                                            )}
                                        </TableBody>
                                    </Table>
                                </div>

                                {!readOnly && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() =>
                                            setRecord((sebelumnya) => ({
                                                ...sebelumnya,
                                                details: [
                                                    ...sebelumnya.details,
                                                    barisKosong(),
                                                ],
                                            }))
                                        }
                                    >
                                        <Plus />
                                        Tambah baris
                                    </Button>
                                )}

                                <FieldDescription>
                                    Nilai diisi per unit, bukan total. Ambang
                                    kapitalisasi group dibandingkan terhadap
                                    nilai satu aset — dua puluh kursi lima ratus
                                    ribu tidak melewati ambang sepuluh juta
                                    hanya karena datang bersamaan.
                                </FieldDescription>
                            </div>
                        </CollapsibleSection>

                        <CollapsibleSection
                            value="pabrikan"
                            title="Pabrikan dan model per baris"
                            summary={`${record.details.filter((baris) => baris.pabrikan_aset_id).length} baris terisi`}
                        >
                            <div className="space-y-4">
                                {record.details.map((baris, index) => (
                                    <div
                                        key={baris.id ?? `pabrikan-${index}`}
                                        className="grid gap-4 rounded-md border px-4 py-3 sm:grid-cols-3"
                                    >
                                        <Field>
                                            <p className="text-sm font-medium">
                                                Baris {index + 1}
                                            </p>
                                            <FieldDescription>
                                                {baris.nama || 'Tanpa nama'}
                                            </FieldDescription>
                                        </Field>
                                        <Field>
                                            {pilihan(
                                                'Pabrikan',
                                                pabrikan,
                                                baris.pabrikan_aset_id,
                                                (id) =>
                                                    ubahBaris(index, {
                                                        pabrikan_aset_id: id,
                                                        // Model selalu milik satu pabrikan,
                                                        // jadi mengganti pabrikan membuat
                                                        // model yang sudah dipilih pasti
                                                        // salah. Server menolaknya; layar
                                                        // mengosongkannya lebih dulu.
                                                        model_aset_id: '',
                                                    }),
                                                {
                                                    placeholder:
                                                        'Tanpa pabrikan',
                                                },
                                            )}
                                        </Field>
                                        <Field>
                                            {pilihan(
                                                'Model aset',
                                                model,
                                                baris.model_aset_id,
                                                (id) =>
                                                    ubahBaris(index, {
                                                        model_aset_id: id,
                                                    }),
                                                { placeholder: 'Tanpa model' },
                                            )}
                                        </Field>
                                    </div>
                                ))}
                            </div>
                        </CollapsibleSection>

                        <CollapsibleSection
                            value="keterangan"
                            title="Keterangan"
                            summary={record.keterangan || undefined}
                        >
                            <Field>
                                <Textarea
                                    label="Keterangan"
                                    rows={3}
                                    maxLength={2000}
                                    readOnly={readOnly}
                                    value={record.keterangan ?? ''}
                                    onChange={(event) =>
                                        setRecord({
                                            ...record,
                                            keterangan: event.target.value,
                                        })
                                    }
                                />
                                <FieldDescription>
                                    Mis. nomor surat jalan atau nama pengirim.
                                </FieldDescription>
                            </Field>
                        </CollapsibleSection>

                        {selesai && (
                            <CollapsibleSection
                                value="terbit"
                                title="Aset terbit"
                                summary={`${terbit.length} aset`}
                            >
                                <div className="space-y-3">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead className="w-44">
                                                    Kode aset
                                                </TableHead>
                                                <TableHead>Nama</TableHead>
                                                <TableHead className="w-64">
                                                    Nomor seri
                                                </TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {terbit.map((aset, index) => (
                                                <TableRow key={aset.id}>
                                                    <TableCell className="text-primary font-medium">
                                                        {aset.kode}
                                                    </TableCell>
                                                    <TableCell>
                                                        {aset.nama}
                                                    </TableCell>
                                                    <TableCell>
                                                        <Input
                                                            aria-label={`Nomor seri ${aset.kode}`}
                                                            maxLength={150}
                                                            placeholder="Belum bernomor seri"
                                                            readOnly={
                                                                !canCorrect
                                                            }
                                                            value={
                                                                aset.serial_number ??
                                                                ''
                                                            }
                                                            onChange={(event) =>
                                                                setTerbit(
                                                                    (
                                                                        sebelumnya,
                                                                    ) =>
                                                                        sebelumnya.map(
                                                                            (
                                                                                baris,
                                                                                posisi,
                                                                            ) =>
                                                                                posisi ===
                                                                                index
                                                                                    ? {
                                                                                          ...baris,
                                                                                          serial_number:
                                                                                              event
                                                                                                  .target
                                                                                                  .value,
                                                                                      }
                                                                                    : baris,
                                                                        ),
                                                                )
                                                            }
                                                        />
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                    {canCorrect && (
                                        <Button
                                            type="button"
                                            onClick={() =>
                                                void simpanNomorSeri()
                                            }
                                            disabled={menyimpan}
                                        >
                                            {menyimpan
                                                ? 'Menyimpan…'
                                                : 'Simpan nomor seri'}
                                        </Button>
                                    )}
                                    <FieldDescription>
                                        Satu simpan untuk seluruh dokumen, bukan
                                        satu per aset: yang mengetiknya sedang
                                        memegang setumpuk stiker dan membacanya
                                        berurutan.
                                    </FieldDescription>
                                </div>
                            </CollapsibleSection>
                        )}
                    </CollapsibleSectionGroup>
                </div>
            </div>

            <AlertDialog
                open={konfirmasiSelesai}
                onOpenChange={setKonfirmasiSelesai}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Selesaikan penerimaan ini?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {totalAset} aset akan terdaftar dengan kodenya
                            masing-masing dan mulai disusutkan. Nomor aset tidak
                            dapat ditarik kembali, dan dokumen ini tidak dapat
                            diubah lagi sesudahnya.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Batal</AlertDialogCancel>
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
                        <AlertDialogTitle>
                            Arsipkan draf penerimaan?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            Draf ini belum melahirkan aset apa pun, jadi
                            mengarsipkannya tidak mengubah register.
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
