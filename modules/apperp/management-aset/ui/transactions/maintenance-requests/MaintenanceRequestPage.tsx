import type { Context } from './maintenanceRequest';
import MaintenanceRequestDetailPage from './MaintenanceRequestDetailPage';
import MaintenanceRequestListPage from './MaintenanceRequestListPage';

/**
 * Pemilih halaman permintaan pemeliharaan berdasarkan alamat.
 *
 * ```
 * /management-aset/permintaan-pemeliharaan               daftar
 * /management-aset/permintaan-pemeliharaan/baru          permintaan baru
 * /management-aset/permintaan-pemeliharaan/<id>          rincian, mode baca
 * /management-aset/permintaan-pemeliharaan/<id>/ubah     rincian, mode sunting
 * ```
 */
export default function MaintenanceRequestPage({
    context,
    permissions,
    segments,
}: {
    context: Context;
    permissions: string[];
    /** Ruas alamat setelah id menu. */
    segments: string[];
}) {
    const [first, second] = segments;

    if (!first) {
        return <MaintenanceRequestListPage permissions={permissions} />;
    }

    if (first === 'baru') {
        return (
            <MaintenanceRequestDetailPage
                context={context}
                permissions={permissions}
                mode="create"
            />
        );
    }

    return (
        <MaintenanceRequestDetailPage
            key={`${first}-${second ?? ''}`}
            context={context}
            permissions={permissions}
            requestId={first}
            mode={second === 'ubah' ? 'edit' : 'view'}
        />
    );
}
