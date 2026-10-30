import { ExternalLink, FileText, Package, Plus, Trash2 } from 'lucide-react';
import {
    Suspense,
    lazy,
    useCallback,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import { useWorkDate } from '@/hooks/use-work-date';
import { ActionButton } from '@apperp/ui/action-button';
import { Button } from '@apperp/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@apperp/ui/card';
import { DataTable } from '@apperp/ui/data-table';
import { FieldError, FieldLabel } from '@apperp/ui/field';
import { FormControl } from '@apperp/ui/form-control';
import { InlineLookup } from '@apperp/ui/inline-lookup';
import { Input } from '@apperp/ui/input';
import { NativeSelect, NativeSelectOption } from '@apperp/ui/native-select';
import {
    PageDialog,
    PageDialogBody,
    PageDialogContent,
    PageDialogToolbar,
} from '@apperp/ui/page-dialog';
import { TableCell, TableRow } from '@apperp/ui/table';
import { Textarea } from '@apperp/ui/textarea';
import { useConfirmation } from '@apperp/ui/use-confirmation';
import { useRecordTransition } from '@apperp/ui/use-record-transition';
import { ApiError, api, newIdempotencyKey, saveErrorMessage } from '../../api';
import { useSaveFeedback } from '../../use-save-feedback';

const VendorCreateCard = lazy(async () => {
    const module = await import('@/pages/foundation/vendor/vendors');

    return { default: module.VendorCreateCard };
});

const standardDocColumns = [
    { id: 'no', header: 'No.', cell: () => null },
    { id: 'uraian', header: 'Uraian', cell: () => null },
    { id: 'ada', header: 'Ada', cell: () => null },
    { id: 'tidak', header: 'Tidak', cell: () => null },
];

const additionalDocColumns = [
    { id: 'no', header: 'No.', cell: () => null },
    { id: 'nama_dokumen', header: 'Nama Dokumen', cell: () => null },
    { id: 'keterangan', header: 'Ket.', cell: () => null },
    { id: 'aksi', header: 'Aksi', cell: () => null },
];

const itemFormColumns = [
    { id: 'no', header: 'No.', cell: () => null },
    { id: 'jenis_item', header: 'Jenis Item', cell: () => null },
    { id: 'kode_item', header: 'Kode Item', cell: () => null },
    { id: 'nama_item', header: 'Nama Item', cell: () => null },
    { id: 'satuan', header: 'Satuan', cell: () => null },
    { id: 'kesediaan', header: 'Kesediaan', cell: () => null },
    { id: 'aksi', header: 'Aksi', cell: () => null },
];

export type VendorPrequalificationDocumentRow = {
    id?: string;
    kategori: 'wajib' | 'tambahan';
    urutan: number;
    nama_dokumen: string;
    ada: boolean;
    keterangan?: string | null;
};

export type VendorPrequalificationItemRow = {
    id?: string;
    urutan: number;
    jenis_item: string;
    kode_item: string;
    nama_item: string;
    satuan: string;
    kesediaan: string;
    keterangan?: string | null;
};

export type VendorPrequalificationDetail = {
    id: string;
    nomor_prakualifikasi: string;
    tanggal_prakualifikasi: string;
    vendor_id: string;
    kode_vendor: string;
    nama_vendor: string;
    jenis_vendor?: string | null;
    nama_pic?: string | null;
    npwp_ktp?: string | null;
    telepon?: string | null;
    email?: string | null;
    media_sosial?: string | null;
    alamat?: string | null;
    status_vendor?: string | null;
    status_badan_hukum: string;
    status_kualifikasi: string;
    waktu_kesediaan_item?: string | null;
    jumlah_karyawan?: number | null;
    catatan?: string | null;
    version: number;
    documents: VendorPrequalificationDocumentRow[];
    items: VendorPrequalificationItemRow[];
    created_at?: string;
    updated_at?: string;
};

type VendorLookupItem = {
    id: string;
    number: string;
    name: string;
    tax_number: string | null;
    status: string;
    legal_entity_id: string;
};

export const STANDARD_DOCUMENTS = [
    'Surat Penunjukan Keagenan',
    'Sertifikat Produk/Mutu',
    'Referensi Bank',
    'Kemampuan Modal Usaha',
    'Sertifikat',
    'Akte Pendirian',
    'NIB',
    'TDP (Tanda Daftar Perusahaan)',
    'SIUP',
    'SITU',
];

export const QUALIFICATION_STATUS_OPTIONS = [
    'Dalam Proses',
    'Lolos',
    'Tidak Lolos',
] as const;

type FormState = {
    tanggal_prakualifikasi: string;
    nomor_prakualifikasi: string;
    vendor_id: string;
    kode_vendor: string;
    nama_vendor: string;
    jenis_vendor: string;
    nama_pic: string;
    npwp_ktp: string;
    telepon: string;
    email: string;
    media_sosial: string;
    alamat: string;
    status_vendor: string;
    status_badan_hukum: string;
    status_kualifikasi: string;
    waktu_kesediaan_item: string;
    jumlah_karyawan: string;
    catatan: string;
    standardDocs: VendorPrequalificationDocumentRow[];
    extraDocs: VendorPrequalificationDocumentRow[];
    items: VendorPrequalificationItemRow[];
};

function createEmptyStandardDocs(): VendorPrequalificationDocumentRow[] {
    return STANDARD_DOCUMENTS.map((name, idx) => ({
        kategori: 'wajib',
        urutan: idx + 1,
        nama_dokumen: name,
        ada: false,
        keterangan: '',
    }));
}

function createEmptyItemRow(urutan = 1): VendorPrequalificationItemRow {
    return {
        urutan,
        jenis_item: '',
        kode_item: '',
        nama_item: '',
        satuan: '',
        kesediaan: '',
        keterangan: '',
    };
}

function isItemRowTouched(row: VendorPrequalificationItemRow): boolean {
    return (
        row.jenis_item.trim() !== '' ||
        row.kode_item.trim() !== '' ||
        row.nama_item.trim() !== '' ||
        row.satuan.trim() !== '' ||
        row.kesediaan.trim() !== '' ||
        (row.keterangan ?? '').trim() !== ''
    );
}

function isExtraDocTouched(row: VendorPrequalificationDocumentRow): boolean {
    return (
        row.nama_dokumen.trim() !== '' || (row.keterangan ?? '').trim() !== ''
    );
}

function buildInitialFormState(
    initialData: VendorPrequalificationDetail | null | undefined,
    defaultDate: string,
): FormState {
    if (!initialData) {
        return {
            tanggal_prakualifikasi: defaultDate,
            nomor_prakualifikasi: '',
            vendor_id: '',
            kode_vendor: '',
            nama_vendor: '',
            jenis_vendor: '',
            nama_pic: '',
            npwp_ktp: '',
            telepon: '',
            email: '',
            media_sosial: '',
            alamat: '',
            status_vendor: 'Vendor Resmi',
            status_badan_hukum: 'Badan Hukum',
            status_kualifikasi: 'Dalam Proses',
            waktu_kesediaan_item: '',
            jumlah_karyawan: '',
            catatan: '',
            standardDocs: createEmptyStandardDocs(),
            extraDocs: [],
            items: [createEmptyItemRow(1)],
        };
    }

    const savedDocs = initialData.documents ?? [];
    const wajibDocs = savedDocs.filter((d) => d.kategori === 'wajib');
    const tambahanDocs = savedDocs.filter((d) => d.kategori === 'tambahan');

    const usedWajibIds = new Set<string>();
    const standardDocs: VendorPrequalificationDocumentRow[] =
        STANDARD_DOCUMENTS.map((name, idx) => {
            const match = wajibDocs.find(
                (d) =>
                    d.nama_dokumen === name &&
                    (!d.id || !usedWajibIds.has(d.id)),
            );

            if (match?.id) {
                usedWajibIds.add(match.id);
            }

            return {
                id: match?.id,
                kategori: 'wajib',
                urutan: idx + 1,
                nama_dokumen: name,
                ada: match ? Boolean(match.ada) : false,
                keterangan: match?.keterangan ?? '',
            };
        });

    const extraDocs: VendorPrequalificationDocumentRow[] = tambahanDocs.map(
        (d, idx) => ({
            id: d.id,
            kategori: 'tambahan',
            urutan: idx + 1,
            nama_dokumen: d.nama_dokumen ?? '',
            ada: Boolean(d.ada),
            keterangan: d.keterangan ?? '',
        }),
    );

    const savedItems = initialData.items ?? [];
    const items: VendorPrequalificationItemRow[] =
        savedItems.length > 0
            ? savedItems.map((it, idx) => ({
                  id: it.id,
                  urutan: idx + 1,
                  jenis_item: it.jenis_item ?? '',
                  kode_item: it.kode_item ?? '',
                  nama_item: it.nama_item ?? '',
                  satuan: it.satuan ?? '',
                  kesediaan: it.kesediaan ?? '',
                  keterangan: it.keterangan ?? '',
              }))
            : [createEmptyItemRow(1)];

    return {
        tanggal_prakualifikasi:
            initialData.tanggal_prakualifikasi || defaultDate,
        nomor_prakualifikasi: initialData.nomor_prakualifikasi ?? '',
        vendor_id: initialData.vendor_id ?? '',
        kode_vendor: initialData.kode_vendor ?? '',
        nama_vendor: initialData.nama_vendor ?? '',
        jenis_vendor: initialData.jenis_vendor ?? '',
        nama_pic: initialData.nama_pic ?? '',
        npwp_ktp: initialData.npwp_ktp ?? '',
        telepon: initialData.telepon ?? '',
        email: initialData.email ?? '',
        media_sosial: initialData.media_sosial ?? '',
        alamat: initialData.alamat ?? '',
        status_vendor: initialData.status_vendor || 'Vendor Resmi',
        status_badan_hukum: initialData.status_badan_hukum || 'Badan Hukum',
        status_kualifikasi: initialData.status_kualifikasi || 'Dalam Proses',
        waktu_kesediaan_item: initialData.waktu_kesediaan_item ?? '',
        jumlah_karyawan:
            initialData.jumlah_karyawan !== null &&
            initialData.jumlah_karyawan !== undefined
                ? String(initialData.jumlah_karyawan)
                : '',
        catatan: initialData.catatan ?? '',
        standardDocs,
        extraDocs,
        items,
    };
}

type Props = {
    open: boolean;
    onClose: () => void;
    initialData?: VendorPrequalificationDetail | null;
    onSaved: (record: VendorPrequalificationDetail) => void;
    onDelete?: () => void;
    legalEntityId: string | null;
    canCreateCoreVendor: boolean;
};

export function VendorPrequalificationFormDialog({
    open,
    onClose,
    initialData,
    onSaved,
    onDelete,
    legalEntityId,
    canCreateCoreVendor,
}: Props) {
    const { date: workDate } = useWorkDate();
    const isEdit = Boolean(initialData?.id);
    const recordKey = initialData?.id ?? 'new';

    const contentRef = useRef<HTMLDivElement>(null);
    const creationKey = useRef(newIdempotencyKey());

    const baseline = useMemo(
        () => buildInitialFormState(initialData, workDate),
        [initialData, workDate],
    );
    const [form, setForm] = useState<FormState>(() =>
        buildInitialFormState(initialData, workDate),
    );
    const [saving, setSaving] = useState(false);
    const [creatingVendor, setCreatingVendor] = useState(false);

    const policyKey = open && !isEdit ? 'prakualifikasi' : null;
    const [policyResult, setPolicyResult] = useState<{
        key: string;
        allowManual: boolean;
        allowAutomatic: boolean;
        error: string | null;
    } | null>(null);
    const currentPolicy = policyResult?.key === policyKey ? policyResult : null;
    const numberPolicy = {
        allowManual: currentPolicy?.allowManual ?? true,
        allowAutomatic: currentPolicy?.allowAutomatic ?? true,
        loading: policyKey !== null && currentPolicy === null,
        error: currentPolicy?.error ?? null,
    };

    const {
        reportSaveError,
        clearSaveFeedback,
        fieldError: getFieldError,
        feedbackSummary,
    } = useSaveFeedback(contentRef, recordKey);

    const { confirm, confirmation: vendorSwitchConfirmation } =
        useConfirmation();

    useEffect(() => {
        if (policyKey === null) {
            return;
        }

        let active = true;

        api<{ data: { allow_manual: boolean; allow_automatic: boolean } }>(
            '/prakualifikasi/number-policy',
        )
            .then((res) => {
                if (!active) {
                    return;
                }

                setPolicyResult({
                    key: policyKey,
                    allowManual: res.data.allow_manual,
                    allowAutomatic: res.data.allow_automatic,
                    error: null,
                });
            })
            .catch((caught) => {
                if (!active) {
                    return;
                }

                setPolicyResult({
                    key: policyKey,
                    allowManual: false,
                    allowAutomatic: true,
                    error: saveErrorMessage(
                        caught,
                        'memuat kebijakan penomoran prakualifikasi',
                    ),
                });
            });

        return () => {
            active = false;
        };
    }, [policyKey]);

    const loadVendors = useCallback(
        async ({
            query,
            cursor,
            signal,
        }: {
            query: string;
            cursor: string | null;
            signal: AbortSignal;
        }) => {
            if (!legalEntityId) {
                return { items: [] as VendorLookupItem[], nextCursor: null };
            }

            const params = new URLSearchParams();

            if (query.trim() !== '') {
                params.set('q', query.trim());
            }

            if (cursor) {
                params.set('cursor', cursor);
            }

            const body = await api<{
                data?: VendorLookupItem[];
                next_cursor?: string | null;
            }>(`/prakualifikasi/vendors/lookup?${params.toString()}`, {
                signal,
            });

            return {
                items: body.data ?? [],
                nextCursor: body.next_cursor ?? null,
            };
        },
        [legalEntityId],
    );

    const dirty = useMemo(
        () => JSON.stringify(form) !== JSON.stringify(baseline),
        [form, baseline],
    );

    const transition = useRecordTransition(dirty, saving);

    const applyVendorSelection = useCallback(
        (vendor: VendorLookupItem, resetDependentDetails: boolean) => {
            setForm((prev) => ({
                ...prev,
                vendor_id: vendor.id,
                kode_vendor: vendor.number,
                nama_vendor: vendor.name,
                npwp_ktp: vendor.tax_number ?? '',
                jenis_vendor: '',
                nama_pic: '',
                telepon: '',
                email: '',
                media_sosial: '',
                alamat: '',
                ...(resetDependentDetails
                    ? {
                          items: [createEmptyItemRow(1)],
                      }
                    : {}),
            }));
            clearSaveFeedback();
        },
        [clearSaveFeedback],
    );

    const handleSelectVendor = useCallback(
        async (vendor: VendorLookupItem) => {
            if (form.vendor_id === vendor.id) {
                return;
            }

            const hasVendorDependentInput =
                form.vendor_id !== '' &&
                (form.nama_pic.trim() !== '' ||
                    form.telepon.trim() !== '' ||
                    form.email.trim() !== '' ||
                    form.alamat.trim() !== '' ||
                    form.items.some(isItemRowTouched));

            if (hasVendorDependentInput) {
                const confirmed = await confirm({
                    title: 'Ganti vendor rekanan?',
                    description:
                        'Mengganti vendor akan memperbarui identitas vendor dan mengosongkan rincian kontak serta daftar item yang sedang diisi.',
                    confirmLabel: 'Ganti vendor',
                    destructive: true,
                });

                if (!confirmed) {
                    return;
                }

                applyVendorSelection(vendor, true);

                return;
            }

            applyVendorSelection(vendor, form.vendor_id !== '');
        },
        [
            form.vendor_id,
            form.nama_pic,
            form.telepon,
            form.email,
            form.alamat,
            form.items,
            confirm,
            applyVendorSelection,
        ],
    );

    const validateBeforeSubmit = (): Record<string, string[]> | null => {
        const fieldErrors: Record<string, string[]> = {};

        if (!form.tanggal_prakualifikasi.trim()) {
            fieldErrors.tanggal_prakualifikasi = [
                'Tanggal prakualifikasi wajib diisi.',
            ];
        }

        if (!form.vendor_id.trim()) {
            fieldErrors.vendor_id = [
                'Pilih vendor rekanan dari direktori perusahaan aktif terlebih dahulu.',
            ];
        }

        if (!isEdit && !numberPolicy.allowAutomatic) {
            if (!form.nomor_prakualifikasi.trim()) {
                fieldErrors.nomor_prakualifikasi = [
                    'Nomor prakualifikasi wajib diisi karena penomoran otomatis sedang tidak aktif.',
                ];
            }
        }

        form.extraDocs.forEach((doc, idx) => {
            if (isExtraDocTouched(doc) && !doc.nama_dokumen.trim()) {
                fieldErrors[
                    `documents.${STANDARD_DOCUMENTS.length + idx}.nama_dokumen`
                ] = [
                    `Nama dokumen tambahan pada baris ke-${idx + 1} wajib diisi.`,
                ];
            }
        });

        form.items.forEach((item, idx) => {
            if (isItemRowTouched(item) && !item.nama_item.trim()) {
                fieldErrors[`items.${idx}.nama_item`] = [
                    `Nama item pada baris ke-${idx + 1} wajib diisi sebelum menyimpan.`,
                ];
            }
        });

        if (Object.keys(fieldErrors).length === 0) {
            return null;
        }

        return fieldErrors;
    };

    const handleSubmit = async (e: FormEvent) => {
        e.preventDefault();
        clearSaveFeedback();

        if (!isEdit && (numberPolicy.loading || numberPolicy.error)) {
            reportSaveError(
                new ApiError(
                    numberPolicy.error ||
                        'Tunggu pengaturan nomor prakualifikasi selesai dimuat.',
                    {},
                    422,
                ),
                'menyimpan prakualifikasi vendor',
            );

            return;
        }

        const validationErrors = validateBeforeSubmit();

        if (validationErrors) {
            reportSaveError(
                new ApiError(
                    'Lengkapi kolom wajib yang ditandai sebelum menyimpan data prakualifikasi.',
                    validationErrors,
                    422,
                ),
                'menyimpan prakualifikasi vendor',
            );

            return;
        }

        const touchedExtraDocs = form.extraDocs.filter(isExtraDocTouched);
        const touchedItems = form.items.filter(isItemRowTouched);

        const payload = {
            ...(isEdit && initialData ? { version: initialData.version } : {}),
            tanggal_prakualifikasi: form.tanggal_prakualifikasi,
            ...(!isEdit && numberPolicy.allowManual
                ? {
                      nomor_prakualifikasi:
                          form.nomor_prakualifikasi.trim() || null,
                  }
                : {}),
            vendor_id: form.vendor_id,
            jenis_vendor: form.jenis_vendor.trim() || null,
            nama_pic: form.nama_pic.trim() || null,
            npwp_ktp: form.npwp_ktp.trim() || null,
            telepon: form.telepon.trim() || null,
            email: form.email.trim() || null,
            media_sosial: form.media_sosial.trim() || null,
            alamat: form.alamat.trim() || null,
            status_vendor: form.status_vendor,
            status_badan_hukum: form.status_badan_hukum,
            status_kualifikasi: form.status_kualifikasi,
            waktu_kesediaan_item: form.waktu_kesediaan_item.trim() || null,
            jumlah_karyawan:
                form.jumlah_karyawan.trim() !== '' &&
                !Number.isNaN(Number.parseInt(form.jumlah_karyawan, 10))
                    ? Number.parseInt(form.jumlah_karyawan, 10)
                    : null,
            catatan: form.catatan.trim() || null,
            documents: [
                ...form.standardDocs.map((d, idx) => ({
                    ...(d.id ? { id: d.id } : {}),
                    kategori: 'wajib' as const,
                    urutan: idx + 1,
                    nama_dokumen: d.nama_dokumen,
                    ada: Boolean(d.ada),
                    keterangan: (d.keterangan ?? '').trim() || null,
                })),
                ...touchedExtraDocs.map((d, idx) => ({
                    ...(d.id ? { id: d.id } : {}),
                    kategori: 'tambahan' as const,
                    urutan: form.standardDocs.length + idx + 1,
                    nama_dokumen: d.nama_dokumen.trim(),
                    ada: true,
                    keterangan: (d.keterangan ?? '').trim() || null,
                })),
            ],
            items: touchedItems.map((it, idx) => ({
                ...(it.id ? { id: it.id } : {}),
                urutan: idx + 1,
                jenis_item: it.jenis_item.trim() || null,
                kode_item: it.kode_item.trim() || null,
                nama_item: it.nama_item.trim(),
                satuan: it.satuan.trim() || null,
                kesediaan: it.kesediaan.trim() || null,
                keterangan: (it.keterangan ?? '').trim() || null,
            })),
        };

        setSubmittingState(true);

        try {
            const response = await api<{ data: VendorPrequalificationDetail }>(
                isEdit && initialData
                    ? `/prakualifikasi/${initialData.id}`
                    : '/prakualifikasi',
                {
                    method: isEdit ? 'PUT' : 'POST',
                    headers: isEdit
                        ? undefined
                        : { 'Idempotency-Key': creationKey.current },
                    body: JSON.stringify(payload),
                },
            );

            toast.success(
                isEdit
                    ? 'Data prakualifikasi berhasil diperbarui.'
                    : 'Data prakualifikasi berhasil disimpan.',
            );
            onSaved(response.data);
            onClose();
        } catch (caught) {
            reportSaveError(caught, 'menyimpan prakualifikasi vendor');
        } finally {
            setSubmittingState(false);
        }
    };

    function setSubmittingState(value: boolean) {
        setSaving(value);
    }

    if (!open) {
        return null;
    }

    const selectedVendorOption: VendorLookupItem | null = form.vendor_id
        ? {
              id: form.vendor_id,
              number: form.kode_vendor,
              name: form.nama_vendor,
              tax_number: form.npwp_ktp || null,
              status: 'active',
              legal_entity_id: legalEntityId ?? '',
          }
        : null;

    return (
        <>
            <PageDialog
                open={open}
                onOpenChange={(nextOpen) =>
                    !nextOpen && transition.request(onClose)
                }
            >
                <PageDialogContent
                    ref={contentRef}
                    aria-describedby={undefined}
                    title={
                        isEdit
                            ? 'Ubah Prakualifikasi Vendor'
                            : 'Tambah Prakualifikasi Vendor'
                    }
                    recordTitle={
                        isEdit && initialData
                            ? `${initialData.nomor_prakualifikasi} · ${form.nama_vendor || initialData.nama_vendor}`
                            : form.nama_vendor || 'Baru'
                    }
                    editing
                    busy={saving}
                    onDelete={isEdit ? onDelete : undefined}
                >
                    <form
                        noValidate
                        onSubmit={handleSubmit}
                        className="flex min-h-0 flex-1 flex-col"
                    >
                        <PageDialogToolbar>
                            <ActionButton
                                action="save"
                                type="submit"
                                disabled={saving}
                                pending={saving}
                            />
                            <ActionButton
                                action="cancel"
                                type="button"
                                disabled={saving}
                                onClick={() => transition.request(onClose)}
                            />
                        </PageDialogToolbar>

                        {feedbackSummary}

                        <PageDialogBody
                            className="space-y-5"
                            scroll-region="true"
                        >
                            {/* 1. INFORMASI UMUM & IDENTITAS VENDOR */}
                            <Card className="shadow-xs border">
                                <CardHeader className="border-b py-3">
                                    <CardTitle className="text-sm">
                                        Informasi Prakualifikasi & Identitas
                                        Rekanan
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="grid grid-cols-1 gap-4 pt-4 text-xs md:grid-cols-2">
                                    {/* Kolom Kiri */}
                                    <div className="space-y-3">
                                        <FormControl
                                            data-error-field="tanggal_prakualifikasi"
                                            error={getFieldError(
                                                'tanggal_prakualifikasi',
                                            )}
                                        >
                                            <FieldLabel htmlFor="tanggal_prakualifikasi">
                                                Tanggal Prakualifikasi *
                                            </FieldLabel>
                                            <Input
                                                id="tanggal_prakualifikasi"
                                                name="tanggal_prakualifikasi"
                                                type="date"
                                                required
                                                value={
                                                    form.tanggal_prakualifikasi
                                                }
                                                onChange={(e) =>
                                                    setForm((prev) => ({
                                                        ...prev,
                                                        tanggal_prakualifikasi:
                                                            e.target.value,
                                                    }))
                                                }
                                            />
                                        </FormControl>

                                        <FormControl
                                            data-error-field="vendor_id"
                                            error={getFieldError('vendor_id')}
                                        >
                                            <FieldLabel htmlFor="vendor_lookup">
                                                Vendor/Supplier (Direktori Core)
                                                *
                                            </FieldLabel>
                                            <div
                                                id="vendor_lookup"
                                                className="space-y-2"
                                            >
                                                <InlineLookup<VendorLookupItem>
                                                    value={selectedVendorOption}
                                                    loadItems={loadVendors}
                                                    getItemValue={(item) =>
                                                        item.id
                                                    }
                                                    getItemLabel={(item) =>
                                                        `${item.number} · ${item.name}`
                                                    }
                                                    onValueChange={(item) => {
                                                        void handleSelectVendor(
                                                            item,
                                                        );
                                                    }}
                                                    label="Pilih vendor rekanan"
                                                    disabled={!legalEntityId}
                                                    placeholder="Pilih atau cari kode / nama vendor…"
                                                    emptyMessage="Tidak ada vendor yang cocok pada perusahaan aktif."
                                                    portalContainer={contentRef}
                                                    onCreate={
                                                        canCreateCoreVendor
                                                            ? () =>
                                                                  setCreatingVendor(
                                                                      true,
                                                                  )
                                                            : undefined
                                                    }
                                                    columns={[
                                                        {
                                                            id: 'number',
                                                            header: 'Nomor',
                                                            width: 140,
                                                            cell: (item) => (
                                                                <span className="font-mono text-xs font-medium">
                                                                    {
                                                                        item.number
                                                                    }
                                                                </span>
                                                            ),
                                                        },
                                                        {
                                                            id: 'name',
                                                            header: 'Nama Vendor',
                                                            width: 240,
                                                            cell: (item) => (
                                                                <span className="text-xs font-medium">
                                                                    {item.name}
                                                                </span>
                                                            ),
                                                        },
                                                        {
                                                            id: 'tax_number',
                                                            header: 'NPWP',
                                                            width: 170,
                                                            cell: (item) => (
                                                                <span className="text-muted-foreground font-mono text-xs">
                                                                    {item.tax_number ||
                                                                        '—'}
                                                                </span>
                                                            ),
                                                        },
                                                    ]}
                                                />
                                                {form.vendor_id && (
                                                    <div className="flex items-center justify-between gap-2 text-xs">
                                                        <span className="text-muted-foreground font-mono">
                                                            Kode:{' '}
                                                            <strong className="text-foreground">
                                                                {
                                                                    form.kode_vendor
                                                                }
                                                            </strong>{' '}
                                                            · Nama:{' '}
                                                            <strong className="text-foreground">
                                                                {
                                                                    form.nama_vendor
                                                                }
                                                            </strong>
                                                        </span>
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="sm"
                                                            className="h-6 px-2 text-xs"
                                                            onClick={() => {
                                                                window.location.assign(
                                                                    `/settings/vendors?vendor=${encodeURIComponent(form.vendor_id)}`,
                                                                );
                                                            }}
                                                        >
                                                            <ExternalLink className="mr-1 h-3 w-3" />
                                                            Buka Kartu Vendor
                                                        </Button>
                                                    </div>
                                                )}
                                            </div>
                                        </FormControl>

                                        <FormControl
                                            data-error-field="jenis_vendor"
                                            error={getFieldError(
                                                'jenis_vendor',
                                            )}
                                        >
                                            <FieldLabel htmlFor="jenis_vendor">
                                                Jenis Vendor/Supp
                                            </FieldLabel>
                                            <Input
                                                id="jenis_vendor"
                                                name="jenis_vendor"
                                                value={form.jenis_vendor}
                                                onChange={(e) =>
                                                    setForm((prev) => ({
                                                        ...prev,
                                                        jenis_vendor:
                                                            e.target.value,
                                                    }))
                                                }
                                                placeholder="Contoh: Distributor Utama, Pabrikan..."
                                            />
                                        </FormControl>

                                        <FormControl
                                            data-error-field="nama_pic"
                                            error={getFieldError('nama_pic')}
                                        >
                                            <FieldLabel htmlFor="nama_pic">
                                                Nama PIC Vendor/Supp
                                            </FieldLabel>
                                            <Input
                                                id="nama_pic"
                                                name="nama_pic"
                                                value={form.nama_pic}
                                                onChange={(e) =>
                                                    setForm((prev) => ({
                                                        ...prev,
                                                        nama_pic:
                                                            e.target.value,
                                                    }))
                                                }
                                                placeholder="Nama penanggung jawab / kontak..."
                                            />
                                        </FormControl>

                                        <FormControl
                                            data-error-field="npwp_ktp"
                                            error={getFieldError('npwp_ktp')}
                                        >
                                            <FieldLabel htmlFor="npwp_ktp">
                                                NPWP/KTP
                                            </FieldLabel>
                                            <Input
                                                id="npwp_ktp"
                                                name="npwp_ktp"
                                                value={form.npwp_ktp}
                                                onChange={(e) =>
                                                    setForm((prev) => ({
                                                        ...prev,
                                                        npwp_ktp:
                                                            e.target.value,
                                                    }))
                                                }
                                                placeholder="Nomor NPWP atau NIK KTP..."
                                            />
                                        </FormControl>

                                        <FormControl
                                            data-error-field="telepon"
                                            error={getFieldError('telepon')}
                                        >
                                            <FieldLabel htmlFor="telepon">
                                                Telp./HP
                                            </FieldLabel>
                                            <Input
                                                id="telepon"
                                                name="telepon"
                                                value={form.telepon}
                                                onChange={(e) =>
                                                    setForm((prev) => ({
                                                        ...prev,
                                                        telepon: e.target.value,
                                                    }))
                                                }
                                                placeholder="Nomor telepon / WhatsApp aktif..."
                                            />
                                        </FormControl>
                                    </div>

                                    {/* Kolom Kanan */}
                                    <div className="space-y-3">
                                        <FormControl
                                            data-error-field="nomor_prakualifikasi"
                                            error={
                                                getFieldError(
                                                    'nomor_prakualifikasi',
                                                ) ||
                                                numberPolicy.error ||
                                                undefined
                                            }
                                        >
                                            <FieldLabel htmlFor="nomor_prakualifikasi">
                                                Nomor Prakualifikasi
                                            </FieldLabel>
                                            <Input
                                                id="nomor_prakualifikasi"
                                                name="nomor_prakualifikasi"
                                                value={
                                                    isEdit
                                                        ? form.nomor_prakualifikasi
                                                        : numberPolicy.allowManual
                                                          ? form.nomor_prakualifikasi
                                                          : 'Dibuat otomatis saat disimpan'
                                                }
                                                disabled={
                                                    isEdit ||
                                                    !numberPolicy.allowManual
                                                }
                                                required={
                                                    !isEdit &&
                                                    !numberPolicy.allowAutomatic
                                                }
                                                onChange={(e) =>
                                                    setForm((prev) => ({
                                                        ...prev,
                                                        nomor_prakualifikasi:
                                                            e.target.value,
                                                    }))
                                                }
                                                placeholder={
                                                    numberPolicy.allowAutomatic
                                                        ? 'Kosongkan untuk nomor otomatis (PRKP...)'
                                                        : 'Masukkan nomor prakualifikasi'
                                                }
                                            />
                                        </FormControl>

                                        <FormControl
                                            data-error-field="email"
                                            error={getFieldError('email')}
                                        >
                                            <FieldLabel htmlFor="email">
                                                E-Mail
                                            </FieldLabel>
                                            <Input
                                                id="email"
                                                name="email"
                                                type="email"
                                                value={form.email}
                                                onChange={(e) =>
                                                    setForm((prev) => ({
                                                        ...prev,
                                                        email: e.target.value,
                                                    }))
                                                }
                                                placeholder="alamat@perusahaan.co.id"
                                            />
                                        </FormControl>

                                        <FormControl
                                            data-error-field="media_sosial"
                                            error={getFieldError(
                                                'media_sosial',
                                            )}
                                        >
                                            <FieldLabel htmlFor="media_sosial">
                                                MedSos / Website
                                            </FieldLabel>
                                            <Input
                                                id="media_sosial"
                                                name="media_sosial"
                                                value={form.media_sosial}
                                                onChange={(e) =>
                                                    setForm((prev) => ({
                                                        ...prev,
                                                        media_sosial:
                                                            e.target.value,
                                                    }))
                                                }
                                                placeholder="Website atau akun media sosial..."
                                            />
                                        </FormControl>

                                        <FormControl
                                            data-error-field="alamat"
                                            error={getFieldError('alamat')}
                                        >
                                            <FieldLabel htmlFor="alamat">
                                                Alamat
                                            </FieldLabel>
                                            <Input
                                                id="alamat"
                                                name="alamat"
                                                value={form.alamat}
                                                onChange={(e) =>
                                                    setForm((prev) => ({
                                                        ...prev,
                                                        alamat: e.target.value,
                                                    }))
                                                }
                                                placeholder="Alamat kantor / korespondensi..."
                                            />
                                        </FormControl>

                                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                            <FormControl
                                                data-error-field="status_badan_hukum"
                                                error={getFieldError(
                                                    'status_badan_hukum',
                                                )}
                                            >
                                                <FieldLabel htmlFor="status_badan_hukum">
                                                    Status Badan Hukum
                                                </FieldLabel>
                                                <NativeSelect
                                                    id="status_badan_hukum"
                                                    name="status_badan_hukum"
                                                    value={
                                                        form.status_badan_hukum
                                                    }
                                                    onChange={(e) =>
                                                        setForm((prev) => ({
                                                            ...prev,
                                                            status_badan_hukum:
                                                                e.target.value,
                                                        }))
                                                    }
                                                >
                                                    <NativeSelectOption value="Badan Hukum">
                                                        Badan Hukum
                                                    </NativeSelectOption>
                                                    <NativeSelectOption value="Bukan Badan Hukum">
                                                        Bukan Badan Hukum
                                                    </NativeSelectOption>
                                                    <NativeSelectOption value="Perorangan">
                                                        Perorangan
                                                    </NativeSelectOption>
                                                </NativeSelect>
                                            </FormControl>

                                            <FormControl
                                                data-error-field="status_kualifikasi"
                                                error={getFieldError(
                                                    'status_kualifikasi',
                                                )}
                                            >
                                                <FieldLabel htmlFor="status_kualifikasi">
                                                    Status Evaluasi Kualifikasi
                                                </FieldLabel>
                                                <NativeSelect
                                                    id="status_kualifikasi"
                                                    name="status_kualifikasi"
                                                    value={
                                                        form.status_kualifikasi
                                                    }
                                                    onChange={(e) =>
                                                        setForm((prev) => ({
                                                            ...prev,
                                                            status_kualifikasi:
                                                                e.target.value,
                                                        }))
                                                    }
                                                >
                                                    {QUALIFICATION_STATUS_OPTIONS.map(
                                                        (status) => (
                                                            <NativeSelectOption
                                                                key={status}
                                                                value={status}
                                                            >
                                                                {status}
                                                            </NativeSelectOption>
                                                        ),
                                                    )}
                                                </NativeSelect>
                                            </FormControl>
                                        </div>

                                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                            <FormControl
                                                data-error-field="waktu_kesediaan_item"
                                                error={getFieldError(
                                                    'waktu_kesediaan_item',
                                                )}
                                            >
                                                <FieldLabel htmlFor="waktu_kesediaan_item">
                                                    Waktu Kesediaan Item
                                                </FieldLabel>
                                                <Input
                                                    id="waktu_kesediaan_item"
                                                    name="waktu_kesediaan_item"
                                                    value={
                                                        form.waktu_kesediaan_item
                                                    }
                                                    onChange={(e) =>
                                                        setForm((prev) => ({
                                                            ...prev,
                                                            waktu_kesediaan_item:
                                                                e.target.value,
                                                        }))
                                                    }
                                                    placeholder="Contoh: 7 Hari"
                                                />
                                            </FormControl>

                                            <FormControl
                                                data-error-field="jumlah_karyawan"
                                                error={getFieldError(
                                                    'jumlah_karyawan',
                                                )}
                                            >
                                                <FieldLabel htmlFor="jumlah_karyawan">
                                                    Jumlah Karyawan
                                                </FieldLabel>
                                                <Input
                                                    id="jumlah_karyawan"
                                                    name="jumlah_karyawan"
                                                    type="number"
                                                    min={0}
                                                    value={form.jumlah_karyawan}
                                                    onChange={(e) =>
                                                        setForm((prev) => ({
                                                            ...prev,
                                                            jumlah_karyawan:
                                                                e.target.value,
                                                        }))
                                                    }
                                                    placeholder="0"
                                                />
                                            </FormControl>
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>

                            {/* 2. KELENGKAPAN DOKUMEN & CATATAN */}
                            <div className="grid grid-cols-1 gap-4 lg:grid-cols-12">
                                <div className="space-y-2 lg:col-span-7">
                                    <div className="flex items-center justify-between">
                                        <span className="flex items-center gap-1.5 text-xs font-semibold">
                                            <FileText className="text-primary h-4 w-4" />
                                            Kelengkapan Dokumen Wajib
                                        </span>
                                        <span className="text-muted-foreground text-xs">
                                            Terpenuhi:{' '}
                                            {
                                                form.standardDocs.filter(
                                                    (d) => d.ada,
                                                ).length
                                            }
                                            /{form.standardDocs.length}
                                        </span>
                                    </div>

                                    <div className="overflow-hidden rounded-md border">
                                        <DataTable
                                            scrollMode="inline"
                                            showRowNumbers={false}
                                            columns={standardDocColumns}
                                            data={form.standardDocs}
                                            getRowKey={(doc) =>
                                                doc.id || doc.nama_dokumen
                                            }
                                            renderRows={() => (
                                                <>
                                                    {form.standardDocs.map(
                                                        (doc, idx) => (
                                                            <TableRow
                                                                key={
                                                                    doc.id ||
                                                                    doc.nama_dokumen
                                                                }
                                                                className="h-8 text-xs"
                                                            >
                                                                <TableCell className="py-1 text-center font-mono">
                                                                    {idx + 1}.
                                                                </TableCell>
                                                                <TableCell className="py-1 font-medium">
                                                                    {
                                                                        doc.nama_dokumen
                                                                    }
                                                                </TableCell>
                                                                <TableCell className="py-1 text-center">
                                                                    <input
                                                                        type="radio"
                                                                        aria-label={`${doc.nama_dokumen} Ada`}
                                                                        name={`doc_ada_${idx}`}
                                                                        checked={
                                                                            doc.ada ===
                                                                            true
                                                                        }
                                                                        onChange={() =>
                                                                            setForm(
                                                                                (
                                                                                    prev,
                                                                                ) => {
                                                                                    const next =
                                                                                        [
                                                                                            ...prev.standardDocs,
                                                                                        ];
                                                                                    next[
                                                                                        idx
                                                                                    ] =
                                                                                        {
                                                                                            ...next[
                                                                                                idx
                                                                                            ],
                                                                                            ada: true,
                                                                                        };

                                                                                    return {
                                                                                        ...prev,
                                                                                        standardDocs:
                                                                                            next,
                                                                                    };
                                                                                },
                                                                            )
                                                                        }
                                                                        className="h-3.5 w-3.5 cursor-pointer"
                                                                    />
                                                                </TableCell>
                                                                <TableCell className="py-1 text-center">
                                                                    <input
                                                                        type="radio"
                                                                        aria-label={`${doc.nama_dokumen} Tidak Ada`}
                                                                        name={`doc_ada_${idx}`}
                                                                        checked={
                                                                            doc.ada ===
                                                                            false
                                                                        }
                                                                        onChange={() =>
                                                                            setForm(
                                                                                (
                                                                                    prev,
                                                                                ) => {
                                                                                    const next =
                                                                                        [
                                                                                            ...prev.standardDocs,
                                                                                        ];
                                                                                    next[
                                                                                        idx
                                                                                    ] =
                                                                                        {
                                                                                            ...next[
                                                                                                idx
                                                                                            ],
                                                                                            ada: false,
                                                                                        };

                                                                                    return {
                                                                                        ...prev,
                                                                                        standardDocs:
                                                                                            next,
                                                                                    };
                                                                                },
                                                                            )
                                                                        }
                                                                        className="h-3.5 w-3.5 cursor-pointer"
                                                                    />
                                                                </TableCell>
                                                            </TableRow>
                                                        ),
                                                    )}
                                                </>
                                            )}
                                        />
                                    </div>
                                </div>

                                <div className="space-y-4 lg:col-span-5">
                                    <FormControl
                                        data-error-field="catatan"
                                        error={getFieldError('catatan')}
                                    >
                                        <FieldLabel htmlFor="catatan">
                                            Catatan Evaluasi
                                        </FieldLabel>
                                        <Textarea
                                            id="catatan"
                                            name="catatan"
                                            value={form.catatan}
                                            onChange={(e) =>
                                                setForm((prev) => ({
                                                    ...prev,
                                                    catatan: e.target.value,
                                                }))
                                            }
                                            rows={3}
                                            placeholder="Catatan tambahan hasil pemeriksaan dokumen..."
                                        />
                                    </FormControl>

                                    <div className="space-y-2">
                                        <div className="flex items-center justify-between">
                                            <span className="text-xs font-semibold">
                                                Dokumen Tambahan (Opsional)
                                            </span>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    setForm((prev) => ({
                                                        ...prev,
                                                        extraDocs: [
                                                            ...prev.extraDocs,
                                                            {
                                                                kategori:
                                                                    'tambahan',
                                                                urutan:
                                                                    prev
                                                                        .extraDocs
                                                                        .length +
                                                                    1,
                                                                nama_dokumen:
                                                                    '',
                                                                ada: true,
                                                                keterangan: '',
                                                            },
                                                        ],
                                                    }))
                                                }
                                                className="h-7 px-2 text-xs"
                                            >
                                                <Plus className="mr-1 h-3 w-3" />
                                                Tambah
                                            </Button>
                                        </div>

                                        <div className="overflow-hidden rounded-md border">
                                            <DataTable
                                                scrollMode="inline"
                                                showRowNumbers={false}
                                                columns={additionalDocColumns}
                                                data={form.extraDocs}
                                                getRowKey={(doc) =>
                                                    doc.id ||
                                                    `extra-${doc.urutan}`
                                                }
                                                renderRows={() => (
                                                    <>
                                                        {form.extraDocs
                                                            .length === 0 ? (
                                                            <TableRow>
                                                                <TableCell
                                                                    colSpan={4}
                                                                    className="text-muted-foreground py-4 text-center text-xs"
                                                                >
                                                                    Belum ada
                                                                    dokumen
                                                                    tambahan.
                                                                </TableCell>
                                                            </TableRow>
                                                        ) : (
                                                            form.extraDocs.map(
                                                                (doc, idx) => {
                                                                    const docErrorKey = `documents.${STANDARD_DOCUMENTS.length + idx}.nama_dokumen`;
                                                                    const docErr =
                                                                        getFieldError(
                                                                            docErrorKey,
                                                                        );

                                                                    return (
                                                                        <TableRow
                                                                            key={
                                                                                doc.id ||
                                                                                idx
                                                                            }
                                                                            className="text-xs"
                                                                        >
                                                                            <TableCell className="py-1 text-center font-mono">
                                                                                {idx +
                                                                                    1}
                                                                            </TableCell>
                                                                            <TableCell className="py-1">
                                                                                <div
                                                                                    data-error-field={
                                                                                        docErrorKey
                                                                                    }
                                                                                >
                                                                                    <Input
                                                                                        name={
                                                                                            docErrorKey
                                                                                        }
                                                                                        aria-label={`Nama dokumen tambahan baris ${idx + 1}`}
                                                                                        value={
                                                                                            doc.nama_dokumen
                                                                                        }
                                                                                        onChange={(
                                                                                            e,
                                                                                        ) =>
                                                                                            setForm(
                                                                                                (
                                                                                                    prev,
                                                                                                ) => {
                                                                                                    const next =
                                                                                                        [
                                                                                                            ...prev.extraDocs,
                                                                                                        ];
                                                                                                    next[
                                                                                                        idx
                                                                                                    ] =
                                                                                                        {
                                                                                                            ...next[
                                                                                                                idx
                                                                                                            ],
                                                                                                            nama_dokumen:
                                                                                                                e
                                                                                                                    .target
                                                                                                                    .value,
                                                                                                        };

                                                                                                    return {
                                                                                                        ...prev,
                                                                                                        extraDocs:
                                                                                                            next,
                                                                                                    };
                                                                                                },
                                                                                            )
                                                                                        }
                                                                                        placeholder="Nama dokumen..."
                                                                                        className="h-7 text-xs"
                                                                                    />
                                                                                    {docErr && (
                                                                                        <FieldError>
                                                                                            {
                                                                                                docErr
                                                                                            }
                                                                                        </FieldError>
                                                                                    )}
                                                                                </div>
                                                                            </TableCell>
                                                                            <TableCell className="py-1">
                                                                                <Input
                                                                                    aria-label={`Keterangan dokumen tambahan baris ${idx + 1}`}
                                                                                    value={
                                                                                        doc.keterangan ??
                                                                                        ''
                                                                                    }
                                                                                    onChange={(
                                                                                        e,
                                                                                    ) =>
                                                                                        setForm(
                                                                                            (
                                                                                                prev,
                                                                                            ) => {
                                                                                                const next =
                                                                                                    [
                                                                                                        ...prev.extraDocs,
                                                                                                    ];
                                                                                                next[
                                                                                                    idx
                                                                                                ] =
                                                                                                    {
                                                                                                        ...next[
                                                                                                            idx
                                                                                                        ],
                                                                                                        keterangan:
                                                                                                            e
                                                                                                                .target
                                                                                                                .value,
                                                                                                    };

                                                                                                return {
                                                                                                    ...prev,
                                                                                                    extraDocs:
                                                                                                        next,
                                                                                                };
                                                                                            },
                                                                                        )
                                                                                    }
                                                                                    placeholder="Keterangan..."
                                                                                    className="h-7 text-xs"
                                                                                />
                                                                            </TableCell>
                                                                            <TableCell className="py-1 text-center">
                                                                                <Button
                                                                                    type="button"
                                                                                    variant="ghost"
                                                                                    size="icon-sm"
                                                                                    aria-label={`Hapus dokumen tambahan baris ${idx + 1}`}
                                                                                    onClick={() =>
                                                                                        setForm(
                                                                                            (
                                                                                                prev,
                                                                                            ) => ({
                                                                                                ...prev,
                                                                                                extraDocs:
                                                                                                    prev.extraDocs.filter(
                                                                                                        (
                                                                                                            _,
                                                                                                            i,
                                                                                                        ) =>
                                                                                                            i !==
                                                                                                            idx,
                                                                                                    ),
                                                                                            }),
                                                                                        )
                                                                                    }
                                                                                >
                                                                                    <Trash2 className="text-destructive h-3.5 w-3.5" />
                                                                                </Button>
                                                                            </TableCell>
                                                                        </TableRow>
                                                                    );
                                                                },
                                                            )
                                                        )}
                                                    </>
                                                )}
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {/* 3. DAFTAR ITEM YANG TERSEDIA */}
                            <div className="space-y-2">
                                <div className="flex items-center justify-between">
                                    <span className="flex items-center gap-1.5 text-xs font-semibold">
                                        <Package className="text-primary h-4 w-4" />
                                        Daftar Item yang Tersedia
                                    </span>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            setForm((prev) => ({
                                                ...prev,
                                                items: [
                                                    ...prev.items,
                                                    createEmptyItemRow(
                                                        prev.items.length + 1,
                                                    ),
                                                ],
                                            }))
                                        }
                                        className="h-7 px-2 text-xs"
                                    >
                                        <Plus className="mr-1 h-3 w-3" />
                                        Tambah
                                    </Button>
                                </div>

                                <div className="overflow-hidden rounded-md border">
                                    <DataTable
                                        scrollMode="inline"
                                        showRowNumbers={false}
                                        columns={itemFormColumns}
                                        data={form.items}
                                        getRowKey={(it) =>
                                            it.id || `item-${it.urutan}`
                                        }
                                        renderRows={() => (
                                            <>
                                                {form.items.map((item, idx) => {
                                                    const itemErrorKey = `items.${idx}.nama_item`;
                                                    const itemErr =
                                                        getFieldError(
                                                            itemErrorKey,
                                                        );

                                                    return (
                                                        <TableRow
                                                            key={item.id || idx}
                                                            className="text-xs"
                                                        >
                                                            <TableCell className="py-1 text-center font-mono">
                                                                {idx + 1}
                                                            </TableCell>
                                                            <TableCell className="py-1">
                                                                <Input
                                                                    aria-label={`Jenis item baris ${idx + 1}`}
                                                                    value={
                                                                        item.jenis_item
                                                                    }
                                                                    onChange={(
                                                                        e,
                                                                    ) =>
                                                                        setForm(
                                                                            (
                                                                                prev,
                                                                            ) => {
                                                                                const next =
                                                                                    [
                                                                                        ...prev.items,
                                                                                    ];
                                                                                next[
                                                                                    idx
                                                                                ] =
                                                                                    {
                                                                                        ...next[
                                                                                            idx
                                                                                        ],
                                                                                        jenis_item:
                                                                                            e
                                                                                                .target
                                                                                                .value,
                                                                                    };

                                                                                return {
                                                                                    ...prev,
                                                                                    items: next,
                                                                                };
                                                                            },
                                                                        )
                                                                    }
                                                                    placeholder="Jenis..."
                                                                    className="h-7 text-xs"
                                                                />
                                                            </TableCell>
                                                            <TableCell className="py-1">
                                                                <Input
                                                                    aria-label={`Kode item baris ${idx + 1}`}
                                                                    value={
                                                                        item.kode_item
                                                                    }
                                                                    onChange={(
                                                                        e,
                                                                    ) =>
                                                                        setForm(
                                                                            (
                                                                                prev,
                                                                            ) => {
                                                                                const next =
                                                                                    [
                                                                                        ...prev.items,
                                                                                    ];
                                                                                next[
                                                                                    idx
                                                                                ] =
                                                                                    {
                                                                                        ...next[
                                                                                            idx
                                                                                        ],
                                                                                        kode_item:
                                                                                            e
                                                                                                .target
                                                                                                .value,
                                                                                    };

                                                                                return {
                                                                                    ...prev,
                                                                                    items: next,
                                                                                };
                                                                            },
                                                                        )
                                                                    }
                                                                    placeholder="Kode..."
                                                                    className="h-7 font-mono text-xs"
                                                                />
                                                            </TableCell>
                                                            <TableCell className="py-1">
                                                                <div
                                                                    data-error-field={
                                                                        itemErrorKey
                                                                    }
                                                                >
                                                                    <Input
                                                                        name={
                                                                            itemErrorKey
                                                                        }
                                                                        aria-label={`Nama item baris ${idx + 1}`}
                                                                        value={
                                                                            item.nama_item
                                                                        }
                                                                        onChange={(
                                                                            e,
                                                                        ) =>
                                                                            setForm(
                                                                                (
                                                                                    prev,
                                                                                ) => {
                                                                                    const next =
                                                                                        [
                                                                                            ...prev.items,
                                                                                        ];
                                                                                    next[
                                                                                        idx
                                                                                    ] =
                                                                                        {
                                                                                            ...next[
                                                                                                idx
                                                                                            ],
                                                                                            nama_item:
                                                                                                e
                                                                                                    .target
                                                                                                    .value,
                                                                                        };

                                                                                    return {
                                                                                        ...prev,
                                                                                        items: next,
                                                                                    };
                                                                                },
                                                                            )
                                                                        }
                                                                        placeholder="Nama item (wajib jika baris diisi)..."
                                                                        className="h-7 text-xs"
                                                                    />
                                                                    {itemErr && (
                                                                        <FieldError>
                                                                            {
                                                                                itemErr
                                                                            }
                                                                        </FieldError>
                                                                    )}
                                                                </div>
                                                            </TableCell>
                                                            <TableCell className="py-1">
                                                                <Input
                                                                    aria-label={`Satuan item baris ${idx + 1}`}
                                                                    value={
                                                                        item.satuan
                                                                    }
                                                                    onChange={(
                                                                        e,
                                                                    ) =>
                                                                        setForm(
                                                                            (
                                                                                prev,
                                                                            ) => {
                                                                                const next =
                                                                                    [
                                                                                        ...prev.items,
                                                                                    ];
                                                                                next[
                                                                                    idx
                                                                                ] =
                                                                                    {
                                                                                        ...next[
                                                                                            idx
                                                                                        ],
                                                                                        satuan: e
                                                                                            .target
                                                                                            .value,
                                                                                    };

                                                                                return {
                                                                                    ...prev,
                                                                                    items: next,
                                                                                };
                                                                            },
                                                                        )
                                                                    }
                                                                    placeholder="Satuan..."
                                                                    className="h-7 text-xs"
                                                                />
                                                            </TableCell>
                                                            <TableCell className="py-1">
                                                                <Input
                                                                    aria-label={`Kesediaan item baris ${idx + 1}`}
                                                                    value={
                                                                        item.kesediaan
                                                                    }
                                                                    onChange={(
                                                                        e,
                                                                    ) =>
                                                                        setForm(
                                                                            (
                                                                                prev,
                                                                            ) => {
                                                                                const next =
                                                                                    [
                                                                                        ...prev.items,
                                                                                    ];
                                                                                next[
                                                                                    idx
                                                                                ] =
                                                                                    {
                                                                                        ...next[
                                                                                            idx
                                                                                        ],
                                                                                        kesediaan:
                                                                                            e
                                                                                                .target
                                                                                                .value,
                                                                                    };

                                                                                return {
                                                                                    ...prev,
                                                                                    items: next,
                                                                                };
                                                                            },
                                                                        )
                                                                    }
                                                                    placeholder="Stok / kesediaan..."
                                                                    className="h-7 text-xs"
                                                                />
                                                            </TableCell>
                                                            <TableCell className="py-1 text-center">
                                                                <Button
                                                                    type="button"
                                                                    variant="ghost"
                                                                    size="icon-sm"
                                                                    aria-label={`Hapus item baris ${idx + 1}`}
                                                                    onClick={() =>
                                                                        setForm(
                                                                            (
                                                                                prev,
                                                                            ) => ({
                                                                                ...prev,
                                                                                items:
                                                                                    prev
                                                                                        .items
                                                                                        .length <=
                                                                                    1
                                                                                        ? [
                                                                                              createEmptyItemRow(
                                                                                                  1,
                                                                                              ),
                                                                                          ]
                                                                                        : prev.items.filter(
                                                                                              (
                                                                                                  _,
                                                                                                  i,
                                                                                              ) =>
                                                                                                  i !==
                                                                                                  idx,
                                                                                          ),
                                                                            }),
                                                                        )
                                                                    }
                                                                >
                                                                    <Trash2 className="text-destructive h-3.5 w-3.5" />
                                                                </Button>
                                                            </TableCell>
                                                        </TableRow>
                                                    );
                                                })}
                                            </>
                                        )}
                                    />
                                </div>
                            </div>
                        </PageDialogBody>
                    </form>
                </PageDialogContent>
            </PageDialog>

            {creatingVendor && legalEntityId && (
                <Suspense fallback={null}>
                    <VendorCreateCard
                        legalEntityId={legalEntityId}
                        onClose={() => setCreatingVendor(false)}
                        onCreated={(created) => {
                            setCreatingVendor(false);
                            void handleSelectVendor({
                                id: created.id,
                                number: created.number,
                                name: created.name,
                                tax_number: created.tax_number,
                                status: created.status,
                                legal_entity_id: legalEntityId,
                            });
                        }}
                    />
                </Suspense>
            )}

            {transition.confirmation}
            {vendorSwitchConfirmation}
        </>
    );
}
