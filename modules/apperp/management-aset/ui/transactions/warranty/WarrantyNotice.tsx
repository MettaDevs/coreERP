import { useEffect, useState } from 'react';
import { day } from '../../_shared/format';
import { api } from '../../api';
import type { ActiveWarranty } from './warranty';

/**
 * Pemberitahuan garansi dan kontrak servis yang berlaku untuk aset-aset work order pada tanggal
 * mulainya; padanan notifikasi warranty agreement di F&O. Hanya informasi: work order tetap boleh
 * dibuat dan dikerjakan sendiri.
 */
export default function WarrantyNotice({
    asetIds,
    date,
    labelOf,
}: {
    asetIds: string[];
    /** Tanggal `Y-m-d` menurut zona pengguna; kosong berarti hari ini. */
    date: string;
    labelOf: (asetId: string) => string;
}) {
    const key = [...new Set(asetIds.filter(Boolean))].sort().join(',');
    const [loaded, setLoaded] = useState<{
        key: string;
        items: ActiveWarranty[];
    } | null>(null);
    const query = key
        ? `${key
              .split(',')
              .map((id) => `aset_id[]=${id}`)
              .join('&')}${date ? `&tanggal=${date}` : ''}`
        : '';

    useEffect(() => {
        if (!query) {
            return;
        }

        let cancelled = false;
        api<{ data: ActiveWarranty[] }>(
            `/pemeliharaan-aset/referensi/garansi?${query}`,
        )
            .then(
                (result) =>
                    !cancelled && setLoaded({ key: query, items: result.data }),
            )
            .catch(() => !cancelled && setLoaded({ key: query, items: [] }));

        return () => {
            cancelled = true;
        };
    }, [query]);

    const items = loaded && loaded.key === query ? loaded.items : [];

    if (items.length === 0) {
        return null;
    }

    return (
        <div
            role="status"
            className="rounded-md border border-sky-300 bg-sky-50 px-3 py-2 text-sm text-sky-900 dark:border-sky-800 dark:bg-sky-950 dark:text-sky-100"
        >
            <p className="font-medium">
                Aset pada work order ini masih bergaransi atau dalam kontrak
                servis. Pertimbangkan menghubungi vendornya lebih dulu.
            </p>
            <ul className="mt-1 list-disc ps-5">
                {items.map((item, index) => (
                    <li key={`${item.aset_id}-${item.jenis}-${index}`}>
                        {labelOf(item.aset_id)}:{' '}
                        {item.jenis === 'garansi'
                            ? `garansi ${item.jenis_garansi === 'sebagian' ? 'sebagian' : 'penuh'}`
                            : 'kontrak servis'}
                        {item.vendor_nama ? ` dari ${item.vendor_nama}` : ''}
                        {item.referensi ? ` (${item.referensi})` : ''}, berlaku
                        sampai {day(item.berlaku_sampai)}
                        {item.cakupan ? ` · ${item.cakupan}` : ''}
                    </li>
                ))}
            </ul>
        </div>
    );
}
