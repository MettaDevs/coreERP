import { router } from '@inertiajs/react';
import { Badge } from '@apperp/ui/badge';

/**
 * Bentuk data, kosakata status, dan navigasi penjualan dan pemusnahan aset — dipakai bersama oleh daftar
 * dan halaman rincian. Kedua jenis memakai layar yang sama; yang berbeda hanya isi `DISPOSALS`.
 */

export type DisposalResource = 'penjualan-aset' | 'pemusnahan-aset';

export type Disposal = {
    id: string;
    kode: string;
    jenis_dokumen: DisposalResource;
    status: string;
    version: number;
    legal_entity_id: string;
    responsible_org_unit_id: string;
    aset_id: string;
    aset_kode?: string | null;
    aset_nama?: string | null;
    currency_code?: string | null;
    tanggal: string;
    nilai: string | null;
    keterangan: string | null;
    posting?: { posting_id: string; status: string } | null;
};

/** Saldo buku yang dikeluarkan, dari pratinjau posting. */
export type DisposalAmounts = {
    book: string;
    acquisition_value: string;
    accumulated_depreciation: string;
    write_down_amount: string;
    appreciation_amount: string;
    net_book_value: string;
    proceeds: string;
    gain_loss: string;
};

export const DISPOSALS: Record<
    DisposalResource,
    {
        title: string;
        noun: string;
        create: string;
        hasProceeds: boolean;
        empty: string;
    }
> = {
    'penjualan-aset': {
        title: 'Penjualan aset',
        noun: 'penjualan',
        create: 'Buat draf penjualan',
        hasProceeds: true,
        empty: 'Penjualan mencatat aset yang dijual. Aset baru dilepas dan jurnalnya dikirim ke aplikasi finance saat draf diposting.',
    },
    'pemusnahan-aset': {
        title: 'Pemusnahan aset',
        noun: 'pemusnahan',
        create: 'Buat draf pemusnahan',
        hasProceeds: false,
        empty: 'Pemusnahan mencatat aset yang dibuang atau dihancurkan. Aset baru dilepas dan nilai bukunya dicatat sebagai rugi saat draf diposting.',
    },
};

export const STATUS: Record<
    string,
    { label: string; variant: 'default' | 'secondary' | 'outline' }
> = {
    draft: { label: 'Draf', variant: 'outline' },
    posted: { label: 'Diposting', variant: 'secondary' },
    cancelled: { label: 'Dibatalkan', variant: 'outline' },
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
            return 'Siap diambil aplikasi finance';
        case 'held':
            return 'Tertahan; lihat layar Posting finance';
        case 'manual':
            return 'Dicatat manual, tidak dikirim';
        case 'posted':
            return 'Sudah dibukukan aplikasi finance';
        case 'rejected':
            return 'Ditolak aplikasi finance';
        default:
            return 'Tidak ada jurnal';
    }
}

export const izin =
    (resource: DisposalResource, permissions: string[]) => (action: string) =>
        permissions.includes(`management-aset.${resource}.${action}`);

const alamat = (resource: DisposalResource, ...ruas: string[]) =>
    ['/management-aset', resource, ...ruas].join('/');
export const bukaDaftar = (resource: DisposalResource) =>
    router.visit(alamat(resource));
export const bukaDokumen = (resource: DisposalResource, id: string) =>
    router.visit(alamat(resource, id));
export const bukaBaru = (resource: DisposalResource) =>
    router.visit(alamat(resource, 'baru'));

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
