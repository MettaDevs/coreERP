import { useEffect, useState } from 'react';
import { Badge } from '@apperp/ui/badge';
import { day } from '../../_shared/format';
import { api } from '../../api';
import type { Contract, Warranty } from './warranty';

/**
 * Bagian garansi dan kontrak servis pada detail aset. Hanya baca; garansi dan kontrak diubah dari layar
 * Garansi dan kontrak servis. Tiap daftar dimuat hanya bila pengguna boleh membacanya.
 */
export default function AssetWarrantySection({
    asetId,
    canReadWarranties,
    canReadContracts,
}: {
    asetId: string;
    canReadWarranties: boolean;
    canReadContracts: boolean;
}) {
    const [loaded, setLoaded] = useState<{
        asetId: string;
        warranties: Warranty[];
        contracts: Contract[];
    } | null>(null);

    useEffect(() => {
        let cancelled = false;
        Promise.all([
            canReadWarranties
                ? api<{ data: Warranty[] }>(`/garansi-aset?aset_id=${asetId}`)
                      .then((result) => result.data)
                      .catch(() => [])
                : Promise.resolve([]),
            canReadContracts
                ? api<{ data: Contract[] }>(`/kontrak-servis?aset_id=${asetId}`)
                      .then((result) => result.data)
                      .catch(() => [])
                : Promise.resolve([]),
        ]).then(
            ([warranties, contracts]) =>
                !cancelled && setLoaded({ asetId, warranties, contracts }),
        );

        return () => {
            cancelled = true;
        };
    }, [asetId, canReadWarranties, canReadContracts]);

    const data = loaded?.asetId === asetId ? loaded : null;

    if (!data) {
        return <p className="text-muted-foreground text-sm">Memuat…</p>;
    }

    if (data.warranties.length === 0 && data.contracts.length === 0) {
        return (
            <p className="text-muted-foreground text-sm">
                Aset ini tidak bergaransi dan tidak masuk kontrak servis.
            </p>
        );
    }

    return (
        <div className="space-y-2">
            {data.warranties.map((warranty) => (
                <div className="rounded-md border px-3 py-2" key={warranty.id}>
                    <p className="flex items-center gap-2 text-sm font-medium">
                        Garansi {warranty.jenis_garansi_label.toLowerCase()}
                        {warranty.vendor ? ` · ${warranty.vendor.name}` : ''}
                        {warranty.berlaku && (
                            <Badge variant="outline">Berlaku</Badge>
                        )}
                    </p>
                    <p className="text-muted-foreground text-xs">
                        {day(warranty.berlaku_mulai)} –{' '}
                        {day(warranty.berlaku_sampai)}
                        {warranty.nomor_referensi
                            ? ` · ${warranty.nomor_referensi}`
                            : ''}
                        {warranty.catatan ? ` · ${warranty.catatan}` : ''}
                    </p>
                </div>
            ))}
            {data.contracts.map((contract) => (
                <div className="rounded-md border px-3 py-2" key={contract.id}>
                    <p className="flex items-center gap-2 text-sm font-medium">
                        Kontrak servis {contract.nomor_kontrak}
                        {contract.vendor ? ` · ${contract.vendor.name}` : ''}
                        {contract.berlaku && (
                            <Badge variant="outline">Berlaku</Badge>
                        )}
                    </p>
                    <p className="text-muted-foreground text-xs">
                        {day(contract.berlaku_mulai)} –{' '}
                        {day(contract.berlaku_sampai)}
                        {contract.cakupan ? ` · ${contract.cakupan}` : ''}
                    </p>
                </div>
            ))}
        </div>
    );
}
