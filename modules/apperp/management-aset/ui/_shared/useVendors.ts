import { useRecordOptions } from '../use-record-list';

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
export function useVendors(path: string, legalEntityId: string | null) {
    return useRecordOptions<Vendor>(
        legalEntityId ? `${path}?legal_entity_id=${legalEntityId}` : null,
        (vendor) => `${vendor.number} — ${vendor.name}`,
    );
}
