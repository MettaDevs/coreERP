import {
    Building2,
    FileText,
    Package,
    Plus,
    RotateCcw,
    Search,
    Trash2,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { toast } from 'sonner';
import { Badge } from '@apperp/ui/badge';
import { Button } from '@apperp/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@apperp/ui/card';
import { DataTable } from '@apperp/ui/data-table';
import {
    Dialog,
    DialogAction,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@apperp/ui/dialog';
import { Input } from '@apperp/ui/input';
import { Label } from '@apperp/ui/label';
import { NativeSelect, NativeSelectOption } from '@apperp/ui/native-select';
import { TableCell, TableRow } from '@apperp/ui/table';
import { Textarea } from '@apperp/ui/textarea';
import { api, toastSaveError } from '../../api';
import { VendorLookupModal } from './VendorLookupModal';
import type { VendorLookupItem } from './VendorLookupModal';

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

export type PrakualifikasiDokumenForm = {
    id?: string;
    kategori: 'wajib' | 'tambahan';
    urutan: number;
    nama_dokumen: string;
    ada: boolean;
    keterangan?: string;
};

export type PrakualifikasiItemForm = {
    id?: string;
    urutan: number;
    jenis_item: string;
    kode_item: string;
    nama_item: string;
    satuan: string;
    kesediaan: string;
    keterangan?: string;
};

export type PrakualifikasiFormData = {
    id?: string;
    nomor_prakualifikasi: string;
    tanggal_prakualifikasi: string;
    vendor_id?: string | null;
    kode_vendor: string;
    nama_vendor: string;
    jenis_vendor: string;
    nama_pic: string;
    npwp_ktp: string;
    telepon: string;
    email: string;
    media_sosial: string;
    alamat: string;
    status_vendor?: string;
    status_badan_hukum: string;
    status_kualifikasi: string;
    waktu_kesediaan_item: string;
    jumlah_karyawan: string | number;
    catatan: string;
    dokumen: PrakualifikasiDokumenForm[];
    items: PrakualifikasiItemForm[];
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

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    initialData?: PrakualifikasiFormData | null;
    onSuccess: () => void;
};

export function PrakualifikasiFormModal({
    open,
    onOpenChange,
    initialData,
    onSuccess,
}: Props) {
    const isEdit = Boolean(initialData?.id);
    const [submitting, setSubmitting] = useState(false);
    const [lookupOpen, setLookupOpen] = useState(false);

    // Form fields (Left column: 7 fields)
    const [qualificationDate, setQualificationDate] = useState(
        () => new Date().toISOString().split('T')[0],
    );
    const [vendorCode, setVendorCode] = useState('');
    const [vendorName, setVendorName] = useState('');
    const [vendorType, setVendorType] = useState('');
    const [picName, setPicName] = useState('');
    const [taxOrIdNumber, setTaxOrIdNumber] = useState('');
    const [phone, setPhone] = useState('');

    // Form fields (Right column: 7 fields)
    const [qualificationNumber, setQualificationNumber] = useState('');
    const [email, setEmail] = useState('');
    const [socialMedia, setSocialMedia] = useState('');
    const [address, setAddress] = useState('');
    const [legalStatus, setLegalStatus] = useState('Badan Hukum');
    const [leadTime, setLeadTime] = useState('');
    const [employeeCount, setEmployeeCount] = useState<string>('');

    // Additional metadata
    const [vendorId, setVendorId] = useState<string | null>(null);
    const [vendorStatus, setVendorStatus] = useState('Vendor Resmi');
    const [qualificationStatus, setQualificationStatus] = useState('Lolos');
    const [notes, setNotes] = useState('');

    // Dynamic Lists (Dokumen & Items)
    const [documentList, setDocumentList] = useState<
        PrakualifikasiDokumenForm[]
    >([]);
    const [additionalDocuments, setAdditionalDocuments] = useState<
        PrakualifikasiDokumenForm[]
    >([]);
    const [items, setItems] = useState<PrakualifikasiItemForm[]>([]);

    function getEmptyStandardDocs(): PrakualifikasiDokumenForm[] {
        return STANDARD_DOCUMENTS.map((name, idx) => ({
            kategori: 'wajib',
            urutan: idx + 1,
            nama_dokumen: name,
            ada: false,
            keterangan: '',
        }));
    }

    function getEmptyInitialItems(): PrakualifikasiItemForm[] {
        return [
            {
                urutan: 1,
                jenis_item: 'Obat',
                kode_item: '',
                nama_item: '',
                satuan: '',
                kesediaan: '',
                keterangan: '',
            },
        ];
    }

    const resetToEmptyForm = useCallback(() => {
        const today = new Date().toISOString().split('T')[0];
        setQualificationDate(today);
        setQualificationNumber('');
        setVendorId(null);
        setVendorCode('');
        setVendorName('');
        setVendorType('');
        setPicName('');
        setTaxOrIdNumber('');
        setPhone('');
        setEmail('');
        setSocialMedia('');
        setAddress('');
        setLegalStatus('Badan Hukum');
        setVendorStatus('Vendor Resmi');
        setQualificationStatus('Lolos');
        setLeadTime('');
        setEmployeeCount('');
        setNotes('');
        setDocumentList(getEmptyStandardDocs());
        setAdditionalDocuments([]);
        setItems(getEmptyInitialItems());
    }, []);

    useEffect(() => {
        if (!open) {
            return;
        }

        const timer = setTimeout(() => {
            if (initialData) {
                setQualificationDate(
                    initialData.tanggal_prakualifikasi ||
                        new Date().toISOString().split('T')[0],
                );
                setQualificationNumber(initialData.nomor_prakualifikasi || '');
                setVendorId(initialData.vendor_id || null);
                setVendorCode(initialData.kode_vendor || '');
                setVendorName(initialData.nama_vendor || '');
                setVendorType(initialData.jenis_vendor || '');
                setPicName(initialData.nama_pic || '');
                setTaxOrIdNumber(initialData.npwp_ktp || '');
                setPhone(initialData.telepon || '');
                setEmail(initialData.email || '');
                setSocialMedia(initialData.media_sosial || '');
                setAddress(initialData.alamat || '');
                setVendorStatus(initialData.status_vendor || 'Vendor Resmi');
                setLegalStatus(initialData.status_badan_hukum || 'Badan Hukum');
                setQualificationStatus(
                    initialData.status_kualifikasi || 'Lolos',
                );
                setLeadTime(initialData.waktu_kesediaan_item || '');
                setEmployeeCount(
                    initialData.jumlah_karyawan !== null &&
                        initialData.jumlah_karyawan !== undefined
                        ? String(initialData.jumlah_karyawan)
                        : '',
                );
                setNotes(initialData.catatan || '');

                const allDocs = initialData.dokumen || [];
                const standardDocs: PrakualifikasiDokumenForm[] =
                    STANDARD_DOCUMENTS.map((name, idx) => {
                        const match = allDocs.find(
                            (d) => d.nama_dokumen === name,
                        );

                        return {
                            id: match?.id,
                            kategori: 'wajib',
                            urutan: idx + 1,
                            nama_dokumen: name,
                            ada: match ? Boolean(match.ada) : false,
                            keterangan: match?.keterangan || '',
                        };
                    });
                setDocumentList(standardDocs);

                const extraDocs = allDocs
                    .filter((d) => !STANDARD_DOCUMENTS.includes(d.nama_dokumen))
                    .map((d, idx) => ({
                        id: d.id,
                        kategori: 'tambahan' as const,
                        urutan: idx + 1,
                        nama_dokumen: d.nama_dokumen,
                        ada: Boolean(d.ada),
                        keterangan: d.keterangan || '',
                    }));
                setAdditionalDocuments(extraDocs);

                if (initialData.items && initialData.items.length > 0) {
                    setItems(
                        initialData.items.map((it, idx) => ({
                            id: it.id,
                            urutan: idx + 1,
                            jenis_item: it.jenis_item || '',
                            kode_item: it.kode_item || '',
                            nama_item: it.nama_item || '',
                            satuan: it.satuan || '',
                            kesediaan: it.kesediaan || '',
                            keterangan: it.keterangan || '',
                        })),
                    );
                } else {
                    setItems(getEmptyInitialItems());
                }
            } else {
                resetToEmptyForm();

                if (open) {
                    setLookupOpen(true);
                }
            }
        }, 0);

        return () => clearTimeout(timer);
    }, [open, initialData, resetToEmptyForm]);

    const handleSelectVendor = (v: VendorLookupItem) => {
        setVendorId(v.vendor_id || null);
        setVendorCode(v.kode_vendor || '');
        setVendorName(v.nama_vendor || '');

        if (v.alamat) {
            setAddress(v.alamat);
        }

        if (v.telepon) {
            setPhone(v.telepon);
        }

        if (v.email) {
            setEmail(v.email);
        }

        if (v.nama_pic) {
            setPicName(v.nama_pic);
        }

        if (v.jenis_vendor) {
            setVendorType(v.jenis_vendor);
        }

        if (v.status_badan_hukum) {
            setLegalStatus(v.status_badan_hukum);
        }

        if (v.items && v.items.length > 0) {
            setItems(
                v.items.map((it, idx) => ({
                    urutan: idx + 1,
                    jenis_item: it.jenis_item || 'Barang',
                    kode_item: it.kode_item || '',
                    nama_item: it.nama_item || '',
                    satuan: it.satuan || '',
                    kesediaan: it.kesediaan || '',
                    keterangan: it.keterangan || '',
                })),
            );
        }

        toast.info(`Data vendor ${v.nama_vendor} berhasil dimuat.`);
    };

    const handleToggleDoc = (index: number, adaVal: boolean) => {
        setDocumentList((prev) => {
            const next = [...prev];
            next[index] = { ...next[index], ada: adaVal };

            return next;
        });
    };

    const handleAddExtraDoc = () => {
        setAdditionalDocuments((prev) => [
            ...prev,
            {
                kategori: 'tambahan',
                urutan: prev.length + 1,
                nama_dokumen: '',
                ada: true,
                keterangan: '',
            },
        ]);
    };

    const handleRemoveExtraDoc = (index: number) => {
        setAdditionalDocuments((prev) => prev.filter((_, i) => i !== index));
    };

    const handleExtraDocChange = (
        index: number,
        field: 'nama_dokumen' | 'keterangan',
        val: string,
    ) => {
        setAdditionalDocuments((prev) => {
            const next = [...prev];
            next[index] = { ...next[index], [field]: val };

            return next;
        });
    };

    const handleAddItem = () => {
        setItems((prev) => [
            ...prev,
            {
                urutan: prev.length + 1,
                jenis_item: 'BMHP',
                kode_item: `Bahan 0${prev.length + 1}`,
                nama_item: '',
                satuan: 'Pcs',
                kesediaan: '100',
                keterangan: '',
            },
        ]);
    };

    const handleRemoveItem = (index: number) => {
        if (items.length <= 1) {
            toast.error('Daftar item minimal memiliki 1 baris.');

            return;
        }

        setItems((prev) => prev.filter((_, i) => i !== index));
    };

    const handleItemChange = (
        index: number,
        field: keyof PrakualifikasiItemForm,
        value: string,
    ) => {
        setItems((prev) => {
            const next = [...prev];
            next[index] = { ...next[index], [field]: value };

            return next;
        });
    };

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();

        if (!vendorCode.trim() || !vendorName.trim()) {
            toast.error(
                'Mohon lengkapi Kode Vendor/Supp dan Nama Vendor/Supp.',
            );

            return;
        }

        const validItems = items.filter((it) => it.nama_item.trim() !== '');
        const validExtraDocs = additionalDocuments.filter(
            (d) => d.nama_dokumen.trim() !== '',
        );

        const payload = {
            tanggal_prakualifikasi: qualificationDate,
            nomor_prakualifikasi: qualificationNumber.trim() || undefined,
            vendor_id: vendorId,
            kode_vendor: vendorCode.trim(),
            nama_vendor: vendorName.trim(),
            jenis_vendor: vendorType.trim() || null,
            nama_pic: picName.trim() || null,
            npwp_ktp: taxOrIdNumber.trim() || null,
            telepon: phone.trim() || null,
            email: email.trim() || null,
            media_sosial: socialMedia.trim() || null,
            alamat: address.trim() || null,
            status_vendor: vendorStatus,
            status_badan_hukum: legalStatus,
            status_kualifikasi: qualificationStatus,
            waktu_kesediaan_item: leadTime.trim() || null,
            jumlah_karyawan:
                employeeCount && !isNaN(parseInt(employeeCount, 10))
                    ? parseInt(employeeCount, 10)
                    : null,
            catatan: notes.trim() || null,
            dokumen: [...documentList, ...validExtraDocs],
            items: validItems,
        };

        setSubmitting(true);

        try {
            if (isEdit && initialData?.id) {
                await api(`/prakualifikasi/${initialData.id}`, {
                    method: 'PUT',
                    body: JSON.stringify(payload),
                });
                toast.success('Data prakualifikasi berhasil diperbarui.');
            } else {
                await api('/prakualifikasi', {
                    method: 'POST',
                    body: JSON.stringify(payload),
                });
                toast.success('Data prakualifikasi berhasil disimpan.');
            }

            onSuccess();
            onOpenChange(false);
        } catch (err) {
            toastSaveError(err, 'Gagal menyimpan data prakualifikasi.');
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <>
            <Dialog open={open} onOpenChange={onOpenChange}>
                <DialogContent className="flex max-h-[90vh] max-w-4xl flex-col overflow-hidden border p-0 shadow-xl">
                    <DialogHeader className="bg-muted/20 shrink-0 border-b p-4">
                        <div className="flex items-center justify-between">
                            <DialogTitle className="flex items-center gap-2 text-lg font-bold">
                                <Building2 className="text-primary h-5 w-5" />
                                Data Prakualifikasi
                            </DialogTitle>
                            <div className="flex items-center gap-1.5">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => {
                                        resetToEmptyForm();
                                        toast.info('Form telah dikosongkan.');
                                    }}
                                    className="text-muted-foreground hover:text-foreground h-7 text-xs"
                                    title="Kosongkan form"
                                >
                                    <RotateCcw className="mr-1.5 h-3.5 w-3.5" />
                                    Kosongkan
                                </Button>
                            </div>
                        </div>
                        <DialogDescription className="text-muted-foreground text-xs">
                            Evaluasi kelengkapan berkas kualifikasi, profil
                            badan hukum, dan kesediaan item rekanan vendor.
                        </DialogDescription>
                    </DialogHeader>

                    <form
                        onSubmit={handleSubmit}
                        className="flex flex-1 flex-col overflow-hidden"
                    >
                        <div className="flex-1 space-y-5 overflow-y-auto p-5">
                            {/* 1. INFORMASI UMUM & VENDOR (2 Kolom) */}
                            <div className="bg-card rounded-lg border p-4 shadow-sm">
                                <div className="text-muted-foreground mb-3 text-xs font-semibold uppercase tracking-wider">
                                    Informasi Prakualifikasi & Identitas Rekanan
                                </div>

                                <div className="grid grid-cols-1 gap-4 text-xs md:grid-cols-2">
                                    {/* KOLOM KIRI (7 Baris) */}
                                    <div className="space-y-3">
                                        <div>
                                            <Label className="text-xs font-medium">
                                                Tanggal Prakualifikasi{' '}
                                                <span className="text-destructive">
                                                    *
                                                </span>
                                            </Label>
                                            <Input
                                                type="date"
                                                value={qualificationDate}
                                                onChange={(e) =>
                                                    setQualificationDate(
                                                        e.target.value,
                                                    )
                                                }
                                                required
                                                className="mt-1 h-8 text-xs"
                                            />
                                        </div>

                                        <div>
                                            <Label className="text-xs font-medium">
                                                Kode Vendor/Supp{' '}
                                                <span className="text-destructive">
                                                    *
                                                </span>
                                            </Label>
                                            <div className="mt-1 flex gap-2">
                                                <Input
                                                    value={vendorCode}
                                                    onChange={(e) =>
                                                        setVendorCode(
                                                            e.target.value,
                                                        )
                                                    }
                                                    required
                                                    placeholder="Contoh: Supp-001..."
                                                    className="h-8 font-mono text-xs font-medium"
                                                />
                                                <Button
                                                    type="button"
                                                    variant="secondary"
                                                    size="sm"
                                                    onClick={() =>
                                                        setLookupOpen(true)
                                                    }
                                                    className="h-8 shrink-0 text-xs font-medium"
                                                >
                                                    <Search className="mr-1 h-3.5 w-3.5" />
                                                    Cari Data
                                                </Button>
                                            </div>
                                            <p className="text-muted-foreground mt-1 text-[10px]">
                                                Bisa diketik manual atau dipilih
                                                otomatis dari Master Vendor via
                                                tombol &quot;Cari Data&quot;.
                                            </p>
                                        </div>

                                        <div>
                                            <Label className="text-xs font-medium">
                                                Nama Vendor/Supp{' '}
                                                <span className="text-destructive">
                                                    *
                                                </span>
                                            </Label>
                                            <Input
                                                value={vendorName}
                                                onChange={(e) =>
                                                    setVendorName(
                                                        e.target.value,
                                                    )
                                                }
                                                required
                                                placeholder="Nama vendor / rekanan"
                                                className="mt-1 h-8 text-xs font-semibold"
                                            />
                                        </div>

                                        <div>
                                            <Label className="text-xs font-medium">
                                                Jenis Vendor/Supp
                                            </Label>
                                            <Input
                                                value={vendorType}
                                                onChange={(e) =>
                                                    setVendorType(
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="Contoh: Distributor / Pabrikan / Subkontraktor"
                                                className="mt-1 h-8 text-xs font-medium"
                                            />
                                        </div>

                                        <div>
                                            <Label className="text-xs font-medium">
                                                Nama PIC Vendor/Supp
                                            </Label>
                                            <Input
                                                value={picName}
                                                onChange={(e) =>
                                                    setPicName(e.target.value)
                                                }
                                                placeholder="Nama contact person / PIC rekanan"
                                                className="mt-1 h-8 text-xs font-medium"
                                            />
                                        </div>

                                        <div>
                                            <Label className="text-xs font-medium">
                                                NPWP/KTP
                                            </Label>
                                            <Input
                                                value={taxOrIdNumber}
                                                onChange={(e) =>
                                                    setTaxOrIdNumber(
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="Nomor pokok wajib pajak / KTP"
                                                className="mt-1 h-8 font-mono text-xs"
                                            />
                                        </div>

                                        <div>
                                            <Label className="text-xs font-medium">
                                                Telepon/HP
                                            </Label>
                                            <Input
                                                value={phone}
                                                onChange={(e) =>
                                                    setPhone(e.target.value)
                                                }
                                                placeholder="Nomor kontak / HP rekanan"
                                                className="mt-1 h-8 font-mono text-xs"
                                            />
                                        </div>
                                    </div>

                                    {/* KOLOM KANAN (7 Baris) */}
                                    <div className="space-y-3">
                                        <div>
                                            <Label className="text-xs font-medium">
                                                No Prakualifikasi
                                            </Label>
                                            <Input
                                                value={qualificationNumber}
                                                onChange={(e) =>
                                                    setQualificationNumber(
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="Otomatis (contoh: Pra/VIII/26/0001)"
                                                className="mt-1 h-8 font-mono text-xs font-medium"
                                            />
                                        </div>

                                        <div>
                                            <Label className="text-xs font-medium">
                                                Email
                                            </Label>
                                            <Input
                                                type="email"
                                                value={email}
                                                onChange={(e) =>
                                                    setEmail(e.target.value)
                                                }
                                                placeholder="Alamat email resmi rekanan"
                                                className="mt-1 h-8 text-xs"
                                            />
                                        </div>

                                        <div>
                                            <Label className="text-xs font-medium">
                                                Media Sosial
                                            </Label>
                                            <Input
                                                value={socialMedia}
                                                onChange={(e) =>
                                                    setSocialMedia(
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="Website / LinkedIn / IG rekanan"
                                                className="mt-1 h-8 text-xs"
                                            />
                                        </div>

                                        <div>
                                            <Label className="text-xs font-medium">
                                                Alamat Vendor
                                            </Label>
                                            <Input
                                                value={address}
                                                onChange={(e) =>
                                                    setAddress(e.target.value)
                                                }
                                                placeholder="Alamat kantor / gudang rekanan"
                                                className="mt-1 h-8 text-xs"
                                            />
                                        </div>

                                        <div>
                                            <Label className="text-xs font-medium">
                                                Status Badan Hukum
                                            </Label>
                                            <NativeSelect
                                                size="sm"
                                                value={legalStatus}
                                                onChange={(e) =>
                                                    setLegalStatus(
                                                        e.target.value,
                                                    )
                                                }
                                                className="mt-1 text-xs"
                                            >
                                                <NativeSelectOption value="Badan Hukum">
                                                    Badan Hukum
                                                </NativeSelectOption>
                                                <NativeSelectOption value="Bukan Badan Hukum">
                                                    Bukan Badan Hukum
                                                </NativeSelectOption>
                                                <NativeSelectOption value="PT">
                                                    PT
                                                </NativeSelectOption>
                                                <NativeSelectOption value="CV">
                                                    CV
                                                </NativeSelectOption>
                                            </NativeSelect>
                                        </div>

                                        <div>
                                            <Label className="text-xs font-medium">
                                                Waktu Kesediaan Item
                                            </Label>
                                            <Input
                                                value={leadTime}
                                                onChange={(e) =>
                                                    setLeadTime(e.target.value)
                                                }
                                                placeholder="Contoh: 7 Hari"
                                                className="mt-1 h-8 text-xs"
                                            />
                                        </div>

                                        <div>
                                            <Label className="text-xs font-medium">
                                                Jumlah Karyawan yg dimiliki
                                            </Label>
                                            <Input
                                                type="number"
                                                value={employeeCount}
                                                onChange={(e) =>
                                                    setEmployeeCount(
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="Contoh: 45"
                                                className="mt-1 h-8 font-mono text-xs"
                                            />
                                        </div>

                                        <div>
                                            <Label className="text-xs font-medium">
                                                Status Kualifikasi
                                            </Label>
                                            <NativeSelect
                                                size="sm"
                                                value={qualificationStatus}
                                                onChange={(e) =>
                                                    setQualificationStatus(
                                                        e.target.value,
                                                    )
                                                }
                                                className="mt-1 text-xs font-medium"
                                            >
                                                <NativeSelectOption value="Lolos">
                                                    Lolos
                                                </NativeSelectOption>
                                                <NativeSelectOption value="Batal">
                                                    Batal
                                                </NativeSelectOption>
                                            </NativeSelect>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {/* 2. DOKUMEN & CATATAN (2 Kolom Bersisian) */}
                            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                {/* KIRI: Kelengkapan Dokumen (10 Dokumen Acuan) */}
                                <Card className="shadow-sm">
                                    <CardHeader className="bg-muted/20 border-b px-4 py-3">
                                        <div className="flex items-center justify-between">
                                            <CardTitle className="flex items-center gap-1.5 text-xs font-semibold">
                                                <FileText className="text-primary h-4 w-4" />
                                                Kelengkapan Dokumen
                                            </CardTitle>
                                            <Badge
                                                variant="secondary"
                                                className="text-[11px] font-normal"
                                            >
                                                {
                                                    documentList.filter(
                                                        (d) => d.ada,
                                                    ).length
                                                }{' '}
                                                / {documentList.length} Ada
                                            </Badge>
                                        </div>
                                    </CardHeader>
                                    <CardContent className="max-h-72 overflow-y-auto p-0">
                                        <DataTable
                                            scrollMode="inline"
                                            showRowNumbers={false}
                                            columns={standardDocColumns}
                                            data={documentList}
                                            getRowKey={(doc) =>
                                                doc.nama_dokumen
                                            }
                                            renderRows={() => (
                                                <>
                                                    {documentList.map(
                                                        (doc, idx) => (
                                                            <TableRow
                                                                key={
                                                                    doc.nama_dokumen
                                                                }
                                                                className="h-7 text-xs"
                                                            >
                                                                <TableCell className="py-1 text-center font-mono">
                                                                    {idx + 1}
                                                                </TableCell>
                                                                <TableCell className="py-1 font-medium">
                                                                    {
                                                                        doc.nama_dokumen
                                                                    }
                                                                </TableCell>
                                                                <TableCell className="py-1 text-center">
                                                                    <input
                                                                        type="radio"
                                                                        name={`doc-${idx}`}
                                                                        checked={
                                                                            doc.ada ===
                                                                            true
                                                                        }
                                                                        onChange={() =>
                                                                            handleToggleDoc(
                                                                                idx,
                                                                                true,
                                                                            )
                                                                        }
                                                                        className="text-primary h-3.5 w-3.5 cursor-pointer"
                                                                    />
                                                                </TableCell>
                                                                <TableCell className="py-1 text-center">
                                                                    <input
                                                                        type="radio"
                                                                        name={`doc-${idx}`}
                                                                        checked={
                                                                            doc.ada ===
                                                                            false
                                                                        }
                                                                        onChange={() =>
                                                                            handleToggleDoc(
                                                                                idx,
                                                                                false,
                                                                            )
                                                                        }
                                                                        className="text-muted-foreground h-3.5 w-3.5 cursor-pointer"
                                                                    />
                                                                </TableCell>
                                                            </TableRow>
                                                        ),
                                                    )}
                                                </>
                                            )}
                                        />
                                    </CardContent>
                                </Card>

                                {/* KANAN: Dokumen Lainnya & Catatan */}
                                <div className="space-y-4">
                                    <Card className="shadow-sm">
                                        <CardHeader className="bg-muted/20 border-b px-4 py-2.5">
                                            <div className="flex items-center justify-between">
                                                <CardTitle className="text-xs font-semibold">
                                                    Dokumen Lainnya (Sebutkan)
                                                </CardTitle>
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={handleAddExtraDoc}
                                                    className="h-6 px-2 text-[11px]"
                                                >
                                                    <Plus className="mr-1 h-3 w-3" />
                                                    Tambah Dokumen
                                                </Button>
                                            </div>
                                        </CardHeader>
                                        <CardContent className="max-h-36 overflow-y-auto p-0">
                                            <DataTable
                                                scrollMode="inline"
                                                showRowNumbers={false}
                                                columns={additionalDocColumns}
                                                data={additionalDocuments}
                                                getRowKey={(doc) =>
                                                    doc.id ||
                                                    doc.nama_dokumen ||
                                                    doc.urutan
                                                }
                                                renderRows={() => (
                                                    <>
                                                        {additionalDocuments.length ===
                                                        0 ? (
                                                            <TableRow>
                                                                <TableCell
                                                                    colSpan={4}
                                                                    className="text-muted-foreground py-4 text-center text-xs"
                                                                >
                                                                    Belum ada
                                                                    dokumen
                                                                    tambahan.
                                                                    Klik Tambah
                                                                    Dokumen
                                                                    untuk
                                                                    mengisi.
                                                                </TableCell>
                                                            </TableRow>
                                                        ) : (
                                                            additionalDocuments.map(
                                                                (doc, idx) => (
                                                                    <TableRow
                                                                        key={`extra-doc-${idx}`}
                                                                        className="h-7 text-xs"
                                                                    >
                                                                        <TableCell className="py-1 text-center font-mono">
                                                                            {idx +
                                                                                1}
                                                                        </TableCell>
                                                                        <TableCell className="py-1">
                                                                            <Input
                                                                                value={
                                                                                    doc.nama_dokumen
                                                                                }
                                                                                onChange={(
                                                                                    e,
                                                                                ) =>
                                                                                    handleExtraDocChange(
                                                                                        idx,
                                                                                        'nama_dokumen',
                                                                                        e
                                                                                            .target
                                                                                            .value,
                                                                                    )
                                                                                }
                                                                                placeholder="Nama dokumen..."
                                                                                className="h-6 text-xs"
                                                                            />
                                                                        </TableCell>
                                                                        <TableCell className="py-1">
                                                                            <Input
                                                                                value={
                                                                                    doc.keterangan ||
                                                                                    ''
                                                                                }
                                                                                onChange={(
                                                                                    e,
                                                                                ) =>
                                                                                    handleExtraDocChange(
                                                                                        idx,
                                                                                        'keterangan',
                                                                                        e
                                                                                            .target
                                                                                            .value,
                                                                                    )
                                                                                }
                                                                                placeholder="Keterangan..."
                                                                                className="h-6 text-xs"
                                                                            />
                                                                        </TableCell>
                                                                        <TableCell className="py-1 text-center">
                                                                            <Button
                                                                                type="button"
                                                                                variant="ghost"
                                                                                size="sm"
                                                                                onClick={() =>
                                                                                    handleRemoveExtraDoc(
                                                                                        idx,
                                                                                    )
                                                                                }
                                                                                className="text-destructive hover:bg-destructive/10 h-6 w-6 p-0"
                                                                            >
                                                                                <Trash2 className="h-3 w-3" />
                                                                            </Button>
                                                                        </TableCell>
                                                                    </TableRow>
                                                                ),
                                                            )
                                                        )}
                                                    </>
                                                )}
                                            />
                                        </CardContent>
                                    </Card>

                                    <div>
                                        <Label className="mb-1 block text-xs font-semibold">
                                            Catatan :
                                        </Label>
                                        <Textarea
                                            value={notes}
                                            onChange={(e) =>
                                                setNotes(e.target.value)
                                            }
                                            rows={3}
                                            placeholder="Catatan evaluasi kualifikasi vendor..."
                                            className="text-xs"
                                        />
                                    </div>
                                </div>
                            </div>

                            {/* 3. DAFTAR LIST ITEM YANG TERSEDIA (Tabel Interaktif Manual) */}
                            <Card className="shadow-sm">
                                <CardHeader className="bg-muted/20 border-b px-4 py-3">
                                    <div className="flex items-center justify-between">
                                        <div className="flex items-center gap-2">
                                            <Package className="text-primary h-4 w-4" />
                                            <CardTitle className="text-xs font-semibold">
                                                Daftar List Item yang tersedia
                                            </CardTitle>
                                        </div>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={handleAddItem}
                                            className="h-7 text-xs"
                                        >
                                            <Plus className="mr-1 h-3.5 w-3.5" />
                                            Tambah Item
                                        </Button>
                                    </div>
                                </CardHeader>
                                <CardContent className="overflow-x-auto p-0">
                                    <DataTable
                                        scrollMode="inline"
                                        showRowNumbers={false}
                                        columns={itemFormColumns}
                                        data={items}
                                        getRowKey={(item) =>
                                            item.id ||
                                            item.kode_item ||
                                            item.nama_item ||
                                            item.urutan
                                        }
                                        renderRows={() => (
                                            <>
                                                {items.map((item, idx) => (
                                                    <TableRow
                                                        key={`item-${idx}`}
                                                        className="h-8 text-xs"
                                                    >
                                                        <TableCell className="py-1 text-center font-mono">
                                                            {idx + 1}
                                                        </TableCell>
                                                        <TableCell className="py-1">
                                                            <Input
                                                                value={
                                                                    item.jenis_item
                                                                }
                                                                onChange={(e) =>
                                                                    handleItemChange(
                                                                        idx,
                                                                        'jenis_item',
                                                                        e.target
                                                                            .value,
                                                                    )
                                                                }
                                                                placeholder="Obat / BMHP"
                                                                className="h-7 text-center text-xs"
                                                            />
                                                        </TableCell>
                                                        <TableCell className="py-1">
                                                            <Input
                                                                value={
                                                                    item.kode_item
                                                                }
                                                                onChange={(e) =>
                                                                    handleItemChange(
                                                                        idx,
                                                                        'kode_item',
                                                                        e.target
                                                                            .value,
                                                                    )
                                                                }
                                                                placeholder="Kode..."
                                                                className="h-7 text-center font-mono text-xs"
                                                            />
                                                        </TableCell>
                                                        <TableCell className="py-1">
                                                            <Input
                                                                value={
                                                                    item.nama_item
                                                                }
                                                                onChange={(e) =>
                                                                    handleItemChange(
                                                                        idx,
                                                                        'nama_item',
                                                                        e.target
                                                                            .value,
                                                                    )
                                                                }
                                                                placeholder="Nama item..."
                                                                className="h-7 text-center text-xs font-medium"
                                                            />
                                                        </TableCell>
                                                        <TableCell className="py-1">
                                                            <Input
                                                                value={
                                                                    item.satuan
                                                                }
                                                                onChange={(e) =>
                                                                    handleItemChange(
                                                                        idx,
                                                                        'satuan',
                                                                        e.target
                                                                            .value,
                                                                    )
                                                                }
                                                                placeholder="Satuan..."
                                                                className="h-7 text-center text-xs"
                                                            />
                                                        </TableCell>
                                                        <TableCell className="py-1">
                                                            <Input
                                                                value={
                                                                    item.kesediaan
                                                                }
                                                                onChange={(e) =>
                                                                    handleItemChange(
                                                                        idx,
                                                                        'kesediaan',
                                                                        e.target
                                                                            .value,
                                                                    )
                                                                }
                                                                placeholder="Kesediaan..."
                                                                className="h-7 text-center font-mono text-xs"
                                                            />
                                                        </TableCell>
                                                        <TableCell className="py-1 text-center">
                                                            <Button
                                                                type="button"
                                                                variant="ghost"
                                                                size="sm"
                                                                onClick={() =>
                                                                    handleRemoveItem(
                                                                        idx,
                                                                    )
                                                                }
                                                                className="text-destructive hover:bg-destructive/10 h-7 w-7 p-0"
                                                            >
                                                                <Trash2 className="h-3.5 w-3.5" />
                                                            </Button>
                                                        </TableCell>
                                                    </TableRow>
                                                ))}
                                            </>
                                        )}
                                    />
                                </CardContent>
                            </Card>
                        </div>

                        {/* TOMBOL AKSI SESUAI ACUAN DIAGRAM: Kembali, Batal, Edit, Simpan */}
                        <DialogFooter className="bg-card flex shrink-0 items-center justify-end gap-2 border-t p-3 px-5">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => onOpenChange(false)}
                                disabled={submitting}
                                className="h-8 text-xs font-medium"
                            >
                                Kembali
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={resetToEmptyForm}
                                disabled={submitting}
                                className="text-muted-foreground hover:text-foreground h-8 text-xs font-medium"
                            >
                                Batal
                            </Button>
                            {isEdit && (
                                <Button
                                    type="button"
                                    variant="secondary"
                                    onClick={() => {
                                        toast.info('Mode ubah data aktif.');
                                    }}
                                    disabled={submitting}
                                    className="h-8 text-xs font-medium"
                                >
                                    Edit
                                </Button>
                            )}
                            <DialogAction
                                type="submit"
                                disabled={submitting || !vendorCode}
                            >
                                {submitting ? 'Menyimpan…' : 'Simpan'}
                            </DialogAction>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Vendor Lookup Cari Data Modal */}
            <VendorLookupModal
                open={lookupOpen}
                onOpenChange={setLookupOpen}
                onSelect={handleSelectVendor}
            />
        </>
    );
}
