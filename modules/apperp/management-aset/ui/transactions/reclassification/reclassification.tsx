import { router } from '@inertiajs/react';
import { Badge } from '@apperp/ui/badge';

/**
 * Bentuk data, kosakata, dan navigasi reklasifikasi aset — dipakai bersama oleh daftar dan halaman rincian.
 */

export type Context = {
    legal_entity_id: string | null;
    org_unit_id: string | null;
};

export type MovedBook = {
    buku_id: string;
    nilai_perolehan: string;
    akumulasi_penyusutan: string;
    penurunan_nilai: string;
    kenaikan_nilai: string;
    dijurnal: boolean;
};

export type ReclassificationLine = {
    id?: string;
    line_number?: number;
    aset_id: string;
    group_aset_tujuan_id: string;
    /** Pecah saja: salah satu dari persentase atau nilai perolehan. */
    persen: string;
    nilai_perolehan: string;
    nama_aset_baru: string;
    keterangan: string;
    aset_kode?: string | null;
    aset_nama?: string | null;
    nilai_perolehan_aset?: string | null;
    group_aset_asal_nama?: string | null;
    group_aset_tujuan_nama?: string | null;
    /** Diisi saat diposting. */
    aset_baru_id?: string | null;
    aset_baru_kode?: string | null;
    nilai_perolehan_dipindah?: string | null;
    books?: MovedBook[];
};

export type Reclassification = {
    id: string;
    kode: string;
    jenis: 'pindah_group' | 'pecah';
    status: string;
    version: number;
    legal_entity_id: string;
    responsible_org_unit_id: string;
    responsible_org_unit_nama?: string | null;
    tanggal: string;
    keterangan: string;
    diposting_pada?: string | null;
    posting_id?: string | null;
    posting?: { posting_id: string; status: string } | null;
    jumlah_baris?: number;
    details?: ReclassificationLine[];
};

export type EditableReclassification = {
    jenis: string;
    tanggal: string;
    keterangan: string;
    responsible_org_unit_id: string;
    details: ReclassificationLine[];
};

/** Jenis reklasifikasi. */
export const KINDS = [
    { value: 'pindah_group', label: 'Pindah group aset' },
    { value: 'pecah', label: 'Pecah aset' },
];

export const kindLabel = (value?: string | null) =>
    KINDS.find((kind) => kind.value === value)?.label ?? value ?? '—';

export const STATUS: Record<
    string,
    { label: string; variant: 'default' | 'secondary' | 'outline' }
> = {
    draft: { label: 'Draf', variant: 'outline' },
    posted: { label: 'Diposting', variant: 'secondary' },
};

export function StatusBadge({ status }: { status: string }) {
    const tampilan = STATUS[status] ?? {
        label: status,
        variant: 'outline' as const,
    };

    return <Badge variant={tampilan.variant}>{tampilan.label}</Badge>;
}

/** Keadaan jurnal sesudah diposting, dalam bahasa pengguna. */
export function postingLabel(status?: string | null): string {
    switch (status) {
        case 'pending':
            return 'siap diambil aplikasi finance';
        case 'held':
            return 'tertahan; lihat layar Posting finance';
        case 'manual':
            return 'dicatat manual, tidak dikirim';
        case 'posted':
            return 'sudah dibukukan aplikasi finance';
        case 'rejected':
            return 'ditolak aplikasi finance';
        default:
            return 'tidak ada; akun buku besarnya tidak berubah';
    }
}

export const emptyLine = (): ReclassificationLine => ({
    aset_id: '',
    group_aset_tujuan_id: '',
    persen: '',
    nilai_perolehan: '',
    nama_aset_baru: '',
    keterangan: '',
});

export const izin = (permissions: string[]) => (action: string) =>
    permissions.includes(`management-aset.reklasifikasi-aset.${action}`);

export const RESOURCE = 'reklasifikasi-aset';
const alamat = (...ruas: string[]) =>
    ['/management-aset', RESOURCE, ...ruas].join('/');
export const bukaDaftar = () => router.visit(alamat());
export const bukaReklasifikasi = (id: string) => router.visit(alamat(id));
export const bukaReklasifikasiUbah = (id: string) =>
    router.visit(alamat(id, 'ubah'));
export const bukaReklasifikasiBaru = () => router.visit(alamat('baru'));

/** Tanggal `Y-m-d` menjadi `dd/mm/yyyy`; kosong tetap kosong. */
export const tanggalTampil = (value?: string | null) => {
    if (!value) {
        return '—';
    }

    const [tahun, bulan, hari] = value.slice(0, 10).split('-');

    return hari && bulan && tahun ? `${hari}/${bulan}/${tahun}` : value;
};

/** Nilai uang tanpa desimal nol, gaya Indonesia. */
export const uang = (value?: string | null) => {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    const amount = Number(value);

    return Number.isFinite(amount)
        ? new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 }).format(
              amount,
          )
        : value;
};
