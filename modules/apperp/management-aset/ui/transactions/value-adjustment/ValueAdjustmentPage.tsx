import type { Context } from './valueAdjustment';
import ValueAdjustmentDetailPage from './ValueAdjustmentDetailPage';
import ValueAdjustmentListPage from './ValueAdjustmentListPage';

/**
 * Pemilih halaman penyesuaian nilai aset berdasarkan alamat.
 *
 * ```
 * /management-aset/penyesuaian-nilai-aset               daftar
 * /management-aset/penyesuaian-nilai-aset/baru          draf baru
 * /management-aset/penyesuaian-nilai-aset/<id>          rincian, pratinjau, dan posting
 * /management-aset/penyesuaian-nilai-aset/<id>/ubah     rincian, mode sunting
 * ```
 */
export default function ValueAdjustmentPage({
    context,
    permissions,
    segments,
}: {
    context: Context;
    permissions: string[];
    /** Ruas alamat setelah id menu. */
    segments: string[];
}) {
    const [pertama, kedua] = segments;

    if (!pertama) {
        return <ValueAdjustmentListPage permissions={permissions} />;
    }

    if (pertama === 'baru') {
        return (
            <ValueAdjustmentDetailPage
                context={context}
                permissions={permissions}
                mode="create"
            />
        );
    }

    return (
        <ValueAdjustmentDetailPage
            key={`${pertama}-${kedua ?? ''}`}
            context={context}
            permissions={permissions}
            adjustmentId={pertama}
            mode={kedua === 'ubah' ? 'edit' : 'view'}
        />
    );
}
