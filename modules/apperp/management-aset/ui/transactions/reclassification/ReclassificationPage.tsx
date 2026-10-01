import type { Context } from './reclassification';
import ReclassificationDetailPage from './ReclassificationDetailPage';
import ReclassificationListPage from './ReclassificationListPage';

/**
 * Pemilih halaman reklasifikasi aset berdasarkan alamat.
 *
 * ```
 * /management-aset/reklasifikasi-aset               daftar
 * /management-aset/reklasifikasi-aset/baru          draf baru
 * /management-aset/reklasifikasi-aset/<id>          rincian, pratinjau, dan posting
 * /management-aset/reklasifikasi-aset/<id>/ubah     rincian, mode sunting
 * ```
 */
export default function ReclassificationPage({
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
        return <ReclassificationListPage permissions={permissions} />;
    }

    if (pertama === 'baru') {
        return (
            <ReclassificationDetailPage
                context={context}
                permissions={permissions}
                mode="create"
            />
        );
    }

    return (
        <ReclassificationDetailPage
            key={`${pertama}-${kedua ?? ''}`}
            context={context}
            permissions={permissions}
            reclassificationId={pertama}
            mode={kedua === 'ubah' ? 'edit' : 'view'}
        />
    );
}
