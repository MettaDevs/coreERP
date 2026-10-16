import { useRecordOptions } from '../use-record-list';

/**
 * Bentuk minimum yang dipakai setiap dropdown. Field lain milik master tetap dibawa
 * apa adanya, karena sebagian form perlu menurunkan sesuatu dari record yang dipilih —
 * misalnya frekuensi periode sebuah profil penyusutan — tanpa memuat ulang recordnya.
 */
export type MasterOption = {
    id: string;
    kode: string;
    nama: string;
    display_label?: string;
} & Record<string, unknown>;

/** Satu bentuk label untuk seluruh pilihan master, agar tidak ada varian pemisah. */
export const optionLabel = (option: MasterOption) =>
    option.display_label ?? `${option.kode} — ${option.nama}`;

/**
 * Memuat pilihan satu master untuk dipakai sebagai isi dropdown foreign key.
 *
 * Hanya data aktif yang diambil: kolom penunjuk selalu berarti "pilih yang masih boleh
 * dipakai", sedangkan data terarsip tetap tersimpan pada record lama.
 *
 * Kegagalan tidak dilempar. Pengguna dapat memiliki akses tulis pada satu master tanpa
 * akses lihat pada master yang ditunjuknya, dan itu tidak boleh membuat seluruh form
 * gagal dibuka; yang muncul hanyalah pilihan kosong beserta alasannya.
 */
export function useMasterOptions(
    resource: string | null | undefined,
    query = '',
    selectedId: string | string[] = '',
) {
    return useRecordOptions<MasterOption>(
        resource ? `/${resource}?aktif=true&${query}` : null,
        optionLabel,
        selectedId,
    );
}
