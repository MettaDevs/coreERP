import { useEffect, useState } from 'react';
import { api } from '../api';

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
export function useMasterOptions(resource: string | null | undefined) {
    const [options, setOptions] = useState<MasterOption[]>([]);
    const [error, setError] = useState('');

    useEffect(() => {
        if (!resource) {
            setOptions([]);
            setError('');
            return;
        }
        let cancelled = false;
        api<{ data: MasterOption[] }>(`/${resource}?per_page=100&aktif=true`)
            .then((result) => {
                if (cancelled) return;
                setOptions(result.data);
                setError('');
            })
            .catch(() => {
                if (cancelled) return;
                setOptions([]);
                setError(
                    `Pilihan belum dapat dimuat. Anda memerlukan akses lihat pada ${resource}.`,
                );
            });
        return () => {
            cancelled = true;
        };
    }, [resource]);

    return { options, error };
}
