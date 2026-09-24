import { router } from '@inertiajs/react';
import type {
    PostingCheckLine,
    PostingCheckProblem,
} from '@/components/finance/posting-check';
import { Badge } from '@apperp/ui/badge';
import type { FieldConfig } from '../../master/fields';

/**
 * Bentuk data, kosakata status, dan navigasi dokumen penerimaan aset.
 *
 * Penerimaan tinggal di dalam layar inventarisasi aset, bukan sebagai menu tersendiri:
 * ia satu-satunya pintu masuk ke register, jadi memisahkannya membuat orang harus tahu
 * lebih dulu bahwa dua layar itu bicara tentang benda yang sama.
 */

export type Context = {
    legal_entity_id: string | null;
    org_unit_id: string | null;
    user_id: string | number | null;
};

export type BarisPenerimaan = {
    id?: string;
    line_number?: number;
    nama: string;
    group_aset_id: string;
    jenis_aset_id: string;
    kondisi_aset_id: string;
    pabrikan_aset_id: string;
    model_aset_id: string;
    model_number: string;
    jumlah: number | string;
    nilai_per_unit: number | string;
    ppn_per_unit: number | string;
    residu_per_unit: number | string;
    permintaan_pembelian_detail_id: string;
    keterangan: string;
    /** Diisi server; kolom bacaan, bukan isian. */
    group_aset_nama?: string | null;
    jenis_aset_nama?: string | null;
    kondisi_aset_nama?: string | null;
    pabrikan_aset_nama?: string | null;
    model_aset_nama?: string | null;
};

export type Penerimaan = {
    id: string;
    kode: string;
    status: string;
    version: number;
    tanggal: string;
    tanggal_siap_pakai: string | null;
    legal_entity_id: string;
    responsible_org_unit_id: string;
    receiving_org_unit_id: string | null;
    diterima_oleh_user_id: string | null;
    penanggung_jawab_user_id: string | null;
    lokasi_aset_id: string | null;
    currency_code: string;
    keterangan: string | null;
    cara_perolehan: CaraPerolehan;
    vendor_id: string | null;
    vendor_invoice_reference: string | null;
    vendor_invoice_date: string | null;
    /** Vendor milik Core, dibaca ulang saat dokumen dibuka. */
    vendor?: VendorRingkas | null;
    /** Keadaan jurnal perolehannya di feed posting finance, sesudah diselesaikan. */
    posting?: StatusPosting | null;
    jumlah_baris?: number;
    jumlah_aset?: number;
    lokasi_aset_kode?: string | null;
    lokasi_aset_nama?: string | null;
    /** Nama unit kerja dan nama orang; id-nya tetap dipulangkan di sebelahnya. */
    responsible_org_unit_nama?: string | null;
    receiving_org_unit_nama?: string | null;
    diterima_oleh_nama?: string | null;
    penanggung_jawab_nama?: string | null;
    details?: BarisPenerimaan[];
};

export type EditablePenerimaan = Partial<Penerimaan> & {
    details: BarisPenerimaan[];
};

/** Cara aset diperoleh (K-12). Saldo awal belum lewat penerimaan. */
export type CaraPerolehan = 'pembelian' | 'hibah';

export const CARA_PEROLEHAN: { value: CaraPerolehan; label: string }[] = [
    { value: 'pembelian', label: 'Pembelian' },
    { value: 'hibah', label: 'Hibah' },
];

/** Vendor milik Core (K-06). */
export type VendorRingkas = {
    id: string;
    number: string;
    name: string;
    status: string;
};

/** Keadaan jurnal perolehan di feed posting finance. */
export type StatusPosting = {
    posting_id: string;
    status: string;
    external_reference: string | null;
    reason_code: string | null;
    reason: string | null;
    acknowledged_at: string | null;
    problems: PostingCheckProblem[];
};

/**
 * Pratinjau jurnal perolehan sebelum diselesaikan: baris jurnal, masalahnya, status yang akan
 * diperoleh, dan hal yang menolak penyelesaian. `status` `null` berarti tidak ada nilai yang
 * dijurnal.
 */
export type PratinjauPosting = {
    blockers: { field: string; message: string }[];
    status: string | null;
    settlement_mode: string | null;
    currency: { code: string; decimals: number } | null;
    lines: PostingCheckLine[];
    problems: PostingCheckProblem[];
};

/** Satu peringatan ambang kapitalisasi, satu baris dokumen. */
export type Peringatan = {
    line_number: number;
    nama: string;
    ambang_kapitalisasi: string;
    pesan: string;
};

export type Ringkasan = {
    jumlah_baris: number;
    jumlah_aset: number;
    peringatan: Peringatan[];
};

/** Aset yang lahir dari dokumen, untuk layar pengisian nomor seri. */
export type AsetTerbit = {
    id: string;
    kode: string;
    nama: string;
    serial_number: string | null;
    penerimaan_aset_detail_id: string | null;
};

/** Label dan warna status. Dua saja: penerimaan tidak melewati persetujuan. */
export const STATUS: Record<
    string,
    {
        label: string;
        variant: 'default' | 'secondary' | 'outline' | 'destructive';
    }
> = {
    draft: { label: 'Draf', variant: 'outline' },
    selesai: { label: 'Selesai', variant: 'secondary' },
};

export function StatusBadge({ status }: { status: string }) {
    const tampilan = STATUS[status] ?? {
        label: status,
        variant: 'outline' as const,
    };

    return <Badge variant={tampilan.variant}>{tampilan.label}</Badge>;
}

export const barisKosong = (): BarisPenerimaan => ({
    nama: '',
    group_aset_id: '',
    jenis_aset_id: '',
    kondisi_aset_id: '',
    pabrikan_aset_id: '',
    model_aset_id: '',
    model_number: '',
    jumlah: 1,
    nilai_per_unit: '',
    ppn_per_unit: '',
    residu_per_unit: '',
    permintaan_pembelian_detail_id: '',
    keterangan: '',
});

export const penerimaanKosong = (context: Context): EditablePenerimaan => ({
    // Tanggal hari ini sebagai nilai awal: penerimaan hampir selalu dicatat pada hari
    // barangnya datang, dan tanggal kosong pada dokumen bertanda tangan adalah cacat
    // yang baru ketahuan setelah dicetak.
    tanggal: new Date().toISOString().slice(0, 10),
    tanggal_siap_pakai: '',
    responsible_org_unit_id: context.org_unit_id ?? '',
    receiving_org_unit_id: context.org_unit_id ?? '',
    diterima_oleh_user_id:
        context.user_id === null ? '' : String(context.user_id),
    penanggung_jawab_user_id: '',
    lokasi_aset_id: '',
    currency_code: 'IDR',
    keterangan: '',
    cara_perolehan: 'pembelian',
    vendor_id: '',
    vendor_invoice_reference: '',
    vendor_invoice_date: '',
    details: [barisKosong()],
});

/**
 * Unit kerja, orang, dan lokasi pada kepala dokumen.
 *
 * Semuanya dropdown bernama, bukan kotak teks berisi ULID: tidak ada orang yang hafal
 * ULID, jadi satu-satunya cara mengisinya benar adalah menyalin dari tempat lain — dan
 * satu digit tertukar tersimpan tanpa keluhan.
 */
export const KEPALA_REFERENCES: FieldConfig[] = [
    {
        name: 'responsible_org_unit_id',
        label: 'Unit pengguna',
        type: 'reference',
        resource: 'reference-data/unit-kerja',
        required: true,
        help: 'Unit kerja yang menanggung seluruh aset pada dokumen ini setelah diterima.',
    },
    {
        name: 'receiving_org_unit_id',
        label: 'Unit penerima',
        type: 'reference',
        resource: 'reference-data/unit-kerja',
        help: 'Loket atau gudang yang menerima fisiknya. Sering berbeda dari unit pengguna.',
    },
    {
        name: 'diterima_oleh_user_id',
        label: 'Diterima oleh',
        type: 'reference',
        resource: 'reference-data/anggota',
    },
    {
        name: 'penanggung_jawab_user_id',
        label: 'Penanggung jawab',
        type: 'reference',
        resource: 'reference-data/anggota',
    },
    {
        name: 'lokasi_aset_id',
        label: 'Lokasi awal',
        type: 'reference',
        resource: 'lokasi-aset',
        help: 'Lokasi yang dipetakan ke unit organisasi menentukan dimensi keuangan aset.',
    },
];

/** Penunjuk master pada tiap baris barang. */
export const BARIS_REFERENCES: FieldConfig[] = [
    {
        name: 'group_aset_id',
        label: 'Group aset',
        type: 'reference',
        resource: 'group-aset',
        required: true,
        help: 'Menentukan buku penyusutan dan ambang kapitalisasi.',
    },
    {
        name: 'jenis_aset_id',
        label: 'Jenis aset',
        type: 'reference',
        resource: 'jenis-aset',
        required: true,
        help: 'Menentukan atribut tambahan yang harus diisi.',
    },
    {
        name: 'kondisi_aset_id',
        label: 'Kondisi aset',
        type: 'reference',
        resource: 'kondisi-aset',
    },
    {
        name: 'pabrikan_aset_id',
        label: 'Pabrikan',
        type: 'reference',
        resource: 'pabrikan-aset',
    },
    {
        name: 'model_aset_id',
        label: 'Model aset',
        type: 'reference',
        resource: 'model-aset',
        help: 'Katalog model per pabrikan. Kosongkan bila modelnya belum terdaftar.',
    },
];

/**
 * Hak akses penerimaan.
 *
 * Menyusun berkasnya dan benar-benar menambah aset ke register adalah dua wewenang
 * berbeda, dan manifest memisahkannya: yang pertama
 * `management-aset.penerimaan-aset.*`, yang kedua `management-aset.aset.create`. Layar
 * mengikuti pemisahan itu apa adanya — tombol "Selesaikan" tidak muncul untuk juru tulis
 * yang hanya boleh menyiapkan berkasnya.
 */
export const izin = (permissions: string[]) => (action: string) =>
    permissions.includes(`management-aset.penerimaan-aset.${action}`);
export const bolehMendaftarkan = (permissions: string[]) =>
    permissions.includes('management-aset.aset.create');
export const bolehMengoreksiAset = (permissions: string[]) =>
    permissions.includes('management-aset.aset.update');

const alamat = (...ruas: string[]) =>
    ['/management-aset', 'inventarisasi-aset', 'penerimaan', ...ruas].join('/');
export const bukaPenerimaanDaftar = () => router.visit(alamat());
export const bukaPenerimaan = (id: string) => router.visit(alamat(id));
export const bukaPenerimaanUbah = (id: string) =>
    router.visit(alamat(id, 'ubah'));
export const bukaPenerimaanBaru = () => router.visit(alamat('baru'));

/** Tanggal `Y-m-d` menjadi `dd/mm/yyyy`; kosong tetap kosong. */
export const tanggalTampil = (value?: string | null) => {
    if (!value) {
        return '—';
    }

    const [tahun, bulan, hari] = value.slice(0, 10).split('-');

    return hari && bulan && tahun ? `${hari}/${bulan}/${tahun}` : value;
};
