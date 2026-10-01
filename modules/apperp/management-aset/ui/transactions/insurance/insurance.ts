/** Bentuk data layar asuransi aset, sama dengan kontrak `InsurancePolicy` dan `InsuranceCoverage`. */
export type Coverage = {
    id: string;
    aset_id: string;
    aset_kode: string;
    aset_nama: string | null;
    nilai_pertanggungan: string;
    berlaku_mulai: string;
    berlaku_sampai: string | null;
    keterangan: string | null;
    berjalan: boolean;
    polis_kode?: string;
    polis_nama?: string;
    nomor_polis?: string;
};

export type Policy = {
    id: string;
    kode: string;
    legal_entity_id: string;
    nama: string;
    nomor_polis: string;
    jenis_asuransi_id: string | null;
    jenis_asuransi_nama: string | null;
    vendor_id: string | null;
    vendor: { id: string; number: string; name: string; status: string } | null;
    berlaku_mulai: string;
    berlaku_sampai: string | null;
    premi_tahunan: string;
    nilai_pertanggungan: string;
    diblokir: boolean;
    keterangan: string | null;
    total_nilai_tertanggung: string;
    selisih_plafon: string;
    berlaku: boolean;
    version: number;
    pertanggungan?: Coverage[];
};

export type InsuranceStatus =
    'tidak_diasuransikan' | 'kurang_diasuransikan' | 'cukup';

export const STATUS_LABELS: Record<InsuranceStatus, string> = {
    tidak_diasuransikan: 'Belum diasuransikan',
    kurang_diasuransikan: 'Kurang diasuransikan',
    cukup: 'Cukup',
};
