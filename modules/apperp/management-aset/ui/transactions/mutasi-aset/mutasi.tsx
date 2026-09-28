import { router } from '@inertiajs/react';
import { Badge } from '@apperp/ui/badge';

/**
 * Bentuk data, kosakata status, dan navigasi mutasi aset — dipakai bersama oleh daftar
 * dan halaman rincian, sehingga keduanya menyebut status yang sama dengan kata yang sama.
 */

export type Context = {
    legal_entity_id: string | null;
    org_unit_id: string | null;
    user_id?: string | null;
};

export type MutasiLine = {
    id?: string;
    line_number?: number;
    aset_id: string;
    kondisi_aset_id: string;
    catatan: string;
    /** Diisi server; kolom bacaan, bukan isian. */
    aset_kode?: string;
    aset_nama?: string;
    aset_serial_number?: string | null;
    kondisi_aset_nama?: string | null;
    /**
     * Keadaan asal yang berlaku: nilai beku bila dokumen sudah selesai, keadaan aset
     * sekarang bila masih draf. Server yang memilih; layar tinggal menampilkannya.
     */
    asal_lokasi_nama?: string | null;
    asal_lokasi_efektif_id?: string | null;
    asal_org_unit_efektif_id?: string | null;
    asal_custodian_efektif_id?: string | null;
    /** Nama, diterjemahkan server dari id unit kerja dan id pengguna milik Core. */
    asal_org_unit_nama?: string | null;
    asal_custodian_nama?: string | null;
};

export type Mutasi = {
    id: string;
    kode: string;
    status: string;
    version: number;
    tanggal: string;
    legal_entity_id: string;
    responsible_org_unit_id: string;
    tujuan_lokasi_id: string | null;
    tujuan_org_unit_id: string;
    tujuan_lokasi_kode?: string | null;
    tujuan_lokasi_nama?: string | null;
    diserahkan_oleh_user_id: string | null;
    diterima_oleh_user_id: string | null;
    alasan: string;
    keterangan: string | null;
    jumlah_baris?: number;
    /** Nama unit kerja dan nama orang; id-nya tetap dipulangkan di sebelahnya. */
    tujuan_org_unit_nama?: string | null;
    responsible_org_unit_nama?: string | null;
    diserahkan_oleh_nama?: string | null;
    diterima_oleh_nama?: string | null;
    details?: MutasiLine[];
};

export type EditableMutasi = Partial<Mutasi> & { details: MutasiLine[] };

/** Label dan warna status. Dua saja: mutasi tidak melewati persetujuan pada versi ini. */
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

export const emptyLine = (): MutasiLine => ({
    aset_id: '',
    kondisi_aset_id: '',
    catatan: '',
});

export const emptyMutasi = (): EditableMutasi => ({
    // Tanggal hari ini sebagai nilai awal: serah terima hampir selalu dicatat pada hari
    // ia terjadi, dan tanggal kosong pada dokumen bertanda tangan adalah cacat yang baru
    // ketahuan setelah dicetak.
    tanggal: new Date().toISOString().slice(0, 10),
    tujuan_lokasi_id: '',
    tujuan_org_unit_id: '',
    diserahkan_oleh_user_id: '',
    diterima_oleh_user_id: '',
    alasan: '',
    keterangan: '',
    details: [emptyLine()],
});

export type Option = { id: string; kode: string; nama: string };

// Select memilih berdasarkan label, jadi id dibolak-balik ke nama di dua tempat ini.
export const labelDari = (option?: Option) =>
    option ? `${option.kode} · ${option.nama}` : null;
export const idDari = (options: Option[], label: string | null) =>
    options.find((option) => `${option.kode} · ${option.nama}` === label)?.id ??
    '';

/**
 * Hak akses mutasi.
 *
 * Menyusun dokumennya dan benar-benar memindahkan asetnya adalah dua wewenang berbeda,
 * dan manifest memisahkannya: yang pertama `management-aset.mutasi-aset.*`, yang kedua
 * `management-aset.aset.mutate`. Layar mengikuti pemisahan itu apa adanya — tombol
 * "Selesaikan" tidak muncul untuk juru tulis yang hanya boleh menyiapkan berkasnya.
 */
export const izin = (permissions: string[]) => (action: string) =>
    permissions.includes(`management-aset.mutasi-aset.${action}`);
export const bolehMemindahkan = (permissions: string[]) =>
    permissions.includes('management-aset.aset.mutate');

export const RESOURCE = 'mutasi-aset';
const alamat = (...ruas: string[]) =>
    ['/management-aset', RESOURCE, ...ruas].join('/');
export const bukaDaftar = () => router.visit(alamat());
export const bukaMutasi = (id: string) => router.visit(alamat(id));
export const bukaMutasiUbah = (id: string) => router.visit(alamat(id, 'ubah'));
export const bukaMutasiBaru = () => router.visit(alamat('baru'));

/** Tanggal `Y-m-d` menjadi `dd/mm/yyyy`; kosong tetap kosong. */
export const tanggalTampil = (value?: string | null) => {
    if (!value) {
        return '—';
    }

    const [tahun, bulan, hari] = value.slice(0, 10).split('-');

    return hari && bulan && tahun ? `${hari}/${bulan}/${tahun}` : value;
};
