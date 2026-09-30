import type { Context } from './monitoring';
import MonitoringDetailPage from './MonitoringDetailPage';
import MonitoringListPage from './MonitoringListPage';

/**
 * Pemilih halaman monitoring aset berdasarkan alamat.
 *
 * ```
 * /management-aset/monitoring-aset               daftar
 * /management-aset/monitoring-aset/baru          monitoring baru
 * /management-aset/monitoring-aset/<id>          rincian, mode baca
 * /management-aset/monitoring-aset/<id>/ubah     rincian, mode sunting
 * ```
 *
 * Bentuknya sama dengan mutasi aset: dua daftar dokumen yang berperilaku sama tidak boleh punya
 * dua cara berbeda untuk membuka recordnya.
 */
export default function MonitoringPage({
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
        return <MonitoringListPage permissions={permissions} />;
    }

    if (pertama === 'baru') {
        return (
            <MonitoringDetailPage
                context={context}
                permissions={permissions}
                mode="create"
            />
        );
    }

    return (
        <MonitoringDetailPage
            key={`${pertama}-${kedua ?? ''}`}
            context={context}
            permissions={permissions}
            monitoringId={pertama}
            mode={kedua === 'ubah' ? 'edit' : 'view'}
        />
    );
}
