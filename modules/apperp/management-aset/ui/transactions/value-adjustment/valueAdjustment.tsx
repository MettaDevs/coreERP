import { router } from '@inertiajs/react';
import { Badge } from '@apperp/ui/badge';
import type { CancellationSummary } from '../_shared/CancellationStatus';

/**
 * Bentuk data, kosakata, dan navigasi penyesuaian nilai aset — dipakai bersama oleh daftar dan halaman
 * rincian.
 */

export type Context = {
    legal_entity_id: string | null;
    org_unit_id: string | null;
};

export type AdjustmentLine = {
    id?: string;
    line_number?: number;
    aset_id: string;
    nilai: string;
    keterangan: string;
    aset_kode?: string | null;
    aset_nama?: string | null;
    /** Diisi server: nilai buku sekarang selama draf, nilai beku sesudah diposting. */
    nilai_buku_sebelum?: string | null;
    nilai_buku_sesudah?: string | null;
};

export type Adjustment = {
    id: string;
    kode: string;
    jenis: 'write_down' | 'appreciation';
    status: string;
    cancellation?: CancellationSummary | null;
    version: number;
    legal_entity_id: string;
    responsible_org_unit_id: string;
    responsible_org_unit_nama?: string | null;
    buku_id: string;
    buku_kode?: string | null;
    buku_nama?: string | null;
    buku_di_post?: boolean;
    tanggal: string;
    keterangan: string;
    diposting_pada?: string | null;
    posting_id?: string | null;
    posting?: { posting_id: string; status: string } | null;
    jumlah_baris?: number;
    total_nilai?: string;
    details?: AdjustmentLine[];
};

export type EditableAdjustment = {
    jenis: string;
    buku_id: string;
    tanggal: string;
    keterangan: string;
    responsible_org_unit_id: string;
    details: AdjustmentLine[];
};

/** Jenis penyesuaian, dengan istilah bakunya. */
export const KINDS = [
    { value: 'write_down', label: 'Penurunan nilai (write-down)' },
    { value: 'appreciation', label: 'Kenaikan nilai (revaluasi)' },
];

export const kindLabel = (value?: string | null) =>
    KINDS.find((kind) => kind.value === value)?.label ?? value ?? '—';

export const STATUS: Record<
    string,
    { label: string; variant: 'default' | 'secondary' | 'outline' }
> = {
    draft: { label: 'Draf', variant: 'outline' },
    cancelled: { label: 'Dibatalkan', variant: 'outline' },
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
            return 'tidak ada; buku ini tidak dikirim ke aplikasi finance';
    }
}

export const emptyLine = (): AdjustmentLine => ({
    aset_id: '',
    nilai: '',
    keterangan: '',
});

export const izin = (permissions: string[]) => (action: string) =>
    permissions.includes(`management-aset.penyesuaian-nilai-aset.${action}`);

export const RESOURCE = 'penyesuaian-nilai-aset';
const alamat = (...ruas: string[]) =>
    ['/management-aset', RESOURCE, ...ruas].join('/');
export const bukaDaftar = () => router.visit(alamat());
export const bukaPenyesuaian = (id: string) => router.visit(alamat(id));
export const bukaPenyesuaianUbah = (id: string) =>
    router.visit(alamat(id, 'ubah'));
export const bukaPenyesuaianBaru = () => router.visit(alamat('baru'));

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
