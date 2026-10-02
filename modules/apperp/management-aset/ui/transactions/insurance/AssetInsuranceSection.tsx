import { useEffect, useState } from 'react';
import { Badge } from '@apperp/ui/badge';
import { day, money } from '../../_shared/format';
import { api } from '../../api';
import type { Coverage, InsuranceStatus } from './insurance';
import { STATUS_LABELS } from './insurance';

type AssetInsurance = {
    tanggal: string;
    total_nilai_tertanggung: string;
    nilai_perolehan: string;
    nilai_buku: string | null;
    status: InsuranceStatus;
    pertanggungan: Coverage[];
};

/**
 * Bagian asuransi pada detail aset: nilai yang ditanggung hari ini dibanding nilai perolehan, dan
 * riwayat pertanggungan dari seluruh polis. Hanya baca; pertanggungan diubah dari layar polis.
 */
export default function AssetInsuranceSection({ asetId }: { asetId: string }) {
    const [loaded, setLoaded] = useState<{
        asetId: string;
        data: AssetInsurance | null;
    } | null>(null);

    useEffect(() => {
        let cancelled = false;
        api<{ data: AssetInsurance }>(`/asuransi-aset?aset_id=${asetId}`)
            .then(
                (result) =>
                    !cancelled && setLoaded({ asetId, data: result.data }),
            )
            .catch(() => !cancelled && setLoaded({ asetId, data: null }));

        return () => {
            cancelled = true;
        };
    }, [asetId]);

    const data = loaded?.asetId === asetId ? loaded.data : null;

    if (!data) {
        return (
            <p className="text-muted-foreground text-sm">
                {loaded ? 'Asuransi aset belum dapat dimuat.' : 'Memuat…'}
            </p>
        );
    }

    return (
        <div className="space-y-3">
            <dl className="grid gap-3 sm:grid-cols-3">
                <div>
                    <dt className="text-muted-foreground text-xs">
                        Ditanggung hari ini
                    </dt>
                    <dd className="flex items-center gap-2 text-sm">
                        {money(data.total_nilai_tertanggung)}
                        <Badge
                            variant={
                                data.status === 'cukup'
                                    ? 'secondary'
                                    : 'destructive'
                            }
                        >
                            {STATUS_LABELS[data.status]}
                        </Badge>
                    </dd>
                </div>
                <div>
                    <dt className="text-muted-foreground text-xs">
                        Nilai perolehan
                    </dt>
                    <dd className="text-sm">{money(data.nilai_perolehan)}</dd>
                </div>
                <div>
                    <dt className="text-muted-foreground text-xs">
                        Nilai buku
                    </dt>
                    <dd className="text-sm">{money(data.nilai_buku)}</dd>
                </div>
            </dl>
            {data.pertanggungan.length === 0 ? (
                <p className="text-muted-foreground text-sm">
                    Aset ini belum pernah ditanggung polis asuransi.
                </p>
            ) : (
                <div className="space-y-2">
                    {data.pertanggungan.map((coverage) => (
                        <div
                            className="rounded-md border px-3 py-2"
                            key={coverage.id}
                        >
                            <p className="flex items-center gap-2 text-sm font-medium">
                                {coverage.polis_nama} · {coverage.nomor_polis}
                                {coverage.berjalan && (
                                    <Badge variant="outline">Berjalan</Badge>
                                )}
                            </p>
                            <p className="text-muted-foreground text-xs">
                                {money(coverage.nilai_pertanggungan)} ·{' '}
                                {day(coverage.berlaku_mulai)} –{' '}
                                {coverage.berlaku_sampai
                                    ? day(coverage.berlaku_sampai)
                                    : 'ikut polis'}
                            </p>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}
