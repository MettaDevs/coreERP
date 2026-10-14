import { useEffect, useState } from 'react';
import { fetchDataset, widgetFailure } from '@/lib/analytics/api';
import type { DatasetDescription } from '@/lib/analytics/types';
import { useAnalyticsScope } from './use-analytics-scope';

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
    const scope = useAnalyticsScope();
    const key = code === null ? null : `${scope}:${code}`;
    const [fetched, setFetched] = useState<Fetched | null>(null);
    const known = key === null ? undefined : loaded.get(key);

    useEffect(() => {
        if (code === null || code === '' || key === null || loaded.has(key)) {
            return;
        }

        const controller = new AbortController();

        fetchDataset(code, controller.signal)
            .then((description) => {
                loaded.set(key, description);
                setFetched({ code: key, description, failure: null });
            })
            .catch((caught: unknown) => {
                if (!controller.signal.aborted) {
                    setFetched({
                        code: key,
                        description: null,
                        failure: widgetFailure(caught).message,
                    });
                }
            });

        return () => controller.abort();
    }, [code, key]);

    if (code === null || code === '') {
        return { description: null, loading: false, failure: null };
    }

    if (known !== undefined) {
        return { description: known, loading: false, failure: null };
    }

    const current = fetched !== null && fetched.code === key ? fetched : null;

    return {
        description: current?.description ?? null,
        loading: current === null,
        failure: current?.failure ?? null,
    };
}
