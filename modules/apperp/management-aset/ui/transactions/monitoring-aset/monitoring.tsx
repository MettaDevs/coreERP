import { router } from '@inertiajs/react';
import { Badge } from '@apperp/ui/badge';

/**
 * Bentuk data, kosakata status, dan navigasi monitoring aset — dipakai bersama oleh daftar dan
 * halaman rincian, sehingga keduanya menyebut status dan hasil dengan kata yang sama.
 */

export type Context = {
    legal_entity_id: string | null;
    org_unit_id: string | null;
    user_id?: string | null;
};

export type MonitoringLine = {
    id?: string;
    line_number?: number;
    aset_id: string;
    /** Temuan pemeriksa; `null` berarti belum diperiksa. */
    ada: boolean | null;
    kondisi_aset_id: string;
    keterangan: string;
    /** Diisi server; kolom bacaan, bukan isian. */
    aset_kode?: string;
    aset_nama?: string;
    spesifikasi?: string;
    kondisi_aset_nama?: string | null;
    /**
     * Keadaan register: nilai beku bila monitoring sudah selesai, keadaan aset sekarang bila
     * masih draf. Server yang memilih; layar tinggal menampilkannya.
     */
    sistem_lifecycle_state?: string | null;
    sistem_lifecycle_label?: string;
    sistem_lokasi_id?: string | null;
    sistem_lokasi_nama?: string | null;
    sistem_org_unit_nama?: string | null;
    sistem_custodian_nama?: string | null;
    nilai_perolehan?: string | null;
    akumulasi_penyusutan?: string | null;
    nilai_buku?: string | null;
    /** Dihitung server dari status aset dan temuan; `null` bila belum diperiksa. */
    hasil?: 'sesuai' | 'tidak_sesuai' | null;
};

export type Monitoring = {
    id: string;
    kode: string;
    status: string;
    version: number;
    tanggal: string;
    legal_entity_id: string;
    responsible_org_unit_id: string | null;
    penanggung_jawab_user_id: string | null;
    lokasi_aset_id: string;
    lokasi_aset_kode?: string | null;
    lokasi_aset_nama?: string | null;
    keterangan: string | null;
    diselesaikan_pada?: string | null;
    jumlah_baris?: number;
    jumlah_belum_diperiksa?: number;
    jumlah_tidak_sesuai?: number;
    responsible_org_unit_nama?: string | null;
    penanggung_jawab_nama?: string | null;
    details?: MonitoringLine[];
};

export type EditableMonitoring = Partial<Monitoring> & {
    details: MonitoringLine[];
};

/** Label dan warna status dokumen. Dua saja: monitoring tidak melewati persetujuan. */
export const STATUS: Record<
    string,
    { label: string; variant: 'default' | 'secondary' | 'outline' }
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

/** Hasil baris. Selalu dengan teks, bukan warna saja. */
export function ResultBadge({ hasil }: { hasil?: string | null }) {
    if (hasil === 'sesuai') {
        return <Badge variant="secondary">Sesuai</Badge>;
    }

    if (hasil === 'tidak_sesuai') {
        return <Badge variant="destructive">Tidak sesuai</Badge>;
    }

    return <Badge variant="outline">Belum diperiksa</Badge>;
}

export const PRESENCE = [
    { value: 'ada', label: 'Ada' },
    { value: 'tidak', label: 'Tidak ada' },
];

export const presenceValue = (ada: boolean | null) =>
    ada === null ? null : ada ? 'ada' : 'tidak';

export const emptyLine = (): MonitoringLine => ({
    aset_id: '',
    ada: null,
    kondisi_aset_id: '',
    keterangan: '',
});

export const emptyMonitoring = (workDate: string): EditableMonitoring => ({
    tanggal: workDate,
    lokasi_aset_id: '',
    responsible_org_unit_id: '',
    penanggung_jawab_user_id: '',
    keterangan: '',
    details: [],
});

/**
 * Hak akses monitoring. Menyusun, mengarsipkan, dan menyelesaikan adalah permission terpisah
 * dalam satu duty; layar mengikuti pemisahan itu apa adanya.
 */
export const izin = (permissions: string[]) => (action: string) =>
    permissions.includes(`management-aset.monitoring-aset.${action}`);

export const RESOURCE = 'monitoring-aset';
const alamat = (...ruas: string[]) =>
    ['/management-aset', RESOURCE, ...ruas].join('/');
export const bukaDaftar = () => router.visit(alamat());
export const bukaMonitoring = (id: string) => router.visit(alamat(id));
export const bukaMonitoringUbah = (id: string) =>
    router.visit(alamat(id, 'ubah'));
export const bukaMonitoringBaru = () => router.visit(alamat('baru'));

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
