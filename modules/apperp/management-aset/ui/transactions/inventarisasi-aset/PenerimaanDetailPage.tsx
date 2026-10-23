import { usePage } from '@inertiajs/react';
import { Info, Trash2, TriangleAlert } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { DraftRecordAttachments } from '@/components/draft-record-attachments';
import type { DraftAttachmentLimits } from '@/components/draft-record-attachments';
import { RecordAttachments } from '@/components/record-attachments';
import { useWorkDate } from '@/hooks/use-work-date';
import type { Workspace } from '@/types';
import { ActionButton } from '@apperp/ui/action-button';
import { Alert, AlertDescription, AlertTitle } from '@apperp/ui/alert';
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
import { FieldHint } from '@apperp/ui/field';
import { FormControl, FormGrid } from '@apperp/ui/form-control';
import { getFormValidationErrors } from '@apperp/ui/form-feedback';
import { Input } from '@apperp/ui/input';
import { usePageDialogRecord } from '@apperp/ui/page-dialog';
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { Select } from '@apperp/ui/select';

import { Textarea } from '@apperp/ui/textarea';
import EditShield from '../../_shared/EditShield';
import { ApiError, api, errorMessage, newIdempotencyKey } from '../../api';
import AssetConditionSelect from '../../master/AssetConditionSelect';
import { defaultDepartmentOf } from '../../master/locationDefaults';
import MasterLookup from '../../master/MasterLookup';
import { useMasterOptions } from '../../master/useMasterOptions';
import { useSaveFeedback } from '../../use-save-feedback';
import { CancellationAction } from '../_shared/CancellationAction';
import { CancellationStatus } from '../_shared/CancellationStatus';
import { RecordCardHeader } from '../_shared/record-card';
import type { RecordCardControls } from '../_shared/record-card';
import { useRecordCardDirty } from '../_shared/use-record-card-dirty';
import {
    AcquisitionJournal,
    VendorPicker,
    labelStatusPosting,
} from './AcquisitionPosting';
import { OpeningBalanceBooks, useBukuGroup } from './OpeningBalanceBooks';
import type {
    AsetTerbit,
    BarisPenerimaan,
    CaraPerolehan,
    Context,
    EditablePenerimaan,
    Penerimaan,
    PratinjauPosting,
    Ringkasan,
} from './penerimaan';
import {
    CARA_PEROLEHAN,
    StatusBadge,
    barisKosong,
    bolehMendaftarkan,
    bolehMengoreksiAset,
    bukaPenerimaan as navigateView,
    bukaPenerimaanDaftar as navigateClose,
    bukaPenerimaanUbah as navigateEdit,
    izin,
    penerimaanKosong,
    tanggalTampil,
} from './penerimaan';
import PurchaseRequestPicker from './PurchaseRequestPicker';
import { appendRequestLines, requestLinePatch } from './receipt-request-lines';
import ReceiptAttributes from './ReceiptAttributes';

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
 *
 * **Jurnal perolehan dilihat sebelum diselesaikan** (feed posting finance, TODO 9.3): baris
 * jurnal, dimensinya, dan masalahnya tampil selama draf, dan hal yang akan menolak penyelesaian
 * disebut lebih dulu, bukan baru muncul setelah tombol terakhir ditekan.
 */
export default function PenerimaanDetailPage({
    context,
    permissions,
    penerimaanId,
    mode,
    onClose,
    onSaved,
    onRequestView,
    onRequestEdit,
    onBusyChange,
    onDirtyChange,
    portalContainer,
}: {
    context: Context;
    permissions: string[];
    penerimaanId?: string;
    mode: Mode;
} & RecordCardControls) {
    const [feedbackSections, setFeedbackSections] = useState<string[]>([
        'kedatangan',
        'vendor',
        'barang',
        'atribut',
        'jurnal',
    ]);

    const {
        scopeRef: feedbackScopeRef,
        feedbackSummary,
        reportSaveError,
        clearSaveFeedback,
    } = useSaveFeedback(undefined, penerimaanId, () =>
        setFeedbackSections([
            'kedatangan',
            'vendor',
            'pihak',
            'barang',
            'atribut',
            'saldo-awal',
            'pabrikan',
            'dokumen-pendukung',
            'keterangan',
            'jurnal',
            'terbit',
        ]),
    );

    const embedded = usePageDialogRecord(undefined);

    const bukaPenerimaan = (id: string) => (onRequestView ?? navigateView)(id);
    const bukaPenerimaanUbah = (id: string) =>
        (onRequestEdit ?? navigateEdit)(id);
    const bukaPenerimaanDaftar = () => (onClose ?? navigateClose)();

    const can = izin(permissions);
    const canAttach = can('update');
    const { workspace } = usePage<{ workspace: Workspace }>().props;
    const canRegister = bolehMendaftarkan(permissions);
    // Nomor seri mengubah aset, bukan dokumen, jadi izinnya izin koreksi aset. Dokumen
    // yang sudah selesai memang tidak dapat disunting — dan ini bukan pengecualiannya,
    // karena nomor seri tidak pernah menjadi bagian dokumen.
    const canCorrect = bolehMengoreksiAset(permissions);
    const { date: workDate } = useWorkDate();
    const [record, setRecord] = useState<EditablePenerimaan>(() =>
        penerimaanKosong(context, workDate),
    );
    const [reload, setReload] = useState(0);
    const [tersimpan, setTersimpan] = useState<Penerimaan | null>(null);
    const [ringkasan, setRingkasan] = useState<Ringkasan | null>(null);
    const [terbit, setTerbit] = useState<AsetTerbit[]>([]);
    const savedIssuedAssets = useRef<AsetTerbit[]>([]);
    const [issuedLoaded, setIssuedLoaded] = useState(false);
    const [memuat, setMemuat] = useState(mode !== 'create');
    const [menyimpan, setMenyimpan] = useState(false);
    useEffect(() => {
        onBusyChange?.(menyimpan);

        return () => onBusyChange?.(false);
    }, [menyimpan, onBusyChange]);
    const [galat, setGalat] = useState<Record<string, string[]>>({});
    const [saveError, setSaveError] = useState<string | null>(null);
    const [draftAttachments, setDraftAttachments] = useState<File[]>([]);
    const attachmentRevision = useRef(0);
    const createRequest = useRef<{ signature: string; key: string } | null>(
        null,
    );
    const [attachmentLimits, setAttachmentLimits] =
        useState<DraftAttachmentLimits | null>(null);
    const [attachmentLimitsError, setAttachmentLimitsError] = useState<
        string | null
    >(null);
    const [attachmentLimitsReload, setAttachmentLimitsReload] = useState(0);
    useEffect(() => {
        if (mode !== 'create' || !canAttach) {
            return;
        }

        const controller = new AbortController();
        api<{ data: DraftAttachmentLimits }>(
            '/penerimaan-aset/attachment-limits',
            {
                signal: controller.signal,
            },
        )
            .then((result) => {
                if (!controller.signal.aborted) {
                    setAttachmentLimits(result.data);
                    setAttachmentLimitsError(null);
                }
            })
            .catch((caught: unknown) => {
                if (!controller.signal.aborted) {
                    setAttachmentLimitsError(
                        errorMessage(
                            caught,
                            'Pilihan lampiran belum dapat dimuat.',
                        ),
                    );
                }
            });

        return () => controller.abort();
    }, [mode, canAttach, attachmentLimitsReload]);
    const [konfirmasiSelesai, setKonfirmasiSelesai] = useState(false);
    const [konfirmasiArsip, setKonfirmasiArsip] = useState(false);
    // Pratinjau disimpan bersama versi dokumen yang melahirkannya: pratinjau versi lama tidak
    // boleh tampil sebagai pratinjau versi baru, dan "sedang memuat" cukup berarti versinya
    // belum sama.
    const [pratinjau, setPratinjau] = useState<{
        id: string;
        versi: number;
        data: PratinjauPosting | null;
    } | null>(null);

    const lokasi = useMasterOptions(
        'lokasi-aset',
        '',
        record.lokasi_aset_id ?? '',
    );
    const grup = useMasterOptions(
        'group-aset',
        '',
        record.details.map((row) => row.group_aset_id).filter(Boolean),
    );
    const jenis = useMasterOptions(
        'jenis-aset',
        '',
        record.details.map((row) => row.jenis_aset_id).filter(Boolean),
    );
    const kondisi = useMasterOptions(
        'kondisi-aset',
        '',
        record.details.map((row) => row.kondisi_aset_id).filter(Boolean),
    );
    const pabrikan = useMasterOptions(
        'pabrikan-aset',
        '',
        record.details.map((row) => row.pabrikan_aset_id).filter(Boolean),
    );
    const model = useMasterOptions(
        'model-aset',
        '',
        record.details.map((row) => row.model_aset_id).filter(Boolean),
    );
    // Unit kerja dan orang milik Core, dibaca lewat endpoint referensi module: tidak ada
    // pengguna yang mengenali unitnya atau rekannya dari ULID.
    const unitKerja = useMasterOptions(
        'reference-data/unit-kerja',
        '',
        [record.responsible_org_unit_id, record.receiving_org_unit_id].filter(
            (id): id is string => Boolean(id),
        ),
    );
    const anggota = useMasterOptions(
        'reference-data/anggota',
        '',
        [record.diterima_oleh_user_id, record.penanggung_jawab_user_id].filter(
            (id): id is string => Boolean(id),
        ),
    );
    const panelRef = useRef<HTMLDivElement>(null);
    // Saldo awal aset lama (area 10): tanpa vendor dan PPN, dengan akumulasi per buku.
    const saldoAwal = record.cara_perolehan === 'saldo_awal';
    const bukuGroup = useBukuGroup(
        record.details.map((baris) => baris.group_aset_id),
        saldoAwal,
    );

    const readOnly = mode === 'view' || menyimpan;
    const selesai = tersimpan !== null && tersimpan.status !== 'draft';

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
                    cara_perolehan: result.data.cara_perolehan ?? 'pembelian',
                    vendor_id: result.data.vendor_id ?? '',
                    vendor_invoice_reference:
                        result.data.vendor_invoice_reference ?? '',
                    purchase_order_reference:
                        result.data.purchase_order_reference ?? '',
                    vendor_invoice_date:
                        result.data.vendor_invoice_date?.slice(0, 10) ?? '',
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
                        nilai_per_unit: tanpaNolBelakang(baris.nilai_per_unit),
                        ppn_per_unit: tanpaNolBelakang(baris.ppn_per_unit),
                        akumulasi_per_unit: tanpaNolBelakang(
                            baris.akumulasi_per_unit,
                        ),
                        periode_berjalan: baris.periode_berjalan ?? '',
                        saldo_awal_buku: (baris.saldo_awal_buku ?? []).map(
                            (buku) => ({
                                ...buku,
                                akumulasi_per_unit: tanpaNolBelakang(
                                    buku.akumulasi_per_unit,
                                ),
                            }),
                        ),
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
    }, [penerimaanId, reload]);

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

    // Pratinjau jurnal perolehan untuk draf yang sedang dilihat. Dibaca ulang setiap versi
    // dokumen berubah, karena jurnalnya disusun dari isi dokumen yang tersimpan.
    useEffect(() => {
        if (!penerimaanId || selesai || mode !== 'view' || !tersimpan) {
            return;
        }

        let dibatalkan = false;
        const versi = tersimpan.version;
        const id = penerimaanId;
        api<{ data: PratinjauPosting }>(
            `/penerimaan-aset/${penerimaanId}/pratinjau-posting`,
        )
            .then(
                (result) =>
                    !dibatalkan &&
                    setPratinjau({ id, versi, data: result.data }),
            )
            .catch(
                () => !dibatalkan && setPratinjau({ id, versi, data: null }),
            );

        return () => {
            dibatalkan = true;
        };
    }, [penerimaanId, selesai, mode, tersimpan]);

    useEffect(() => {
        if (!penerimaanId || !selesai) {
            return;
        }

        let dibatalkan = false;
        api<{ data: AsetTerbit[] }>(`/penerimaan-aset/${penerimaanId}/aset`)
            .then((result) => {
                if (!dibatalkan) {
                    savedIssuedAssets.current = result.data;
                    setTerbit(result.data);
                }
            })
            .catch(() => undefined)
            .finally(() => {
                if (!dibatalkan) {
                    setIssuedLoaded(true);
                }
            });

        return () => {
            dibatalkan = true;
        };
    }, [penerimaanId, selesai]);

    const pesan = (field: string) => galat[field]?.[0];

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
    const hibah = record.cara_perolehan === 'hibah';
    // Hibah tidak ditagih pemasok, dan saldo awal bukan transaksi dengan pemasok sama sekali.
    const tanpaVendor = hibah || saldoAwal;
    // Pratinjau dokumen dan versi lain tidak pernah tampil sebagai pratinjau dokumen ini.
    const pratinjauMilikIni =
        pratinjau !== null &&
        pratinjau.id === penerimaanId &&
        pratinjau.versi === tersimpan?.version;
    const pratinjauSekarang = pratinjauMilikIni ? pratinjau.data : null;
    const memuatPratinjau = mode === 'view' && !selesai && !pratinjauMilikIni;
    const penghalang = pratinjauSekarang?.blockers ?? [];

    async function simpan() {
        clearSaveFeedback();
        const validationErrors = panelRef.current
            ? getFormValidationErrors(panelRef.current)
            : {};

        if (Object.keys(validationErrors).length) {
            setGalat(validationErrors);
            reportSaveError(
                new ApiError(
                    'Periksa isian yang ditandai.',
                    validationErrors,
                    422,
                ),
                'Data belum dapat disimpan.',
            );

            return;
        }

        setSaveError(null);

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
            purchase_order_reference:
                record.purchase_order_reference?.trim() || null,
            cara_perolehan: record.cara_perolehan || 'pembelian',
            // Hibah dan saldo awal tidak punya pemasok yang ditagih, jadi vendor dan fakturnya
            // tidak dikirim.
            vendor_id: tanpaVendor ? null : record.vendor_id || null,
            vendor_invoice_reference: tanpaVendor
                ? null
                : record.vendor_invoice_reference || null,
            vendor_invoice_date: tanpaVendor
                ? null
                : record.vendor_invoice_date || null,
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
                    // Teks, bukan Number: harga satuan boleh bertiga desimal dan tidak
                    // boleh bergeser karena pembulatan float sebelum sampai ke server.
                    nilai_per_unit: angka(baris.nilai_per_unit),
                    ppn_per_unit: saldoAwal ? '0' : angka(baris.ppn_per_unit),
                    residu_per_unit: Number(baris.residu_per_unit) || 0,
                    akumulasi_per_unit: saldoAwal
                        ? angka(baris.akumulasi_per_unit)
                        : '0',
                    periode_berjalan: saldoAwal
                        ? Number(baris.periode_berjalan) || 0
                        : 0,
                    saldo_awal_buku: saldoAwal
                        ? (baris.saldo_awal_buku ?? []).map((buku) => ({
                              buku_id: buku.buku_id,
                              akumulasi_per_unit: angka(
                                  buku.akumulasi_per_unit,
                              ),
                              periode_berjalan:
                                  Number(buku.periode_berjalan) || 0,
                          }))
                        : [],
                    permintaan_pembelian_detail_id:
                        baris.permintaan_pembelian_detail_id || null,
                    atribut: baris.atribut ?? [],
                    keterangan: baris.keterangan || null,
                })),
        };

        try {
            if (mode === 'create') {
                const signature = JSON.stringify([
                    payload,
                    attachmentRevision.current,
                ]);

                if (createRequest.current?.signature !== signature) {
                    createRequest.current = {
                        signature,
                        key: newIdempotencyKey(),
                    };
                }

                let body: string | FormData = JSON.stringify(payload);

                if (draftAttachments.length > 0) {
                    body = new FormData();
                    body.append('payload', JSON.stringify(payload));

                    for (const file of draftAttachments) {
                        body.append('attachments[]', file);
                    }
                }

                const hasil = await api<{ data: Penerimaan }>(
                    '/penerimaan-aset',
                    {
                        method: 'POST',
                        headers: {
                            'Idempotency-Key': createRequest.current.key,
                        },
                        body,
                    },
                );
                clearSaveFeedback();
                toast.success(
                    `Penerimaan ${hasil.data.kode} disimpan sebagai draf.`,
                );
                setDraftAttachments([]);
                createRequest.current = null;
                (onSaved ?? bukaPenerimaan)(hasil.data.id);

                return;
            }

            const hasil = await api<{ data: Penerimaan }>(
                `/penerimaan-aset/${penerimaanId}`,
                {
                    method: 'PATCH',
                    body: JSON.stringify({
                        ...payload,
                        version: tersimpan?.version,
                    }),
                },
            );
            setTersimpan(hasil.data);
            clearSaveFeedback();
            toast.success('Perubahan penerimaan disimpan.');
            (onSaved ?? bukaPenerimaan)(String(penerimaanId));
        } catch (caught) {
            if (caught instanceof ApiError) {
                setGalat(caught.validationErrors);
            }

            setSaveError(
                caught instanceof ApiError && caught.status >= 500
                    ? 'Penerimaan belum tersimpan karena terjadi kesalahan sistem. Isian Anda tetap tersedia. Tim pengelola dapat memeriksa laporan kesalahannya.'
                    : errorMessage(caught, 'Penerimaan belum dapat disimpan.'),
            );
            panelRef.current?.scrollTo({ top: 0, behavior: 'smooth' });
            reportSaveError(caught, 'Penerimaan belum dapat disimpan.');
        } finally {
            setMenyimpan(false);
        }
    }

    async function selesaikan() {
        setKonfirmasiSelesai(false);
        setMenyimpan(true);
        setSaveError(null);

        try {
            const hasil = await api<{ data: Penerimaan }>(
                `/penerimaan-aset/${penerimaanId}/selesaikan`,
                {
                    method: 'POST',
                    body: JSON.stringify({ version: tersimpan?.version }),
                },
            );
            setTersimpan(hasil.data);
            clearSaveFeedback();
            toast.success(
                `Penerimaan diselesaikan. ${totalAset} aset terdaftar dengan kodenya masing-masing.`,
            );
            (onSaved ?? bukaPenerimaan)(String(penerimaanId));
        } catch (caught) {
            if (caught instanceof ApiError) {
                setGalat(caught.validationErrors);
            }

            setSaveError(
                caught instanceof ApiError && caught.status >= 500
                    ? 'Penerimaan belum selesai karena terjadi kesalahan sistem. Dokumen tetap tersedia. Tim pengelola dapat memeriksa laporan kesalahannya.'
                    : errorMessage(
                          caught,
                          'Penerimaan belum dapat diselesaikan.',
                      ),
            );
            panelRef.current?.scrollTo({ top: 0, behavior: 'smooth' });
            reportSaveError(caught, 'Penerimaan belum dapat diselesaikan.');
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
            // Nomor seri mengklaim dokumennya, jadi versi dokumen yang dikirim dan
            // versi barunya disimpan untuk pengisian berikutnya.
            const hasil = await api<{ data: AsetTerbit[]; version: number }>(
                `/penerimaan-aset/${penerimaanId}/aset`,
                {
                    method: 'PUT',
                    body: JSON.stringify({
                        version: tersimpan?.version,
                        serial: terbit.map((aset) => ({
                            aset_id: aset.id,
                            serial_number: aset.serial_number || null,
                        })),
                    }),
                },
            );
            setTerbit(hasil.data);
            savedIssuedAssets.current = hasil.data;
            setTersimpan((sebelumnya) =>
                sebelumnya
                    ? { ...sebelumnya, version: hasil.version }
                    : sebelumnya,
            );
            clearSaveFeedback();
            toast.success('Nomor seri disimpan.');
        } catch (caught) {
            reportSaveError(caught, 'Nomor seri belum dapat disimpan.');
        } finally {
            setMenyimpan(false);
        }
    }

    async function arsipkan() {
        setKonfirmasiArsip(false);
        setMenyimpan(true);

        try {
            await api(`/penerimaan-aset/${penerimaanId}`, {
                method: 'DELETE',
                body: JSON.stringify({ version: tersimpan?.version }),
            });
            clearSaveFeedback();
            toast.success('Draf penerimaan diarsipkan.');
            bukaPenerimaanDaftar();
        } catch (caught) {
            reportSaveError(caught, 'Penerimaan belum dapat diarsipkan.');
        } finally {
            setMenyimpan(false);
        }
    }

    useRecordCardDirty({
        value: {
            record,
            terbit,
            attachments: draftAttachments.map((file) => [
                file.name,
                file.size,
                file.lastModified,
            ]),
        },
        ready: !memuat && (!selesai || issuedLoaded),
        scopeKey: JSON.stringify([penerimaanId, mode, tersimpan?.version]),
        onDirtyChange: onDirtyChange,
    });

    const editingSerial =
        selesai &&
        mode === 'view' &&
        canCorrect &&
        issuedLoaded &&
        terbit.length > 0;

    if (memuat) {
        return (
            <div className="text-muted-foreground p-5 text-sm">
                Memuat penerimaan…
            </div>
        );
    }

    const legalEntityName = workspace.legal_entities.find(
        (entity) =>
            entity.id ===
            (tersimpan?.legal_entity_id ?? context.legal_entity_id),
    )?.name;
    const judul =
        mode === 'create'
            ? 'Penerimaan aset baru'
            : [legalEntityName, tersimpan?.kode ?? 'Penerimaan aset']
                  .filter(Boolean)
                  .join(' · ');

    /** Lookup SDK yang sama untuk referensi pada kepala dan baris. */
    const pilihan = (
        label: string,
        sumber: ReturnType<typeof useMasterOptions>,
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
            <div>
                <MasterLookup
                    source={sumber}
                    label={label}
                    required={opsi.required}
                    value={String(nilai ?? '')}
                    onValueChange={onPilih}
                    placeholder={opsi.placeholder}
                    disabled={readOnly}
                    permissions={permissions}
                    portalContainer={portalContainer ?? panelRef}
                />
                {sumber.error && (
                    <div role="alert" className="text-destructive mt-1 text-sm">
                        <p>{sumber.error}</p>
                        {sumber.reload && (
                            <Button
                                type="button"
                                variant="link"
                                onClick={sumber.reload}
                            >
                                Muat ulang pilihan
                            </Button>
                        )}
                    </div>
                )}
            </div>
        </EditShield>
    );

    return (
        <div
            ref={feedbackScopeRef}
            className="flex h-full min-h-0 flex-col overflow-hidden"
        >
            <RecordCardHeader
                recordTitle={tersimpan?.kode ?? 'Baru'}
                editing={!readOnly}
                recordUrl={
                    penerimaanId
                        ? `/management-aset/inventarisasi-aset/penerimaan/${penerimaanId}`
                        : undefined
                }
                onEdit={
                    mode === 'view' && !selesai && can('update')
                        ? () => bukaPenerimaanUbah(String(penerimaanId))
                        : undefined
                }
                onDelete={
                    mode === 'view' && !selesai && can('archive')
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
                {mode === 'view' && !selesai && can('update') && !embedded && (
                    <ActionButton
                        action="edit"
                        type="button"
                        onClick={() => bukaPenerimaanUbah(String(penerimaanId))}
                    />
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
                {mode === 'view' && !selesai && can('archive') && !embedded && (
                    <ActionButton
                        action="archive"
                        type="button"
                        onClick={() => setKonfirmasiArsip(true)}
                    />
                )}
                {(mode !== 'view' || editingSerial) && (
                    <>
                        <ActionButton
                            action="save"
                            type="button"
                            onClick={() =>
                                void (editingSerial
                                    ? simpanNomorSeri()
                                    : simpan())
                            }
                            disabled={menyimpan}
                            pending={menyimpan}
                        />
                        <ActionButton
                            action="cancel"
                            disabled={menyimpan}
                            type="button"
                            onClick={() => {
                                if (editingSerial) {
                                    setTerbit(savedIssuedAssets.current);
                                    clearSaveFeedback();
                                } else if (penerimaanId) {
                                    bukaPenerimaan(penerimaanId);
                                } else {
                                    bukaPenerimaanDaftar();
                                }
                            }}
                        />
                    </>
                )}
                {mode === 'view' &&
                    tersimpan?.status === 'selesai' &&
                    penerimaanId && (
                        <CancellationAction
                            resource="penerimaan-aset"
                            documentId={penerimaanId}
                            permissions={permissions}
                            awaitingApproval={
                                tersimpan.cancellation?.status === 'pending'
                            }
                            onComplete={() => {
                                setReload((value) => value + 1);
                                onSaved?.(penerimaanId);
                            }}
                        />
                    )}
                <FieldHint
                    hint={
                        <>
                            Perusahaan mengikuti konteks aktif Anda. Satu
                            dokumen adalah satu kedatangan: tiap unit yang
                            datang menjadi satu aset dengan kodenya sendiri saat
                            dokumen diselesaikan.
                        </>
                    }
                >
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        aria-label="Tentang penerimaan aset"
                    >
                        <Info aria-hidden="true" />
                    </Button>
                </FieldHint>
            </RecordActionBar>
            <CancellationStatus summary={tersimpan?.cancellation} />

            <div
                data-page-dialog-body
                inert={menyimpan || undefined}
                ref={panelRef}
                className="min-h-0 flex-1 overflow-y-auto"
            >
                <div className="space-y-5 p-5">
                    {saveError && (
                        <Alert variant="destructive" role="alert">
                            <TriangleAlert />
                            <AlertTitle>Proses belum berhasil</AlertTitle>
                            <AlertDescription>{saveError}</AlertDescription>
                        </Alert>
                    )}

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
                        value={feedbackSections}
                        onValueChange={setFeedbackSections}
                    >
                        <CollapsibleSection
                            value="kedatangan"
                            title="Kedatangan"
                            summary={tanggalTampil(record.tanggal)}
                        >
                            <FormGrid>
                                <FormControl
                                    data-error-field="tanggal"
                                    hint={
                                        (pesan('tanggal') || saldoAwal) &&
                                        (pesan('tanggal') ? undefined : (
                                            <>
                                                {
                                                    'Tanggal aset diperoleh di sistem lama, paling lambat tanggal cutover. Jurnal saldo awalnya bertanggal cutover.'
                                                }
                                            </>
                                        ))
                                    }
                                    error={
                                        (pesan('tanggal') || saldoAwal) &&
                                        (pesan('tanggal') ? (
                                            <>{pesan('tanggal')}</>
                                        ) : undefined)
                                    }
                                >
                                    <DatePicker
                                        label={
                                            saldoAwal
                                                ? 'Tanggal perolehan'
                                                : 'Tanggal terima'
                                        }

                                        required
                                        readOnly={readOnly}
                                        value={record.tanggal ?? ''}
                                        onValueChange={(value) =>
                                            setRecord({
                                                ...record,
                                                tanggal: value,
                                            })
                                        }
                                        portalContainer={
                                            portalContainer ?? panelRef
                                        }
                                    />
                                </FormControl>

                                <FormControl
                                    data-error-field="tanggal_siap_pakai"
                                    hint={
                                        pesan(
                                            'tanggal_siap_pakai',
                                        ) ? undefined : (
                                            <>
                                                {saldoAwal
                                                    ? 'Tanggal aset mulai dipakai di sistem lama. Penyusutannya di sini berlanjut mulai cutover.'
                                                    : 'Penyusutan dimulai dari tanggal ini, bukan dari tanggal barang tiba. Kosongkan bila keduanya sama.'}
                                            </>
                                        )
                                    }
                                    error={
                                        pesan('tanggal_siap_pakai') ? (
                                            <>{pesan('tanggal_siap_pakai')}</>
                                        ) : undefined
                                    }
                                >
                                    <DatePicker
                                        label="Tanggal siap dipakai"

                                        readOnly={readOnly}
                                        value={record.tanggal_siap_pakai ?? ''}
                                        onValueChange={(value) =>
                                            setRecord({
                                                ...record,
                                                tanggal_siap_pakai: value,
                                            })
                                        }
                                        portalContainer={
                                            portalContainer ?? panelRef
                                        }
                                    />
                                </FormControl>

                                <FormControl
                                    hint={
                                        lokasi.error ? undefined : (
                                            <>
                                                {
                                                    'Lokasi yang dipetakan ke unit organisasi menentukan dimensi keuangan aset.'
                                                }
                                            </>
                                        )
                                    }
                                    error={
                                        lokasi.error ? (
                                            <>{lokasi.error}</>
                                        ) : undefined
                                    }
                                >
                                    {pilihan(
                                        'Lokasi awal',
                                        lokasi,
                                        record.lokasi_aset_id,
                                        (id) =>
                                            setRecord({
                                                ...record,
                                                lokasi_aset_id: id,
                                                // Unit kerja bawaan lokasi mengisi unit pengguna;
                                                // pengguna tetap boleh menggantinya sesudah ini.
                                                responsible_org_unit_id:
                                                    defaultDepartmentOf(
                                                        lokasi.options.find(
                                                            (option) =>
                                                                option.id ===
                                                                id,
                                                        ),
                                                    ) ??
                                                    record.responsible_org_unit_id,
                                            }),
                                        { placeholder: 'Tanpa lokasi' },
                                    )}
                                </FormControl>

                                <FormControl
                                    data-error-field="currency_code"
                                    error={
                                        pesan('currency_code') && (
                                            <>{pesan('currency_code')}</>
                                        )
                                    }
                                >
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
                                </FormControl>
                            </FormGrid>
                        </CollapsibleSection>

                        <CollapsibleSection
                            value="vendor"
                            title="Cara perolehan dan vendor"
                            summary={
                                saldoAwal
                                    ? 'Saldo awal'
                                    : hibah
                                      ? 'Hibah'
                                      : (tersimpan?.vendor?.name ?? 'Pembelian')
                            }
                        >
                            <FormGrid>
                                <FormControl
                                    data-error-field="cara_perolehan"
                                    hint={
                                        pesan('cara_perolehan') ? undefined : (
                                            <>
                                                {saldoAwal
                                                    ? 'Aset lama dari sistem sebelumnya: jurnalnya saldo awal, dengan akumulasi penyusutan sampai cutover.'
                                                    : 'Hibah tidak ditagih vendor: jurnalnya ke akun lawan hibah, bukan hutang.'}
                                            </>
                                        )
                                    }
                                    error={
                                        pesan('cara_perolehan') ? (
                                            <>{pesan('cara_perolehan')}</>
                                        ) : undefined
                                    }
                                >
                                    {readOnly ? (
                                        <Input
                                            label="Cara perolehan"
                                            readOnly
                                            value={
                                                CARA_PEROLEHAN.find(
                                                    (cara) =>
                                                        cara.value ===
                                                        record.cara_perolehan,
                                                )?.label ?? 'Pembelian'
                                            }
                                        />
                                    ) : (
                                        <Select
                                            label="Cara perolehan"
                                            required
                                            items={CARA_PEROLEHAN}
                                            value={
                                                record.cara_perolehan ??
                                                'pembelian'
                                            }
                                            ariaLabel="Cara perolehan"
                                            portalContainer={
                                                portalContainer ?? panelRef
                                            }
                                            onValueChange={(cara) =>
                                                setRecord({
                                                    ...record,
                                                    cara_perolehan: (cara ??
                                                        'pembelian') as CaraPerolehan,
                                                })
                                            }
                                        />
                                    )}
                                </FormControl>

                                {!tanpaVendor && (
                                    <VendorPicker
                                        legalEntityId={
                                            tersimpan?.legal_entity_id ??
                                            context.legal_entity_id
                                        }
                                        value={record.vendor_id ?? ''}
                                        saved={tersimpan?.vendor ?? null}
                                        readOnly={readOnly}
                                        error={pesan('vendor_id')}
                                        portal={portalContainer ?? panelRef}
                                        onChange={(vendorId) =>
                                            setRecord({
                                                ...record,
                                                vendor_id: vendorId,
                                            })
                                        }
                                    />
                                )}

                                {!tanpaVendor && (
                                    <FormControl
                                        data-error-field="vendor_invoice_reference"
                                        error={
                                            pesan(
                                                'vendor_invoice_reference',
                                            ) && (
                                                <>
                                                    {pesan(
                                                        'vendor_invoice_reference',
                                                    )}
                                                </>
                                            )
                                        }
                                    >
                                        <Input
                                            label="Nomor faktur vendor"
                                            maxLength={80}
                                            readOnly={readOnly}
                                            value={
                                                record.vendor_invoice_reference ??
                                                ''
                                            }
                                            onChange={(event) =>
                                                setRecord({
                                                    ...record,
                                                    vendor_invoice_reference:
                                                        event.target.value,
                                                })
                                            }
                                        />
                                    </FormControl>
                                )}

                                <FormControl
                                    data-error-field="purchase_order_reference"
                                    hint={
                                        pesan(
                                            'purchase_order_reference',
                                        ) ? undefined : (
                                            <>
                                                {
                                                    'Isi nomor pada dokumen PO. Kosongkan jika tidak ada.'
                                                }
                                            </>
                                        )
                                    }
                                    error={
                                        pesan('purchase_order_reference') ? (
                                            <>
                                                {pesan(
                                                    'purchase_order_reference',
                                                )}
                                            </>
                                        ) : undefined
                                    }
                                >
                                    <Input
                                        label="Nomor PO"
                                        maxLength={80}
                                        readOnly={readOnly}
                                        value={
                                            record.purchase_order_reference ??
                                            ''
                                        }
                                        onChange={(event) =>
                                            setRecord({
                                                ...record,
                                                purchase_order_reference:
                                                    event.target.value,
                                            })
                                        }
                                    />
                                </FormControl>

                                {!tanpaVendor && (
                                    <FormControl
                                        data-error-field="vendor_invoice_date"
                                        hint={
                                            pesan(
                                                'vendor_invoice_date',
                                            ) ? undefined : (
                                                <>
                                                    {
                                                        'Menjadi tanggal dokumen jurnalnya. Kosongkan bila fakturnya belum datang.'
                                                    }
                                                </>
                                            )
                                        }
                                        error={
                                            pesan('vendor_invoice_date') ? (
                                                <>
                                                    {pesan(
                                                        'vendor_invoice_date',
                                                    )}
                                                </>
                                            ) : undefined
                                        }
                                    >
                                        <DatePicker
                                            label="Tanggal faktur vendor"

                                            readOnly={readOnly}
                                            value={
                                                record.vendor_invoice_date ?? ''
                                            }
                                            onValueChange={(value) =>
                                                setRecord({
                                                    ...record,
                                                    vendor_invoice_date: value,
                                                })
                                            }
                                            portalContainer={
                                                portalContainer ?? panelRef
                                            }
                                        />
                                    </FormControl>
                                )}
                            </FormGrid>
                        </CollapsibleSection>

                        <CollapsibleSection
                            value="pihak"
                            title="Unit dan penanggung jawab"
                            summary={
                                tersimpan?.responsible_org_unit_nama ??
                                undefined
                            }
                        >
                            <FormGrid>
                                <FormControl
                                    hint={
                                        unitKerja.error ? undefined : (
                                            <>
                                                {
                                                    'Unit kerja yang menanggung seluruh aset pada dokumen ini setelah diterima. Terisi sendiri dari unit kerja bawaan lokasi awal, dan masih dapat diganti.'
                                                }
                                            </>
                                        )
                                    }
                                    error={
                                        unitKerja.error ? (
                                            <>{unitKerja.error}</>
                                        ) : undefined
                                    }
                                >
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
                                </FormControl>

                                <FormControl
                                    hint={
                                        <>
                                            Loket atau gudang yang menerima
                                            fisiknya. Sering berbeda dari unit
                                            pengguna.
                                        </>
                                    }
                                >
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
                                </FormControl>

                                <FormControl
                                    error={
                                        anggota.error ? (
                                            <>{anggota.error}</>
                                        ) : undefined
                                    }
                                >
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
                                    {anggota.error ? undefined : null}
                                </FormControl>

                                <FormControl
                                    hint={
                                        <>
                                            Tercatat sebagai pemegang pertama
                                            pada riwayat penempatan tiap aset.
                                        </>
                                    }
                                >
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
                                </FormControl>
                            </FormGrid>
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
                                <div className="flex items-center gap-1 text-sm">
                                    <span>Nilai per unit</span>
                                    <FieldHint
                                        hint={
                                            <>
                                                {saldoAwal
                                                    ? 'Akumulasi dan periode berjalan adalah angka buku yang di-post ke finance sampai cutover; buku lain diatur di bagian Saldo awal per buku. Nilai dan akumulasi diisi per unit, bukan total. '
                                                    : 'Nilai dan PPN diisi per unit, bukan total. '}
                                                Ambang kapitalisasi group
                                                dibandingkan terhadap nilai satu
                                                aset — dua puluh kursi lima
                                                ratus ribu tidak melewati ambang
                                                sepuluh juta hanya karena datang
                                                bersamaan.
                                            </>
                                        }
                                    >
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon-sm"
                                            aria-label="Bantuan field"
                                        >
                                            <Info aria-hidden="true" />
                                        </Button>
                                    </FieldHint>
                                </div>
                                {(() => {
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
                                            header: '#',
                                            width: 40,
                                            cell: ({ index }) => (
                                                <div className="text-muted-foreground">
                                                    {index + 1}
                                                </div>
                                            ),
                                        } satisfies DataTableColumn<
                                            (typeof sdkRows)[number]
                                        >,
                                        ...(record.cara_perolehan ===
                                        'pembelian'
                                            ? [
                                                  {
                                                      content: 'control',
                                                      id: 'column-1',
                                                      header: 'Permintaan asal',
                                                      minWidth: 256,
                                                      cell: ({
                                                          baris,
                                                          index,
                                                      }) => (
                                                          <>
                                                              {readOnly ? (
                                                                  <span>
                                                                      {baris.purchase_request_code ??
                                                                          'Tanpa permintaan asal'}
                                                                  </span>
                                                              ) : (
                                                                  <PurchaseRequestPicker
                                                                      companyId={
                                                                          record.legal_entity_id ??
                                                                          context.legal_entity_id ??
                                                                          undefined
                                                                      }
                                                                      initialRequestId={
                                                                          baris.purchase_request_id
                                                                      }
                                                                      value={
                                                                          baris.permintaan_pembelian_detail_id
                                                                      }
                                                                      readOnly={
                                                                          readOnly
                                                                      }
                                                                      portalContainer={
                                                                          portalContainer ??
                                                                          panelRef
                                                                      }
                                                                      permissions={
                                                                          permissions
                                                                      }
                                                                      orgUnitId={
                                                                          context.org_unit_id
                                                                      }
                                                                      rows={
                                                                          record.details
                                                                      }
                                                                      rowIndex={
                                                                          index
                                                                      }
                                                                      onSelectLines={(
                                                                          request,
                                                                          ids,
                                                                      ) =>
                                                                          setRecord(
                                                                              (
                                                                                  previous,
                                                                              ) => ({
                                                                                  ...previous,
                                                                                  details:
                                                                                      appendRequestLines(
                                                                                          previous.details,
                                                                                          index,
                                                                                          request,
                                                                                          ids,
                                                                                          barisKosong,
                                                                                      ),
                                                                              }),
                                                                          )
                                                                      }
                                                                      onChange={(
                                                                          line,
                                                                          request,
                                                                      ) =>
                                                                          ubahBaris(
                                                                              index,
                                                                              line &&
                                                                                  request
                                                                                  ? {
                                                                                        ...requestLinePatch(
                                                                                            line,
                                                                                            request,
                                                                                        ),
                                                                                        atribut:
                                                                                            line.jenis_aset_id ===
                                                                                            baris.jenis_aset_id
                                                                                                ? baris.atribut
                                                                                                : [],
                                                                                        model_aset_id:
                                                                                            '',
                                                                                        model_number:
                                                                                            '',
                                                                                    }
                                                                                  : {
                                                                                        permintaan_pembelian_detail_id:
                                                                                            '',
                                                                                        purchase_request_id:
                                                                                            null,
                                                                                        purchase_request_code:
                                                                                            null,
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
                                              ]
                                            : []),
                                        {
                                            content: 'control',
                                            id: 'column-2',
                                            header: 'Nama barang',
                                            minWidth: 208,
                                            cell: ({ baris, index }) => (
                                                <>
                                                    <FormControl>
                                                        <Input
                                                            aria-label="Nama barang"
                                                            maxLength={150}
                                                            readOnly={readOnly}
                                                            value={baris.nama}
                                                            onChange={(event) =>
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
                                                    </FormControl>
                                                </>
                                            ),
                                        } satisfies DataTableColumn<
                                            (typeof sdkRows)[number]
                                        >,
                                        {
                                            content: 'control',
                                            id: 'column-3',
                                            header: 'Group aset',
                                            minWidth: 176,
                                            cell: ({ baris, index }) => (
                                                <>
                                                    {pilihan(
                                                        'Group aset',
                                                        grup,
                                                        baris.group_aset_id,
                                                        (id) =>
                                                            ubahBaris(index, {
                                                                group_aset_id:
                                                                    id,
                                                            }),
                                                    )}
                                                </>
                                            ),
                                        } satisfies DataTableColumn<
                                            (typeof sdkRows)[number]
                                        >,
                                        {
                                            content: 'control',
                                            id: 'column-4',
                                            header: 'Jenis aset',
                                            minWidth: 176,
                                            cell: ({ baris, index }) => (
                                                <>
                                                    {pilihan(
                                                        'Jenis aset',
                                                        jenis,
                                                        baris.jenis_aset_id,
                                                        (id) =>
                                                            ubahBaris(index, {
                                                                jenis_aset_id:
                                                                    id,
                                                                atribut:
                                                                    id ===
                                                                    baris.jenis_aset_id
                                                                        ? baris.atribut
                                                                        : [],
                                                                kondisi_aset_id:
                                                                    '',
                                                                model_aset_id:
                                                                    '',
                                                                model_number:
                                                                    '',
                                                            }),
                                                    )}
                                                </>
                                            ),
                                        } satisfies DataTableColumn<
                                            (typeof sdkRows)[number]
                                        >,
                                        {
                                            content: 'control',
                                            id: 'column-5',
                                            header: 'Kondisi',
                                            minWidth: 160,
                                            cell: ({ baris, index }) => (
                                                <>
                                                    {readOnly ? (
                                                        (kondisi.options.find(
                                                            (item) =>
                                                                item.id ===
                                                                baris.kondisi_aset_id,
                                                        )?.nama ?? '—')
                                                    ) : (
                                                        <AssetConditionSelect
                                                            permissions={
                                                                permissions
                                                            }
                                                            assetTypeId={
                                                                baris.jenis_aset_id
                                                            }
                                                            value={
                                                                baris.kondisi_aset_id
                                                            }
                                                            label="Kondisi"
                                                            portalContainer={
                                                                portalContainer ??
                                                                panelRef
                                                            }
                                                            onChange={(value) =>
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
                                            content: 'control',
                                            id: 'column-6',
                                            header: 'Jumlah',
                                            width: 96,
                                            align: 'right',
                                            cell: ({ baris, index }) => (
                                                <>
                                                    <FormControl>
                                                        <Input
                                                            aria-label="Jumlah unit"
                                                            type="number"
                                                            min={1}
                                                            readOnly={readOnly}
                                                            value={String(
                                                                baris.jumlah,
                                                            )}
                                                            onChange={(event) =>
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
                                                    </FormControl>
                                                </>
                                            ),
                                        } satisfies DataTableColumn<
                                            (typeof sdkRows)[number]
                                        >,
                                        {
                                            content: 'control',
                                            id: 'column-7',
                                            header: 'Nilai / unit',
                                            minWidth: 160,
                                            align: 'right',
                                            cell: ({ baris, index }) => (
                                                <>
                                                    <FormControl>
                                                        <Input
                                                            aria-label="Nilai per unit"
                                                            type="number"
                                                            min={0}
                                                            readOnly={readOnly}
                                                            value={String(
                                                                baris.nilai_per_unit,
                                                            )}
                                                            onChange={(event) =>
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
                                                    </FormControl>
                                                </>
                                            ),
                                        } satisfies DataTableColumn<
                                            (typeof sdkRows)[number]
                                        >,
                                        ...(!saldoAwal
                                            ? [
                                                  {
                                                      content: 'control',
                                                      id: 'column-8',
                                                      header: 'PPN / unit',
                                                      minWidth: 144,
                                                      align: 'right',
                                                      cell: ({
                                                          baris,
                                                          index,
                                                      }) => (
                                                          <>
                                                              <FormControl>
                                                                  <Input
                                                                      aria-label="PPN per unit"
                                                                      type="number"
                                                                      min={0}
                                                                      readOnly={
                                                                          readOnly
                                                                      }
                                                                      value={String(
                                                                          baris.ppn_per_unit ??
                                                                              '',
                                                                      )}
                                                                      onChange={(
                                                                          event,
                                                                      ) =>
                                                                          ubahBaris(
                                                                              index,
                                                                              {
                                                                                  ppn_per_unit:
                                                                                      event
                                                                                          .target
                                                                                          .value,
                                                                              },
                                                                          )
                                                                      }
                                                                  />
                                                              </FormControl>
                                                          </>
                                                      ),
                                                  } satisfies DataTableColumn<
                                                      (typeof sdkRows)[number]
                                                  >,
                                              ]
                                            : []),
                                        {
                                            content: 'control',
                                            id: 'column-9',
                                            header: 'Residu / unit',
                                            minWidth: 160,
                                            align: 'right',
                                            cell: ({ baris, index }) => (
                                                <>
                                                    <FormControl>
                                                        <Input
                                                            aria-label="Residu per unit"
                                                            type="number"
                                                            min={0}
                                                            readOnly={readOnly}
                                                            value={String(
                                                                baris.residu_per_unit,
                                                            )}
                                                            onChange={(event) =>
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
                                                    </FormControl>
                                                </>
                                            ),
                                        } satisfies DataTableColumn<
                                            (typeof sdkRows)[number]
                                        >,
                                        ...(saldoAwal
                                            ? [
                                                  {
                                                      content: 'control',
                                                      id: 'column-10',
                                                      header: 'Akumulasi / unit',
                                                      minWidth: 160,
                                                      align: 'right',
                                                      cell: ({
                                                          baris,
                                                          index,
                                                      }) => (
                                                          <>
                                                              <FormControl>
                                                                  <Input
                                                                      aria-label="Akumulasi per unit"
                                                                      type="number"
                                                                      min={0}
                                                                      readOnly={
                                                                          readOnly
                                                                      }
                                                                      value={String(
                                                                          baris.akumulasi_per_unit ??
                                                                              '',
                                                                      )}
                                                                      onChange={(
                                                                          event,
                                                                      ) =>
                                                                          ubahBaris(
                                                                              index,
                                                                              {
                                                                                  akumulasi_per_unit:
                                                                                      event
                                                                                          .target
                                                                                          .value,
                                                                              },
                                                                          )
                                                                      }
                                                                  />
                                                              </FormControl>
                                                          </>
                                                      ),
                                                  } satisfies DataTableColumn<
                                                      (typeof sdkRows)[number]
                                                  >,
                                              ]
                                            : []),
                                        ...(saldoAwal
                                            ? [
                                                  {
                                                      content: 'control',
                                                      id: 'column-11',
                                                      header: 'Periode berjalan',
                                                      minWidth: 128,
                                                      align: 'right',
                                                      cell: ({
                                                          baris,
                                                          index,
                                                      }) => (
                                                          <>
                                                              <FormControl>
                                                                  <Input
                                                                      aria-label="Periode berjalan"
                                                                      type="number"
                                                                      min={0}
                                                                      step={1}
                                                                      readOnly={
                                                                          readOnly
                                                                      }
                                                                      value={String(
                                                                          baris.periode_berjalan ??
                                                                              '',
                                                                      )}
                                                                      onChange={(
                                                                          event,
                                                                      ) =>
                                                                          ubahBaris(
                                                                              index,
                                                                              {
                                                                                  periode_berjalan:
                                                                                      event
                                                                                          .target
                                                                                          .value,
                                                                              },
                                                                          )
                                                                      }
                                                                  />
                                                              </FormControl>
                                                          </>
                                                      ),
                                                  } satisfies DataTableColumn<
                                                      (typeof sdkRows)[number]
                                                  >,
                                              ]
                                            : []),
                                        ...(!readOnly
                                            ? [
                                                  {
                                                      id: 'column-12',
                                                      header: '',
                                                      width: 48,
                                                      cell: ({ index }) => (
                                                          <>
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
                                                baris.id ??
                                                baris.clientKey ??
                                                `baris-${index}`
                                            }
                                            onAddRow={
                                                !readOnly
                                                    ? () =>
                                                          setRecord(
                                                              (sebelumnya) => ({
                                                                  ...sebelumnya,
                                                                  details: [
                                                                      ...sebelumnya.details,
                                                                      barisKosong(),
                                                                  ],
                                                              }),
                                                          )
                                                    : undefined
                                            }
                                            addRowLabel="Tambah"
                                        />
                                    );
                                })()}
                            </div>
                        </CollapsibleSection>

                        <CollapsibleSection
                            value="atribut"
                            title="Isian tambahan barang"
                        >
                            <div className="space-y-4">
                                {record.details.map(
                                    (line, index) =>
                                        line.jenis_aset_id && (
                                            <div
                                                key={`${line.id ?? line.clientKey ?? index}-${line.jenis_aset_id}`}
                                                className="space-y-3"
                                            >
                                                <p className="text-sm font-medium">
                                                    Baris {index + 1}:{' '}
                                                    {line.nama || 'Barang'}
                                                </p>
                                                <ReceiptAttributes
                                                    rowKey={
                                                        line.id ??
                                                        line.clientKey ??
                                                        String(index)
                                                    }
                                                    typeId={line.jenis_aset_id}
                                                    values={line.atribut ?? []}
                                                    readOnly={readOnly}
                                                    portalContainer={
                                                        portalContainer ??
                                                        panelRef
                                                    }
                                                    onChange={(values) =>
                                                        ubahBaris(index, {
                                                            atribut: values,
                                                        })
                                                    }
                                                />
                                            </div>
                                        ),
                                )}
                            </div>
                        </CollapsibleSection>

                        {saldoAwal && (
                            <CollapsibleSection
                                value="saldo-awal"
                                title="Saldo awal per buku"
                                summary={`${record.details.filter((baris) => (baris.saldo_awal_buku ?? []).length > 0).length} baris diisi tersendiri`}
                            >
                                <OpeningBalanceBooks
                                    details={record.details}
                                    books={bukuGroup}
                                    readOnly={readOnly}
                                    pesan={pesan}
                                    onChange={(index, saldoAwalBuku) =>
                                        ubahBaris(index, {
                                            saldo_awal_buku: saldoAwalBuku,
                                        })
                                    }
                                />
                            </CollapsibleSection>
                        )}

                        <CollapsibleSection
                            value="pabrikan"
                            title="Pabrikan dan model per baris"
                            summary={`${record.details.filter((baris) => baris.pabrikan_aset_id).length} baris terisi`}
                        >
                            <div className="space-y-4">
                                {record.details.map((baris, index) => (
                                    <FormGrid
                                        key={baris.id ?? `pabrikan-${index}`}
                                        className="rounded-md border px-4 py-3 sm:grid-cols-3"
                                    >
                                        <div>
                                            <p className="text-sm font-medium">
                                                Baris {index + 1}
                                            </p>
                                            <p className="text-muted-foreground text-sm">
                                                {baris.nama || 'Tanpa nama'}
                                            </p>
                                        </div>
                                        <FormControl>
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
                                        </FormControl>
                                        <FormControl>
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
                                        </FormControl>
                                    </FormGrid>
                                ))}
                            </div>
                        </CollapsibleSection>

                        <CollapsibleSection
                            value="dokumen-pendukung"
                            title="Dokumen pendukung"
                            summary="PO, invoice, dan bukti penerimaan"
                        >
                            <p className="text-muted-foreground mb-3 text-sm">
                                Saat penerimaan diselesaikan, lampiran ikut
                                tersedia pada aset yang dihasilkan.
                            </p>
                            {tersimpan ? (
                                <RecordAttachments
                                    key={tersimpan.id}
                                    recordType="aset_tr_penerimaan_aset"
                                    recordId={tersimpan.id}
                                />
                            ) : !canAttach ? (
                                <p className="text-muted-foreground text-sm">
                                    Anda belum memiliki hak melampirkan dokumen
                                    pada penerimaan.
                                </p>
                            ) : attachmentLimits ? (
                                <DraftRecordAttachments
                                    files={draftAttachments}
                                    limits={attachmentLimits}
                                    disabled={menyimpan}
                                    error={
                                        Object.entries(galat).find(
                                            ([key]) =>
                                                key === 'attachments' ||
                                                key.startsWith('attachments.'),
                                        )?.[1]?.[0]
                                    }
                                    onChange={(files) => {
                                        attachmentRevision.current += 1;
                                        setDraftAttachments(files);
                                    }}
                                />
                            ) : attachmentLimitsError ? (
                                <Alert variant="destructive">
                                    <AlertDescription>
                                        {attachmentLimitsError}
                                    </AlertDescription>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        disabled={menyimpan}
                                        onClick={() => {
                                            setAttachmentLimitsError(null);
                                            setAttachmentLimitsReload(
                                                (value) => value + 1,
                                            );
                                        }}
                                    >
                                        Coba lagi
                                    </Button>
                                </Alert>
                            ) : (
                                <p
                                    role="status"
                                    className="text-muted-foreground text-sm"
                                >
                                    Memuat pilihan lampiran…
                                </p>
                            )}
                        </CollapsibleSection>

                        <CollapsibleSection
                            value="keterangan"
                            title="Keterangan"
                            summary={record.keterangan || undefined}
                        >
                            <FormControl
                                data-error-field="keterangan"
                                hint={
                                    <>
                                        Mis. nomor surat jalan atau nama
                                        pengirim.
                                    </>
                                }
                            >
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
                            </FormControl>
                        </CollapsibleSection>

                        {mode === 'view' && (
                            <CollapsibleSection
                                value="jurnal"
                                title={
                                    saldoAwal
                                        ? 'Jurnal saldo awal'
                                        : 'Jurnal perolehan'
                                }
                                summary={
                                    selesai
                                        ? labelStatusPosting(
                                              tersimpan?.posting?.status ??
                                                  null,
                                          )
                                        : penghalang.length > 0
                                          ? 'Belum dapat diselesaikan'
                                          : labelStatusPosting(
                                                pratinjauSekarang?.status ??
                                                    null,
                                            )
                                }
                            >
                                <AcquisitionJournal
                                    pratinjau={pratinjauSekarang}
                                    memuat={memuatPratinjau}
                                    selesai={selesai}
                                    posting={tersimpan?.posting ?? null}
                                />
                            </CollapsibleSection>
                        )}

                        {selesai && (
                            <CollapsibleSection
                                value="terbit"
                                title="Aset terbit"
                                summary={`${terbit.length} aset`}
                            >
                                <div className="space-y-3">
                                    {(() => {
                                        const sdkRows = terbit.map(
                                            (aset, index) => {
                                                return { aset, index };
                                            },
                                        );
                                        const sdkColumns: DataTableColumn<
                                            (typeof sdkRows)[number]
                                        >[] = [
                                            {
                                                id: 'column-0',
                                                header: 'Kode aset',
                                                width: 176,
                                                cell: ({ aset }) => (
                                                    <div className="text-primary font-medium">
                                                        {aset.kode}
                                                    </div>
                                                ),
                                            } satisfies DataTableColumn<
                                                (typeof sdkRows)[number]
                                            >,
                                            {
                                                id: 'column-1',
                                                header: 'Nama',
                                                cell: ({ aset }) => (
                                                    <>{aset.nama}</>
                                                ),
                                            } satisfies DataTableColumn<
                                                (typeof sdkRows)[number]
                                            >,
                                            {
                                                content: 'control',
                                                id: 'column-2',
                                                header: 'Nomor seri',
                                                width: 256,
                                                cell: ({ aset, index }) => (
                                                    <>
                                                        <FormControl>
                                                            <Input
                                                                aria-label={`Nomor seri ${aset.kode}`}
                                                                maxLength={150}
                                                                placeholder="Belum bernomor seri"
                                                                readOnly={
                                                                    !canCorrect ||
                                                                    menyimpan
                                                                }
                                                                value={
                                                                    aset.serial_number ??
                                                                    ''
                                                                }
                                                                onChange={(
                                                                    event,
                                                                ) =>
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
                                                        </FormControl>
                                                    </>
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
                                                getRowKey={({ aset }) =>
                                                    aset.id
                                                }
                                            />
                                        );
                                    })()}
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
                            {penghalang.length > 0
                                ? 'Penerimaan ini belum dapat diselesaikan. Benahi dulu hal berikut, lalu coba lagi.'
                                : `${totalAset} aset akan terdaftar dengan kodenya masing-masing dan mulai disusutkan, dan jurnal perolehannya terbit bersamaan. Nomor aset tidak dapat ditarik kembali, dan dokumen ini tidak dapat diubah lagi sesudahnya.`}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    {penghalang.map((blocker) => (
                        <p
                            key={blocker.field}
                            className="text-destructive text-sm"
                        >
                            {blocker.message}
                        </p>
                    ))}
                    <AlertDialogFooter>
                        <AlertDialogCancel>Batal</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={() => void selesaikan()}
                            disabled={penghalang.length > 0}
                        >
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
                            Delete draf penerimaan?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            Draf ini belum melahirkan aset apa pun, jadi
                            mengarsipkannya tidak mengubah register.
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

/**
 * Nilai dari API tersimpan dengan enam angka di belakang koma; nol di ujungnya tidak berarti apa-apa
 * dan hanya membuat 15000000.000000 terbaca seperti angka lain.
 */
function tanpaNolBelakang(nilai: number | string | undefined): string {
    const teks = String(nilai ?? '');

    return teks.includes('.') ? teks.replace(/\.?0+$/, '') : teks;
}

/** Angka isian sebagai teks desimal apa adanya; kosong berarti nol. */
function angka(nilai: number | string | undefined): string {
    const teks = String(nilai ?? '').trim();

    return teks === '' ? '0' : teks;
}
