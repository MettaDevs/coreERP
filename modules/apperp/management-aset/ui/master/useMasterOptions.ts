import { useCallback, useEffect, useState } from 'react';
import { ApiError, api } from '../api';

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

/** Dipakai bersama saat belum ada pilihan, agar identitasnya tetap dan pemanggil yang
 *  menaruhnya di daftar dependensi tidak ikut berubah tiap render. */
const TANPA_PILIHAN: MasterOption[] = [];

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
) {
    // Hasil selalu dicatat bersama master yang melahirkannya, lalu yang dikembalikan
    // hanyalah hasil yang masih milik master yang diminta sekarang. Dengan begitu ganti
    // master — atau tidak memilih master sama sekali — langsung memberi pilihan kosong
    // pada render yang sama, tanpa effect yang perlu mengosongkannya lebih dulu dan
    // tanpa satu frame pun yang memperlihatkan pilihan milik master sebelumnya.
    const lookupKey = `${resource ?? ''}?${query}`;
    const [attempt, setAttempt] = useState(0);
    const [muatan, setMuatan] = useState<{
        resource: string;
        options: MasterOption[];
        error: string;
    } | null>(null);
    const reload = useCallback(() => {
        setMuatan(null);
        setAttempt((value) => value + 1);
    }, []);
    const termuat =
        resource && muatan?.resource === lookupKey ? muatan : undefined;

    useEffect(() => {
        if (!resource) {
            return;
        }

        let cancelled = false;
        api<{ data: MasterOption[] }>(
            `/${resource}?per_page=100&aktif=true&${query}`,
            { headers: { Accept: 'application/json' } },
        )
            .then((result) => {
                if (cancelled) {
                    return;
                }

                setMuatan({
                    resource: lookupKey,
                    options: result.data,
                    error: '',
                });
            })
            .catch((caught: unknown) => {
                if (cancelled) {
                    return;
                }

                setMuatan({
                    resource: lookupKey,
                    options: [],
                    error:
                        caught instanceof ApiError && caught.status === 403
                            ? 'Anda belum memiliki akses untuk melihat pilihan ini.'
                            : caught instanceof ApiError &&
                                caught.status === 401
                              ? 'Sesi Anda sudah berakhir. Silakan masuk kembali.'
                              : 'Pilihan gagal dimuat. Coba muat ulang.',
                });
            });

        return () => {
            cancelled = true;
        };
    }, [resource, query, lookupKey, attempt]);

    return {
        options: termuat?.options ?? TANPA_PILIHAN,
        error: termuat?.error ?? '',
        loading: Boolean(resource && !termuat),
        reload,
    };
}
