import { useEffect, useState } from 'react';
import { fetchDataset, widgetFailure } from '@/lib/analytics/api';
import type { DatasetDescription } from '@/lib/analytics/types';

/**
 * Isi satu data dari katalog yang sama dengan pemilih nilai, kolom, dan saringan selama halaman terbuka. Kolom data
 * pribadi sudah disaring server untuk pengguna ini, jadi isi ini aman disimpan selama sesi halaman.
 */
const loaded = new Map<string, DatasetDescription>();

type Fetched = {
    code: string;
    description: DatasetDescription | null;
    failure: string | null;
};

/**
 * Kolom, nilai, dan kolom tanggal data yang dipilih (`GET api/v1/analytics/datasets/{code}`). Hasil disimpan
 * bersama kodenya, jadi isi data sebelumnya tidak pernah tampil sebagai isi data yang baru dipilih.
 */
export function useDatasetDescription(code: string | null) {
    const [fetched, setFetched] = useState<Fetched | null>(null);
    const known = code === null ? undefined : loaded.get(code);

    useEffect(() => {
        if (code === null || code === '' || loaded.has(code)) {
            return;
        }

        const controller = new AbortController();

        fetchDataset(code, controller.signal)
            .then((description) => {
                loaded.set(code, description);
                setFetched({ code, description, failure: null });
            })
            .catch((caught: unknown) => {
                if (!controller.signal.aborted) {
                    setFetched({
                        code,
                        description: null,
                        failure: widgetFailure(caught).message,
                    });
                }
            });

        return () => controller.abort();
    }, [code]);

    if (code === null || code === '') {
        return { description: null, loading: false, failure: null };
    }

    if (known !== undefined) {
        return { description: known, loading: false, failure: null };
    }

    const current = fetched !== null && fetched.code === code ? fetched : null;

    return {
        description: current?.description ?? null,
        loading: current === null,
        failure: current?.failure ?? null,
    };
}
