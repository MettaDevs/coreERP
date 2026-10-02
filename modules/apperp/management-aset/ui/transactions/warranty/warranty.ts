/** Bentuk data layar garansi dan kontrak servis, sama dengan kontraknya. */
export type Warranty = {
    id: string;
    aset_id: string;
    aset_kode: string;
    aset_nama: string | null;
    legal_entity_id: string;
    vendor_id: string | null;
    vendor: { id: string; number: string; name: string } | null;
    jenis_garansi: 'penuh' | 'sebagian';
    jenis_garansi_label: string;
    nomor_referensi: string | null;
    berlaku_mulai: string;
    berlaku_sampai: string;
    catatan: string | null;
    berlaku: boolean;
    sisa_hari: number;
    version: number;
};

export type Contract = {
    id: string;
    kode: string;
    legal_entity_id: string;
    nomor_kontrak: string;
    vendor_id: string;
    vendor: { id: string; number: string; name: string } | null;
    berlaku_mulai: string;
    berlaku_sampai: string;
    cakupan: string | null;
    nilai_kontrak: string | null;
    keterangan: string | null;
    lines_count: number | null;
    berlaku: boolean;
    sisa_hari: number;
    version: number;
    aset?: {
        line_number: number;
        aset_id: string;
        aset_kode: string;
        aset_nama: string | null;
    }[];
};

export type Expiring = {
    jenis: 'garansi' | 'kontrak_servis';
    id: string;
    referensi: string | null;
    aset_kode: string | null;
    aset_nama: string | null;
    jumlah_aset: number;
    vendor_nama: string | null;
    berlaku_sampai: string;
    sisa_hari: number;
};

export type ActiveWarranty = {
    aset_id: string;
    jenis: 'garansi' | 'kontrak_servis';
    referensi: string | null;
    vendor_nama: string | null;
    jenis_garansi: 'penuh' | 'sebagian' | null;
    cakupan: string | null;
    berlaku_sampai: string;
};

export const WARRANTY_TYPES = [
    { value: 'penuh', label: 'Penuh' },
    { value: 'sebagian', label: 'Sebagian' },
];
