import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Badge } from '@apperp/ui/badge';
import { api } from '../../api';

/**
 * Bentuk data, kosakata status, dan navigasi permintaan pemeliharaan — dipakai bersama oleh
 * daftar dan halaman rincian, sehingga keduanya menyebut status dengan kata yang sama.
 */

export type Context = {
    legal_entity_id: string | null;
    org_unit_id: string | null;
    user_id?: string | null;
};

export type MaintenanceRequest = {
    id: string;
    kode: string;
    status: string;
    version: number;
    legal_entity_id: string;
    responsible_org_unit_id: string;
    jenis_permintaan_id: string;
    jenis_permintaan_kode: string | null;
    jenis_permintaan_nama: string | null;
    /** Tipe work order bawaan dari jenis permintaan; kosong berarti harus dipilih saat membuat work order. */
    jenis_permintaan_tipe_work_order_id: string | null;
    aset_id: string | null;
    aset_kode: string | null;
    aset_nama: string | null;
    aset_jenis_aset_id: string | null;
    lokasi_aset_id: string | null;
    lokasi_kode: string | null;
    lokasi_nama: string | null;
    deskripsi: string;
    tingkat_layanan_id: string | null;
    tingkat_layanan_nama: string | null;
    sebab_kerusakan_id: string | null;
    sebab_kerusakan_nama: string | null;
    diajukan_pada: string | null;
    diputuskan_pada: string | null;
    diputuskan_oleh_user_id: string | null;
    alasan_penolakan: string | null;
    pemeliharaan_aset_id: string | null;
    work_order_kode: string | null;
    work_order_status: string | null;
    created_at: string;
    /** Hanya dikirim rincian (`GET .../{id}`), bukan daftar. */
    unit_nama?: string | null;
    dilaporkan_oleh_nama?: string | null;
    diputuskan_oleh_nama?: string | null;
};

/** Isian form; nilai kosong ditulis sebagai string kosong supaya kontrolnya tetap terkendali. */
export type MaintenanceRequestForm = {
    responsible_org_unit_id: string;
    jenis_permintaan_id: string;
    aset_id: string;
    lokasi_aset_id: string;
    deskripsi: string;
    tingkat_layanan_id: string;
    sebab_kerusakan_id: string;
};

export type Option = { id: string; kode: string; nama: string | null };

export const emptyForm = (
    orgUnitId: string | null,
): MaintenanceRequestForm => ({
    responsible_org_unit_id: orgUnitId ?? '',
    jenis_permintaan_id: '',
    aset_id: '',
    lokasi_aset_id: '',
    deskripsi: '',
    tingkat_layanan_id: '',
    sebab_kerusakan_id: '',
});

export const toForm = (data: MaintenanceRequest): MaintenanceRequestForm => ({
    responsible_org_unit_id: data.responsible_org_unit_id ?? '',
    jenis_permintaan_id: data.jenis_permintaan_id ?? '',
    aset_id: data.aset_id ?? '',
    lokasi_aset_id: data.lokasi_aset_id ?? '',
    deskripsi: data.deskripsi ?? '',
    tingkat_layanan_id: data.tingkat_layanan_id ?? '',
    sebab_kerusakan_id: data.sebab_kerusakan_id ?? '',
});

/** Label dan warna status. Selalu dengan teks, bukan warna saja. */
export const STATUS: Record<
    string,
    {
        label: string;
        variant: 'default' | 'secondary' | 'outline' | 'destructive';
    }
> = {
    draft: { label: 'Draf', variant: 'outline' },
    diajukan: { label: 'Diajukan', variant: 'default' },
    diterima: { label: 'Diterima', variant: 'secondary' },
    ditolak: { label: 'Ditolak', variant: 'destructive' },
    work_order_dibuat: { label: 'Work order dibuat', variant: 'secondary' },
};

export function StatusBadge({ status }: { status: string }) {
    const display = STATUS[status] ?? {
        label: status,
        variant: 'outline' as const,
    };

    return <Badge variant={display.variant}>{display.label}</Badge>;
}

/**
 * Hak akses permintaan pemeliharaan. Menyusun, mengajukan, dan meninjau adalah permission
 * terpisah; layar mengikuti pemisahan itu apa adanya.
 */
export const permissionCheck = (permissions: string[]) => (action: string) =>
    permissions.includes(`management-aset.permintaan-pemeliharaan.${action}`);

export const RESOURCE = 'permintaan-pemeliharaan';
const path = (...segments: string[]) =>
    ['/management-aset', RESOURCE, ...segments].join('/');
export const openList = () => router.visit(path());
export const openRequest = (id: string) => router.visit(path(id));
export const openRequestEdit = (id: string) => router.visit(path(id, 'ubah'));
export const openNewRequest = () => router.visit(path('baru'));
export const workOrderPath = (id: string) =>
    `/management-aset/pemeliharaan-aset/${id}`;

/** `kode · nama`; tanpa nama cukup kodenya. */
export const codeName = (kode?: string | null, nama?: string | null) =>
    [kode, nama].filter(Boolean).join(' · ');

const NO_OPTIONS: Option[] = [];

/**
 * Register aset untuk pemilih. `/aset` memulangkan seluruh register tanpa pencarian di server,
 * jadi penyaringan terjadi di kotak pencarian pemilih. Dimuat hanya saat diperlukan.
 */
export function useAssets(enabled: boolean) {
    const [loaded, setLoaded] = useState<{
        options: Option[];
        error: string;
    } | null>(null);

    useEffect(() => {
        if (!enabled || loaded) {
            return;
        }

        let cancelled = false;
        api<{ data: Option[] }>('/aset')
            .then((result) => {
                if (!cancelled) {
                    setLoaded({ options: result.data, error: '' });
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setLoaded({
                        options: [],
                        error: 'Daftar aset tidak dapat dimuat. Minta akses lihat register aset, atau isi lokasinya saja.',
                    });
                }
            });

        return () => {
            cancelled = true;
        };
    }, [enabled, loaded]);

    return {
        options: loaded?.options ?? NO_OPTIONS,
        error: loaded?.error ?? '',
    };
}

/**
 * Pilihan yang bergantung pada satu induk — jenis pekerjaan untuk aset, varian untuk jenis
 * pekerjaan. Hasil dicatat bersama induknya, jadi ganti induk langsung memberi pilihan kosong
 * pada render yang sama tanpa menyisakan pilihan milik induk sebelumnya.
 */
export function useDependentOptions(
    parentId: string,
    buildPath: (parentId: string) => string,
    failure: string,
) {
    const [loaded, setLoaded] = useState<{
        parentId: string;
        options: Option[];
        error: string;
    } | null>(null);
    const current =
        parentId && loaded?.parentId === parentId ? loaded : undefined;

    useEffect(() => {
        if (!parentId) {
            return;
        }

        let cancelled = false;
        api<{ data: Option[] }>(buildPath(parentId))
            .then((result) => {
                if (!cancelled) {
                    setLoaded({ parentId, options: result.data, error: '' });
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setLoaded({ parentId, options: [], error: failure });
                }
            });

        return () => {
            cancelled = true;
        };
        // `buildPath` dan `failure` ditulis tetap oleh pemanggil; hanya induk yang memicu muat ulang.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [parentId]);

    return {
        options: current?.options ?? NO_OPTIONS,
        error: current?.error ?? '',
    };
}
