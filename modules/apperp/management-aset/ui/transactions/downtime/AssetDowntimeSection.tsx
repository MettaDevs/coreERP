import { useEffect, useState } from 'react';
import { useDateTimeFormat } from '@/hooks/use-date-time';
import { Badge } from '@apperp/ui/badge';
import { api } from '../../api';
import type { Downtime } from './downtime';

/** Paling banyak sekian catatan terakhir yang ditampilkan di detail aset. */
const SHOWN = 10;

/**
 * Bagian downtime pada detail aset: catatan henti terakhir, termasuk yang masih berjalan. Hanya baca;
 * pencatatan dan koreksinya di layar Downtime aset.
 */
export default function AssetDowntimeSection({ asetId }: { asetId: string }) {
    const formatDateTime = useDateTimeFormat();
    const [loaded, setLoaded] = useState<{
        asetId: string;
        rows: Downtime[] | null;
    } | null>(null);

    useEffect(() => {
        let cancelled = false;
        api<{ data: Downtime[] }>(`/downtime-aset?aset_id=${asetId}`)
            .then(
                (result) =>
                    !cancelled &&
                    setLoaded({ asetId, rows: result.data.slice(0, SHOWN) }),
            )
            .catch(() => !cancelled && setLoaded({ asetId, rows: null }));

        return () => {
            cancelled = true;
        };
    }, [asetId]);

    const rows = loaded?.asetId === asetId ? loaded.rows : undefined;

    if (rows === undefined) {
        return <p className="text-muted-foreground text-sm">Memuat…</p>;
    }

    if (rows === null || rows.length === 0) {
        return (
            <p className="text-muted-foreground text-sm">
                {rows === null
                    ? 'Downtime aset belum dapat dimuat.'
                    : 'Aset ini belum pernah tercatat berhenti.'}
            </p>
        );
    }

    return (
        <div className="space-y-2">
            {rows.map((row) => (
                <div className="rounded-md border px-3 py-2" key={row.id}>
                    <p className="flex items-center gap-2 text-sm font-medium">
                        {formatDateTime(row.mulai)} –{' '}
                        {row.terbuka ? (
                            <Badge variant="destructive">Masih berhenti</Badge>
                        ) : (
                            formatDateTime(row.selesai)
                        )}
                    </p>
                    <p className="text-muted-foreground text-xs">
                        {[
                            `${row.durasi_jam.toLocaleString('id-ID')} jam`,
                            row.alasan_downtime_nama,
                            row.pemeliharaan_aset_kode,
                        ]
                            .filter(Boolean)
                            .join(' · ')}
                    </p>
                </div>
            ))}
        </div>
    );
}
