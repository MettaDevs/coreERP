import { router } from '@inertiajs/react';
import { CheckCheck, ListPlus, Plus, Trash2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { RefObject } from 'react';
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
import EditShield from '../../_shared/EditShield';
import {
    ApiError,
    api,
    errorMessage,
    newIdempotencyKey,
    toastSaveError,
} from '../../api';
import { optionLabel, useMasterOptions } from '../../master/useMasterOptions';
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
    monitoringId,
    mode,
}: {
    context: Context;
    permissions: string[];
    monitoringId?: string;
    mode: Mode;
}) {
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
    const [aset, setAset] = useState<Aset[]>([]);
    const [asetGalat, setAsetGalat] = useState('');
    const lokasi = useMasterOptions('lokasi-aset');
    const kondisi = useMasterOptions('kondisi-aset');
    const unitKerja = useMasterOptions('reference-data/unit-kerja');
    const anggota = useMasterOptions('reference-data/anggota');
    const panelRef = useRef<HTMLDivElement>(null);

    const readOnly = mode === 'view';
    const selesai = tersimpan?.status === 'selesai';

    // Daftar aset hanya dibutuhkan untuk menambah baris dengan tangan.
    useEffect(() => {
        if (readOnly) {
            return;
        }

        api<{ data: Aset[] }>('/aset')
            .then((result) => setAset(result.data))
            .catch(() =>
                setAsetGalat(
                    'Daftar aset tidak dapat dimuat, jadi aset belum bisa ditambahkan satu per satu. Pakai Isi otomatis, atau minta akses lihat register aset.',
                ),
            );
    }, [readOnly]);

    useEffect(() => {
        if (!monitoringId) {
            return;
        }

        let dibatalkan = false;
        api<{ data: Monitoring }>(`/monitoring-aset/${monitoringId}`)
            .then((result) => {
                if (!dibatalkan) {
                    setTersimpan(result.data);
                    setRecord(keForm(result.data));
                }
            })
            .catch((caught) =>
                toast.error(
                    errorMessage(caught, 'Monitoring belum dapat dimuat.'),
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

    function pilihDari(
        options: { id: string }[],
        id: string | null | undefined,
    ) {
        const dipilih = options.find((option) => option.id === id);

        return dipilih ? optionLabel(dipilih as never) : null;
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
                toast.success(
                    `Monitoring ${hasil.data.kode} disimpan sebagai draf.`,
                );
                bukaMonitoring(hasil.data.id);

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
            toast.success('Perubahan monitoring disimpan.');
            bukaMonitoring(String(monitoringId));
        } catch (caught) {
            if (caught instanceof ApiError) {
                setGalat(caught.validationErrors);
            }

            toastSaveError(caught, 'Monitoring belum dapat disimpan.');
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
            toast.success(
                jumlah > 0
                    ? `${jumlah} aset di lokasi ini ditambahkan ke daftar periksa.`
                    : 'Semua aset di lokasi ini sudah ada di daftar periksa.',
            );
        } catch (caught) {
            toastSaveError(caught, 'Daftar aset belum dapat diisi.');
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
            toast.success('Monitoring diselesaikan. Hasilnya sudah dikunci.');
        } catch (caught) {
            if (caught instanceof ApiError) {
                setGalat(caught.validationErrors);
            }

            toastSaveError(caught, 'Monitoring belum dapat diselesaikan.');
        } finally {
            setMenyimpan(false);
        }
    }

    async function arsipkan() {
        setKonfirmasiArsip(false);

        try {
            await api(`/monitoring-aset/${monitoringId}`, {
                method: 'DELETE',
                body: JSON.stringify({ version: tersimpan?.version }),
            });
            toast.success('Draf monitoring diarsipkan.');
            bukaDaftar();
        } catch (caught) {
            toastSaveError(caught, 'Monitoring belum dapat diarsipkan.');
        }
    }

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
    const ubah = () => monitoringId && bukaMonitoringUbah(monitoringId);

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
                {readOnly && !selesai && can('update') && (
                    <>
                        <ActionButton
                            action="edit"
                            type="button"
                            onClick={ubah}
                        >
                            Ubah
                        </ActionButton>
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
                {readOnly && !selesai && can('archive') && (
                    <ActionButton
                        action="archive"
                        type="button"
                        onClick={() => setKonfirmasiArsip(true)}
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
                                monitoringId
                                    ? bukaMonitoring(monitoringId)
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
                            <Input
                                label="Tanggal monitoring"
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
                                active={readOnly && !selesai}
                                label="lokasi aset"
                                onActivate={ubah}
                            >
                                <Select
                                    label="Lokasi aset"
                                    required
                                    items={lokasi.options.map(optionLabel)}
                                    value={pilihDari(
                                        lokasi.options,
                                        record.lokasi_aset_id,
                                    )}
                                    placeholder="Pilih lokasi yang diperiksa"
                                    searchPlaceholder="Cari lokasi aset"
                                    emptyMessage="Lokasi aset tidak ditemukan."
                                    ariaLabel="Lokasi aset"
                                    portalContainer={panelRef}
                                    onValueChange={(item) =>
                                        setRecord({
                                            ...record,
                                            lokasi_aset_id:
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
                                {pesan('lokasi_aset_id') ||
                                    lokasi.error ||
                                    (readOnly && tersimpan?.lokasi_aset_nama
                                        ? tersimpan.lokasi_aset_nama
                                        : 'Satu monitoring memeriksa satu lokasi.')}
                            </FieldDescription>
                        </Field>

                        <Field>
                            <EditShield
                                active={readOnly && !selesai}
                                label="unit organisasi"
                                onActivate={ubah}
                            >
                                <Select
                                    label="Unit organisasi"
                                    items={unitKerja.options.map(optionLabel)}
                                    value={pilihDari(
                                        unitKerja.options,
                                        record.responsible_org_unit_id,
                                    )}
                                    placeholder="Semua unit"
                                    searchPlaceholder="Cari unit kerja"
                                    emptyMessage="Unit kerja tidak ditemukan."
                                    ariaLabel="Unit organisasi"
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
                            </EditShield>
                            <FieldDescription>
                                {pesan('responsible_org_unit_id') ||
                                    unitKerja.error ||
                                    'Bila diisi, Isi otomatis hanya mengambil aset milik unit ini.'}
                            </FieldDescription>
                        </Field>

                        <Field>
                            <EditShield
                                active={readOnly && !selesai}
                                label="penanggung jawab"
                                onActivate={ubah}
                            >
                                <Select
                                    label="Penanggung jawab"
                                    items={anggota.options.map(optionLabel)}
                                    value={pilihDari(
                                        anggota.options,
                                        record.penanggung_jawab_user_id,
                                    )}
                                    placeholder="Semua orang"
                                    searchPlaceholder="Cari nama"
                                    emptyMessage="Orang tidak ditemukan."
                                    ariaLabel="Penanggung jawab"
                                    portalContainer={panelRef}
                                    onValueChange={(item) =>
                                        setRecord({
                                            ...record,
                                            penanggung_jawab_user_id:
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
                                    'Bila diisi, Isi otomatis hanya mengambil aset yang dipegang orang ini.'}
                            </FieldDescription>
                        </Field>
                    </div>

                    <Field>
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
                    </Field>

                    <div className="space-y-2">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h2 className="text-base font-medium">
                                Aset yang diperiksa
                                {record.details.length > 0 && (
                                    <span className="text-muted-foreground ml-2 text-sm font-normal">
                                        {record.details.length} aset
                                        {belumDiperiksa > 0 &&
                                            `, ${belumDiperiksa} belum diperiksa`}
                                        {tidakSesuai > 0 &&
                                            `, ${tidakSesuai} tidak sesuai`}
                                    </span>
                                )}
                            </h2>
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
                                                    details: record.details.map(
                                                        (baris) =>
                                                            baris.ada === null
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
                                            Tandai yang belum sebagai ada
                                        </Button>
                                    )}
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
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="w-12">
                                            No
                                        </TableHead>
                                        <TableHead>Aset</TableHead>
                                        <TableHead>Di catatan aset</TableHead>
                                        <TableHead>Keberadaan</TableHead>
                                        <TableHead>Kondisi fisik</TableHead>
                                        <TableHead>Hasil</TableHead>
                                        <TableHead>Keterangan</TableHead>
                                        <TableHead className="text-right">
                                            Nilai buku
                                        </TableHead>
                                        {readOnly && (
                                            <TableHead>Tindak lanjut</TableHead>
                                        )}
                                        {!readOnly && (
                                            <TableHead className="w-12" />
                                        )}
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {record.details.map((baris, index) => (
                                        <MonitoringRow
                                            key={baris.id ?? `baru-${index}`}
                                            baris={baris}
                                            index={index}
                                            readOnly={readOnly}
                                            lokasiDokumen={
                                                tersimpan?.lokasi_aset_id ??
                                                record.lokasi_aset_id ??
                                                ''
                                            }
                                            aset={aset}
                                            kondisi={kondisi.options}
                                            permissions={permissions}
                                            panelRef={panelRef}
                                            onChange={(patch) =>
                                                ubahBaris(index, patch)
                                            }
                                            onRemove={() =>
                                                setRecord({
                                                    ...record,
                                                    details:
                                                        record.details.filter(
                                                            (_, posisi) =>
                                                                posisi !==
                                                                index,
                                                        ),
                                                })
                                            }
                                        />
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </div>

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
                        <AlertDialogTitle>Arsipkan draf ini?</AlertDialogTitle>
                        <AlertDialogDescription>
                            Draf disembunyikan dari daftar dan nomornya tidak
                            dipakai ulang. Catatan aset tidak terpengaruh.
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

/** Satu baris daftar periksa, dalam mode baca maupun sunting. */
function MonitoringRow({
    baris,
    index,
    readOnly,
    lokasiDokumen,
    aset,
    kondisi,
    permissions,
    panelRef,
    onChange,
    onRemove,
}: {
    baris: MonitoringLine;
    index: number;
    readOnly: boolean;
    lokasiDokumen: string;
    aset: Aset[];
    kondisi: ReturnType<typeof useMasterOptions>['options'];
    permissions: string[];
    panelRef: RefObject<HTMLDivElement | null>;
    onChange: (patch: Partial<MonitoringLine>) => void;
    onRemove: () => void;
}) {
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
                    <Select
                        items={aset.map((item) =>
                            item.nama
                                ? `${item.kode} · ${item.nama}`
                                : item.kode,
                        )}
                        value={
                            dipilih
                                ? dipilih.nama
                                    ? `${dipilih.kode} · ${dipilih.nama}`
                                    : dipilih.kode
                                : null
                        }
                        placeholder="Pilih aset"
                        searchPlaceholder="Cari kode atau nama aset"
                        emptyMessage="Aset tidak ditemukan."
                        ariaLabel={`Aset baris ${index + 1}`}
                        portalContainer={panelRef}
                        onValueChange={(item) =>
                            onChange({
                                aset_id:
                                    aset.find(
                                        (calon) =>
                                            (calon.nama
                                                ? `${calon.kode} · ${calon.nama}`
                                                : calon.kode) === item,
                                    )?.id ?? '',
                            })
                        }
                    />
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
                )}
            </TableCell>
            <TableCell>
                {readOnly ? (
                    (baris.kondisi_aset_nama ?? '—')
                ) : (
                    <Select
                        items={kondisi.map(optionLabel)}
                        value={
                            kondisi
                                .filter(
                                    (option) =>
                                        option.id === baris.kondisi_aset_id,
                                )
                                .map(optionLabel)[0] ?? null
                        }
                        placeholder="Tidak dicatat"
                        searchPlaceholder="Cari kondisi"
                        emptyMessage="Kondisi tidak ditemukan."
                        ariaLabel={`Kondisi fisik baris ${index + 1}`}
                        portalContainer={panelRef}
                        onValueChange={(item) =>
                            onChange({
                                kondisi_aset_id:
                                    kondisi.find(
                                        (option) =>
                                            optionLabel(option) === item,
                                    )?.id ?? '',
                            })
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
                    <Input
                        aria-label={`Keterangan baris ${index + 1}`}
                        value={baris.keterangan ?? ''}
                        onChange={(event) =>
                            onChange({ keterangan: event.target.value })
                        }
                    />
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
