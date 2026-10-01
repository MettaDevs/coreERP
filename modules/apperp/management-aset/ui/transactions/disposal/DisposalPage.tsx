import type { DisposalResource } from './disposal';
import DisposalDetailPage from './DisposalDetailPage';
import DisposalListPage from './DisposalListPage';

/**
 * Pemilih halaman penjualan dan pemusnahan aset berdasarkan alamat.
 *
 * ```
 * /management-aset/penjualan-aset           daftar
 * /management-aset/penjualan-aset/baru      draf baru
 * /management-aset/penjualan-aset/<id>      rincian, pratinjau, dan posting
 * ```
 *
 * Bentuknya sama dengan mutasi dan monitoring aset.
 */
export default function DisposalPage({
    resource,
    permissions,
    segments,
}: {
    resource: DisposalResource;
    permissions: string[];
    /** Ruas alamat setelah id menu. */
    segments: string[];
}) {
    const [pertama] = segments;

    if (!pertama) {
        return (
            <DisposalListPage resource={resource} permissions={permissions} />
        );
    }

    return (
        <DisposalDetailPage
            key={pertama}
            resource={resource}
            permissions={permissions}
            disposalId={pertama === 'baru' ? undefined : pertama}
        />
    );
}
