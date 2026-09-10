import { Badge } from '@apperp/ui/badge';
import { router } from '@inertiajs/react';

/**
 * Bentuk data, kosakata status, dan navigasi work order — dipakai bersama oleh daftar
 * dan halaman rincian. Keduanya menyebut status yang sama dengan kata yang sama karena
 * kata itu hanya ditulis di sini.
 */

export type Context = {
    legal_entity_id: string | null;
    org_unit_id: string | null;
};
export type Option = {
    id: string;
    kode: string;
    nama: string;
    minta_keterangan?: boolean;
};

export type JobLine = {
    id?: string;
    asset_id: string;
    maintenance_job_type_id: string;
    variant_id: string;
    trade_id: string;
    ditugaskan_ke_user_id: string;
    estimasi_jam: string;
    dijadwalkan_mulai: string;
    dijadwalkan_selesai: string;
    line_number?: number;
    aktual_jam?: number | null;
    sebab_kerusakan_id?: string | null;
    tindakan_perbaikan_id?: string | null;
    sebab_kerusakan_keterangan?: string | null;
    tindakan_perbaikan_keterangan?: string | null;
    hasil?: string | null;
    catatan: string;
    asset_kode?: string;
    job_type_nama?: string;
    trade_nama?: string;
    variant_nama?: string;
    sebab_kerusakan_nama?: string;
    tindakan_perbaikan_nama?: string;
};

export type WorkOrder = {
    id: string;
    kode: string;
    status: string;
    version: number;
    keterangan: string | null;
    tipe_work_order_id: string;
    tingkat_layanan_id: string | null;
    tipe_work_order_nama?: string;
    tingkat_layanan_nama?: string;
    dijadwalkan_mulai: string | null;
    dijadwalkan_selesai: string | null;
    diharapkan_mulai: string | null;
    diharapkan_selesai: string | null;
    aktual_mulai: string | null;
    aktual_selesai: string | null;
    jumlah_baris?: number;
    details?: JobLine[];
};

export type EditableWorkOrder = Partial<WorkOrder> & { details: JobLine[] };

export type ChecklistRow = {
    id: string;
    line_number: string;
    nama: string;
    tipe: string;
    satuan: string | null;
    min_value: string | null;
    max_value: string | null;
    wajib: boolean;
    instruksi: string | null;
    pilihan: { value: string; result_code: string }[];
    nilai: string | null;
    tidak_berlaku: boolean;
    catatan_teknisi: string | null;
};

/** Label dan warna status. */
export const STATUS: Record<
    string,
    {
        label: string;
        variant: 'default' | 'secondary' | 'outline' | 'destructive';
    }
> = {
    draft: { label: 'Draf', variant: 'outline' },
    dijadwalkan: { label: 'Dijadwalkan', variant: 'secondary' },
    dikerjakan: { label: 'Dikerjakan', variant: 'default' },
    selesai: { label: 'Selesai', variant: 'secondary' },
    ditutup: { label: 'Ditutup', variant: 'outline' },
    dibatalkan: { label: 'Dibatalkan', variant: 'destructive' },
};

/** Tombol transisi yang ditawarkan pada tiap status, beserta hak yang menjaganya. */
export const TRANSISI: Record<
    string,
    { ke: string; label: string; izin: string }[]
> = {
    draft: [
        { ke: 'dijadwalkan', label: 'Jadwalkan', izin: 'schedule' },
        { ke: 'dibatalkan', label: 'Batalkan', izin: 'schedule' },
    ],
    dijadwalkan: [
        { ke: 'dikerjakan', label: 'Mulai kerjakan', izin: 'execute' },
        { ke: 'dibatalkan', label: 'Batalkan', izin: 'schedule' },
    ],
    dikerjakan: [
        { ke: 'selesai', label: 'Selesaikan', izin: 'execute' },
        { ke: 'dibatalkan', label: 'Batalkan', izin: 'schedule' },
    ],
    selesai: [{ ke: 'ditutup', label: 'Tutup', izin: 'close' }],
    ditutup: [],
    dibatalkan: [],
};

export function StatusBadge({ status }: { status: string }) {
    const tampilan = STATUS[status] ?? {
        label: status,
        variant: 'outline' as const,
    };

    return <Badge variant={tampilan.variant}>{tampilan.label}</Badge>;
}

export const emptyJob = (): JobLine => ({
    asset_id: '',
    maintenance_job_type_id: '',
    variant_id: '',
    trade_id: '',
    ditugaskan_ke_user_id: '',
    estimasi_jam: '',
    dijadwalkan_mulai: '',
    dijadwalkan_selesai: '',
    catatan: '',
});

export const emptyWorkOrder = (): EditableWorkOrder => ({
    keterangan: '',
    tipe_work_order_id: '',
    tingkat_layanan_id: '',
    diharapkan_mulai: '',
    diharapkan_selesai: '',
    dijadwalkan_mulai: '',
    dijadwalkan_selesai: '',
    details: [emptyJob()],
});

// Select memilih berdasarkan label, jadi id dibolak-balik ke nama di dua tempat ini.
export const labelDari = (option?: Option) =>
    option ? `${option.kode} · ${option.nama}` : null;
export const idDari = (options: Option[], label: string | null) =>
    options.find((option) => `${option.kode} · ${option.nama}` === label)?.id ??
    '';

export const izin = (permissions: string[]) => (action: string) =>
    permissions.includes(`management-aset.pemeliharaan-aset.${action}`);

/**
 * Rincian punya alamatnya sendiri, sama seperti di daftar mana pun yang membuka record
 * pada halaman terpisah. Karena itu tombol kembali peramban, muat ulang, dan tautan yang
 * disalin ke rekan kerja semuanya mendarat di work order yang sama, bukan di daftar.
 *
 * Alamatnya kini rute shell — `/management-aset/pemeliharaan-aset/<id>/ubah` — bukan lagi
 * ruas sesudah tanda pagar. Perpindahannya kunjungan Inertia, bukan `window.location`:
 * yang terakhir memuat ulang seluruh dokumen, dan justru itu yang dibuang fase ini.
 */
export const RESOURCE = 'pemeliharaan-aset';
const alamat = (...ruas: string[]) =>
    ['/management-aset', RESOURCE, ...ruas].join('/');
export const bukaDaftar = () => router.visit(alamat());
export const bukaWorkOrder = (id: string) => router.visit(alamat(id));
export const bukaWorkOrderUbah = (id: string) =>
    router.visit(alamat(id, 'ubah'));
export const bukaWorkOrderBaru = () => router.visit(alamat('baru'));
export const bukaChecklistJob = (workOrderId: string, jobId: string) =>
    router.visit(alamat(workOrderId, 'checklist', jobId));
