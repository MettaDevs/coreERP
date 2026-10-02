import { useEffect, useState } from 'react';
import { api } from '../api';

/** Vendor milik Core (K-06) seperti dikirim endpoint pemilih vendor module. */
export type Vendor = {
    id: string;
    number: string;
    name: string;
    status: string;
    legal_entity_id: string;
};

/**
 * Vendor aktif satu entitas legal untuk pemilih penanggung, penjamin, atau vendor servis. Tidak
 * memuat apa pun selama entitas legalnya kosong, misalnya saat form belum dibuka.
 */
export function useVendors(
    path: string,
    legalEntityId: string | null,
): Vendor[] {
    const [loaded, setLoaded] = useState<{
        key: string;
        vendors: Vendor[];
    } | null>(null);
    const key = legalEntityId ? `${path}?legal_entity_id=${legalEntityId}` : '';

    useEffect(() => {
        if (!key) {
            return;
        }

        let cancelled = false;
        api<{ data: Vendor[] }>(key)
            .then(
                (result) =>
                    !cancelled && setLoaded({ key, vendors: result.data }),
            )
            .catch(() => !cancelled && setLoaded({ key, vendors: [] }));

        return () => {
            cancelled = true;
        };
    }, [key]);

    return loaded && loaded.key === key ? loaded.vendors : [];
}
